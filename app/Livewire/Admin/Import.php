<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Modules\Collection\Data\MatchedImportLine;
use App\Modules\Collection\Data\UnmatchedImportLine;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Services\CollectionService;
use App\Modules\Collection\Services\TcgplayerImportParser;
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

    public function preview(TcgplayerImportParser $parser): void
    {
        $this->summary = null;
        $this->addedCards = 0;
        $this->addedCopies = 0;

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

        if ($this->matched !== []) {
            $pending = count($this->matched);
            $this->summary = "{$this->addedCards} cartas agregadas hasta ahora, {$pending} pendientes.";

            return;
        }

        $this->summary = "{$this->addedCards} cartas agregadas, {$this->addedCopies} copias totales.";
        $this->text = '';
        $this->unmatched = [];
        // The import is over, so the empty result set below isn't a preview
        // outcome any more — don't let it render "0 líneas reconocidas"
        // underneath the success summary.
        $this->hasPreviewed = false;
    }

    /** Label for the confirm button — mid-import it has to say what's left. */
    public function getConfirmLabelProperty(): string
    {
        $pending = count($this->matched);

        return $this->addedCards > 0
            ? "Continuar importando ({$pending} restantes)"
            : 'Confirmar import';
    }

    public function render()
    {
        return view('livewire.admin.import');
    }
}
