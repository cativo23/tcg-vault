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
    public string $text = '';

    /** @var array<int, MatchedImportLine> */
    public array $matched = [];

    /** @var array<int, UnmatchedImportLine> */
    public array $unmatched = [];

    public ?string $summary = null;

    public function preview(TcgplayerImportParser $parser): void
    {
        $this->summary = null;

        $result = $parser->parse($this->text);
        $this->matched = $result->matched->all();
        $this->unmatched = $result->unmatched->all();
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

        $addedCards = 0;
        $addedCopies = 0;

        foreach ($this->matched as $line) {
            try {
                $service->addItem($collection, $line->tcgdexId, [
                    'condition' => 'NM',
                    'quantity' => $line->qty,
                ]);
                $addedCards++;
                $addedCopies += $line->qty;
            } catch (Throwable $e) {
                // One bad card during confirmation shouldn't sink the rest
                // of the batch — same catch-and-continue philosophy as
                // ImportSetJob.
                report($e);
            }
        }

        $this->summary = "{$addedCards} cartas agregadas, {$addedCopies} copias totales.";
        $this->text = '';
        $this->matched = [];
        $this->unmatched = [];
    }

    public function render()
    {
        return view('livewire.admin.import');
    }
}
