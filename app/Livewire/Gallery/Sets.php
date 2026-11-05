<?php

declare(strict_types=1);

namespace App\Livewire\Gallery;

use App\Livewire\Gallery\Concerns\ResolvesPublicCollection;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\DecidingPriceRows;
use App\Modules\Catalog\Support\PrintedStrip;
use App\Modules\Collection\Services\Valuation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Sets are collections within the collection: completion, a mini printed strip and owned value per set. */
#[Layout('layouts.revamp')]
final class Sets extends Component
{
    use ResolvesPublicCollection;

    // Same bound as Gallery\Index — a public, unauthenticated route must
    // never fan out unbounded work regardless of how large one
    // collector's public collection grows.
    private const MAX_CARDS = 600;

    public function mount(string $username): void
    {
        $this->resolveTargetUser($username);
    }

    public function render(): View
    {
        $public = $this->publicCollection();
        $valuation = new Valuation;

        // Each set's imported card numbers ride in this same query as one json
        // subselect, so the strip costs no query of its own however many sets
        // there are. Sets released the same day fall back to name order.
        $sets = $public->setsQuery()
            ->addSelect(['imported_numbers' => Card::query()
                ->selectRaw("coalesce(json_agg(cards.local_id), '[]'::json)")
                ->whereColumn('cards.set_id', 'sets.id')])
            ->orderByDesc('released_on')
            ->orderBy('name')
            ->get();

        // One query for every owned card and one for the price rows that
        // decide their current prices, grouped per set in PHP, so the
        // per-set value never becomes a query per set.
        $ownedBySet = app(DecidingPriceRows::class)
            ->loadOnto($public->cardsQuery()->take(self::MAX_CARDS)->get())
            ->groupBy('set_id');

        $setValues = $sets->mapWithKeys(fn ($set) => [
            $set->id => $valuation->totalsByCurrency($ownedBySet->get($set->id, collect())),
        ]);

        // The same slot rule as the set page's strip, compressed into runs.
        $strips = $sets->mapWithKeys(fn (Set $set) => [
            $set->id => PrintedStrip::runs(PrintedStrip::slots(
                $this->importedCards($set, $ownedBySet->get($set->id, collect())),
                $set->card_count ?: (int) $set->getAttribute('real_card_count'),
            )),
        ]);

        $name = $this->collectorName();

        return view('livewire.gallery.sets', [
            'sets' => $sets,
            'setValues' => $setValues,
            'strips' => $strips,
        ])->layoutData([
            'title' => "Sets · {$name}'s collection",
            'description' => "The {$sets->count()} Pokémon TCG sets in {$name}'s collection, with completion and owned value per set.",
            'isOwner' => $this->isOwnerViewing(),
        ]);
    }

    /**
     * The set's imported cards as the strip rule wants them: number plus
     * whether the collector owns it (owned numbers come from the cards the
     * page already loaded, so the strip is bounded like the value is).
     *
     * @param  SupportCollection<int, Card>  $owned
     * @return list<array{number: string, owned: bool}>
     */
    private function importedCards(Set $set, SupportCollection $owned): array
    {
        $ownedNumbers = [];
        foreach ($owned as $card) {
            $ownedNumbers[(string) $card->local_id] = true;
        }

        $numbers = json_decode((string) $set->getAttribute('imported_numbers'), true);

        return array_map(
            fn (mixed $number) => ['number' => (string) $number, 'owned' => isset($ownedNumbers[(string) $number])],
            is_array($numbers) ? array_values($numbers) : [],
        );
    }
}
