<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Modules\Catalog\Services\ImportBudget;
use App\Modules\Catalog\Services\ImportBudgetResult;
use App\Modules\Collection\Data\MatchedImportLine;
use App\Modules\Collection\Data\UnmatchedImportLine;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Services\CollectionService;
use App\Modules\Collection\Services\TcgplayerImportParser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
final class Import extends Component
{
    /**
     * Each card costs a tcgdex round-trip via CatalogSyncService, so a whole
     * 50+ card export in one confirm() is a php-fpm/nginx timeout waiting to
     * happen — and a timeout mid-batch leaves no record of what landed, so a
     * retry silently doubles everything that did. Processing a bounded slice
     * per click keeps each request short and parks the remainder in Livewire's
     * persisted component state, where progress survives between clicks.
     */
    private const CONFIRM_CHUNK_SIZE = 10;

    /**
     * Each confirm makes up to CONFIRM_CHUNK_SIZE tcgdex lookups, so this
     * caps one account at that many times this per minute.
     */
    public const CONFIRM_LIMIT_PER_MINUTE = 10;

    public string $text = '';

    /** @var array<int, MatchedImportLine> */
    public array $matched = [];

    /** @var array<int, UnmatchedImportLine> */
    public array $unmatched = [];

    public ?string $summary = null;

    public bool $hasPreviewed = false;

    /** Running totals across the chunked confirm() calls of one import. */
    public int $addedCards = 0;

    public int $addedCopies = 0;

    /**
     * Sets whose import the daily budget refused during this import, with
     * the budget's answer (an ImportBudgetResult case name).
     *
     * @var array<string, string>
     */
    public array $skippedSets = [];

    public function preview(TcgplayerImportParser $parser): void
    {
        $this->summary = null;
        $this->addedCards = 0;
        $this->addedCopies = 0;
        $this->skippedSets = [];

        $result = $parser->parse($this->text);
        $this->matched = $result->matched->all();
        $this->unmatched = $result->unmatched->all();
        $this->hasPreviewed = true;
    }

    public function confirm(CollectionService $service): void
    {
        if ($this->matched === []) {
            return;
        }

        $key = 'import-confirm:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, self::CONFIRM_LIMIT_PER_MINUTE)) {
            $this->addError('confirm', 'Too many imports in a minute. Wait a moment, then continue.');

            return;
        }

        RateLimiter::hit($key, 60);

        $collection = Collection::firstOrCreate(
            ['user_id' => auth()->id(), 'slug' => 'my-collection'],
            ['name' => 'My Collection', 'is_public' => false],
        );

        $chunk = array_splice($this->matched, 0, self::CONFIRM_CHUNK_SIZE);

        foreach ($chunk as $line) {
            try {
                $service->addItem($collection, $line->tcgdexId, [
                    'condition' => 'NM',
                    'quantity' => $line->qty,
                    'needs_variant_review' => $line->variantAmbiguous,
                ]);
                $this->addedCards++;
                $this->addedCopies += $line->qty;
            } catch (Throwable $e) {
                // One bad card during confirmation shouldn't sink the rest
                // of the batch — same catch-and-continue philosophy as
                // ImportSetJob.
                report($e);
            }
        }

        foreach ($service->skippedSetImports() as $setTcgdexId => $budget) {
            $this->skippedSets[$setTcgdexId] = $budget->name;
        }

        if ($this->matched !== []) {
            $pending = count($this->matched);
            $this->summary = "{$this->addedCards} cards added so far, {$pending} pending.".$this->skippedSetsNote();

            return;
        }

        $this->summary = "{$this->addedCards} cards added, {$this->addedCopies} copies total.".$this->skippedSetsNote();
        $this->text = '';
        $this->unmatched = [];
        // The import is over, so the empty result set below isn't a preview
        // outcome any more — don't let it render "0 lines recognized"
        // underneath the success summary.
        $this->hasPreviewed = false;
    }

    /** Tells the user which of the imported cards' sets will not load today. */
    private function skippedSetsNote(): string
    {
        $counts = array_count_values($this->skippedSets);
        $note = '';

        $exhausted = $counts[ImportBudgetResult::Exhausted->name] ?? 0;
        if ($exhausted > 0) {
            $note .= ' '.$exhausted.' '.($exhausted === 1 ? "set wasn't" : "sets weren't")
                .' imported today (daily limit of '.ImportBudget::DAILY_LIMIT.') — try again tomorrow.';
        }

        $busy = $counts[ImportBudgetResult::Busy->name] ?? 0;
        if ($busy > 0) {
            $note .= ' '.$busy.' '.($busy === 1 ? 'set' : 'sets')
                ." couldn't start importing — add a card from ".($busy === 1 ? 'it' : 'them').' again later.';
        }

        return $note;
    }

    /** Label for the confirm button — mid-import it has to say what's left. */
    public function getConfirmLabelProperty(): string
    {
        $pending = count($this->matched);

        return $this->addedCards > 0
            ? "Continue importing ({$pending} remaining)"
            : 'Confirm import';
    }

    public function render(): View
    {
        return view('livewire.admin.import');
    }
}
