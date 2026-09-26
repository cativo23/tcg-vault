<?php

declare(strict_types=1);

namespace App\Livewire\Gallery;

use App\Livewire\Gallery\Concerns\ResolvesPublicCollection;
use App\Modules\Catalog\Models\Card;
use App\Modules\Collection\Services\Valuation;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Sets are collections within the collection: completion + owned value per set. */
#[Layout('layouts.public')]
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

        $sets = $public->setsQuery()->orderByDesc('released_on')->get();

        // One query for every owned card, grouped per set in PHP, so the
        // per-set value never becomes a query per set.
        $ownedBySet = $public->cardsQuery()->take(self::MAX_CARDS)->get()->groupBy('set_id');

        $setValues = $sets->mapWithKeys(fn ($set) => [
            $set->id => $valuation->totalsByCurrency($ownedBySet->get($set->id, collect())),
        ]);

        $name = $this->collectorName();

        return view('livewire.gallery.sets', [
            'sets' => $sets,
            'setValues' => $setValues,
        ])->layoutData([
            'title' => "Sets · {$name}'s collection",
            'description' => "The {$sets->count()} Pokémon TCG sets in {$name}'s collection, with completion and owned value per set.",
            'isOwner' => $this->isOwnerViewing(),
        ]);
    }
}
