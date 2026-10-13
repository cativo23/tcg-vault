<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Jobs\ImportSetJob;
use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Exceptions\CatalogUnavailableException;
use App\Modules\Catalog\Exceptions\InvalidTcgdexIdException;
use App\Modules\Catalog\Exceptions\SetNotFoundException;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CatalogSyncService;
use App\Modules\Catalog\Services\ImportBudget;
use App\Modules\Catalog\Services\ImportBudgetResult;
use App\Modules\Collection\Models\Collection;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * The owner's sets: every set they hold at least one card from, with how
 * many distinct cards they own. Reaches the owner's collections only
 * through Collection's TenantScope, so a private collection works and
 * another user's sets never appear.
 */
#[Layout('layouts.app')]
final class Sets extends Component
{
    public const ADD_SET_LIMIT_PER_MINUTE = 10;

    public bool $pickerOpen = false;

    public string $pickerSearch = '';

    #[Locked]
    public ?string $pickerError = null;

    private const PICKER_SEARCH_MAX_LENGTH = 100;

    public function updatedPickerSearch(): void
    {
        $this->pickerSearch = mb_substr($this->pickerSearch, 0, self::PICKER_SEARCH_MAX_LENGTH);
    }

    public function openPicker(): void
    {
        $this->pickerOpen = true;
        $this->pickerSearch = '';
        $this->pickerError = null;
    }

    public function closePicker(): void
    {
        if (! $this->pickerOpen) {
            return;
        }

        $this->pickerOpen = false;
        $this->pickerSearch = '';
        $this->pickerError = null;
        $this->dispatch('set-picker-closed');
    }

    /**
     * The picker's rows, newest listing entries first. The list is read
     * here, on every render of an open picker, instead of being held in
     * component state: listSets() is cached, and a 200-row payload should
     * not travel with every request.
     *
     * @return array{sets: list<SetSummaryData>, failed: bool, backoff: bool, total: int}
     */
    private function pickerData(): array
    {
        try {
            $all = array_reverse(app(CardCatalogProvider::class)->listSets());
        } catch (CatalogUnavailableException $e) {
            // The failure window answers every render with the same error;
            // only the attempt that started it is worth a report.
            $backoff = $e->isBackoff;

            if (! $backoff) {
                report($e);
            }

            return ['sets' => [], 'failed' => true, 'backoff' => $backoff, 'total' => 0];
        }

        $needle = mb_strtolower(trim($this->pickerSearch));

        $sets = $needle === '' ? $all : array_values(array_filter(
            $all,
            static fn (SetSummaryData $set): bool => str_contains(mb_strtolower($set->name), $needle)
                || str_contains(mb_strtolower($set->tcgdexId), $needle),
        ));

        return ['sets' => array_values($sets), 'failed' => false, 'backoff' => false, 'total' => count($all)];
    }

    /**
     * Picks a set: makes sure the local row exists, queues the card
     * import unless every card is already stored, and opens the grid.
     * The row is created here, before the redirect, because the grid
     * route answers 404 for a set with no row. Any failure leaves the
     * picker open with the reason and does not redirect. Every attempt
     * counts toward the per-minute limit, failures included, since each can
     * cost a tcgdex request. A choice that will queue an import (the set
     * is not stored yet, or not every card is) draws from the user's daily
     * ImportBudget before any tcgdex lookup, so a refused choice costs
     * nothing; a fully imported set never does. The id comes from the
     * client, so it must be of the shape tcgdex ids have and present in
     * the set list before the server makes any catalog request for it.
     */
    public function chooseSet(string $setTcgdexId): void
    {
        $key = 'add-set:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, self::ADD_SET_LIMIT_PER_MINUTE)) {
            $this->pickerError = 'Slow down: too many sets in a minute. Try again shortly.';

            return;
        }

        RateLimiter::hit($key, 60);
        $this->pickerError = null;

        if (preg_match('/\A[A-Za-z0-9.\-]{1,64}\z/', $setTcgdexId) !== 1) {
            $this->pickerError = "That set isn't available from tcgdex.";

            return;
        }

        try {
            $listed = array_any(
                app(CardCatalogProvider::class)->listSets(),
                static fn (SetSummaryData $candidate): bool => $candidate->tcgdexId === $setTcgdexId,
            );

            if (! $listed) {
                $this->pickerError = "That set isn't available from tcgdex.";

                return;
            }

            $existing = Set::where('tcgdex_id', $setTcgdexId)->first();

            if ($existing === null || ! $this->isFullyImported($existing)) {
                $budget = app(ImportBudget::class)->tryStart((int) auth()->id(), $setTcgdexId);

                if ($budget !== ImportBudgetResult::Started) {
                    $this->pickerError = $budget === ImportBudgetResult::Busy
                        ? 'Another set is being started — try again.'
                        : "You've started ".ImportBudget::DAILY_LIMIT.' sets today — try again tomorrow.';

                    return;
                }
            }

            $set = $existing ?? app(CatalogSyncService::class)->ensureSet($setTcgdexId);
        } catch (SetNotFoundException|InvalidTcgdexIdException) {
            $this->pickerError = "That set isn't available from tcgdex.";

            return;
        } catch (Throwable $e) {
            // Inside a set list failure window the attempt that opened it
            // was already reported; repeating it per click is noise.
            if (! ($e instanceof CatalogUnavailableException && $e->isBackoff)) {
                report($e);
            }

            $this->pickerError = "Couldn't reach the card catalog. Try again.";

            return;
        }

        // An unknown or zero announced total is not "complete", so the
        // import is queued; the unique job makes a repeat dispatch a no-op.
        // This is stricter than CollectionService::syncCardAndQueueImport(),
        // which treats an unknown total as incomplete but a zero total as
        // complete.
        if (! $this->isFullyImported($set)) {
            try {
                ImportSetJob::dispatch($set->tcgdex_id);
            } catch (Throwable $e) {
                report($e);
                $this->pickerError = "Couldn't start the import — try again";

                return;
            }
        }

        $this->redirectRoute('admin.sets.show', ['setTcgdexId' => $set->tcgdex_id], navigate: true);
    }

    private function isFullyImported(Set $set): bool
    {
        $total = $set->card_count ?? 0;

        return $total > 0 && $set->cards()->count() >= $total;
    }

    public function render(): View
    {
        $collectionIds = Collection::query()->pluck('id');

        $sets = Set::query()
            ->whereHas('cards.collectionItems', fn (Builder $q) => $q->whereIn('collection_id', $collectionIds))
            ->withCount([
                'cards as owned_card_count' => function (Builder $q) use ($collectionIds) {
                    $q->whereHas('collectionItems', fn (Builder $i) => $i->whereIn('collection_id', $collectionIds));
                },
                'cards as real_card_count',
            ])
            // Postgres sorts NULL first on DESC; undated sets belong last.
            ->orderByRaw('released_on desc nulls last')
            ->orderBy('name')
            ->get();

        return view('livewire.admin.sets', [
            'sets' => $sets,
            'picker' => $this->pickerOpen ? $this->pickerData() : null,
            'ownedIds' => $sets->pluck('tcgdex_id')->all(),
        ]);
    }
}
