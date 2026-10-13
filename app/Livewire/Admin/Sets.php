<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Exceptions\CatalogUnavailableException;
use App\Modules\Catalog\Exceptions\MalformedCatalogResponseException;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The owner's sets: every set they hold at least one card from, with how
 * many distinct cards they own. Reaches the owner's collections only
 * through Collection's TenantScope, so a private collection works and
 * another user's sets never appear.
 */
#[Layout('layouts.app')]
final class Sets extends Component
{
    public bool $pickerOpen = false;

    public string $pickerSearch = '';

    #[Locked]
    public ?string $pickerError = null;

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
     * @return array{sets: list<SetSummaryData>, failed: bool, total: int}
     */
    private function pickerData(): array
    {
        try {
            $all = array_reverse(app(CardCatalogProvider::class)->listSets());
        } catch (CatalogUnavailableException|MalformedCatalogResponseException $e) {
            report($e);

            return ['sets' => [], 'failed' => true, 'total' => 0];
        }

        $needle = mb_strtolower(trim($this->pickerSearch));

        $sets = $needle === '' ? $all : array_values(array_filter(
            $all,
            static fn (SetSummaryData $set): bool => str_contains(mb_strtolower($set->name), $needle)
                || str_contains(mb_strtolower($set->tcgdexId), $needle),
        ));

        return ['sets' => array_values($sets), 'failed' => false, 'total' => count($all)];
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
