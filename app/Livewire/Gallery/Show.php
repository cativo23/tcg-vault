<?php

declare(strict_types=1);

namespace App\Livewire\Gallery;

use App\Models\User;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Scopes\TenantScope;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Layout('layouts.public')]
final class Show extends Component
{
    public User $targetUser;

    public Set $set;

    public string $search = '';

    public string $sort = 'number';

    public string $rarityFilter = '';

    public function mount(string $username, string $setTcgdexId): void
    {
        $user = User::where('username', $username)->first();

        if ($user === null) {
            throw new NotFoundHttpException();
        }

        $this->targetUser = $user;

        $publicCollectionIds = Collection::withoutGlobalScope(TenantScope::class)
            ->where('user_id', $user->id)
            ->where('is_public', true)
            ->pluck('id');

        $set = Set::where('tcgdex_id', $setTcgdexId)
            ->whereHas('cards.collectionItems', function ($query) use ($publicCollectionIds) {
                $query->whereIn('collection_id', $publicCollectionIds);
            })
            ->first();

        if ($set === null) {
            throw new NotFoundHttpException();
        }

        $this->set = $set;
    }

    public function render()
    {
        $publicCollectionIds = Collection::withoutGlobalScope(TenantScope::class)
            ->where('user_id', $this->targetUser->id)
            ->where('is_public', true)
            ->pluck('id');

        $cardsQuery = $this->set->cards()
            ->with(['priceSnapshots', 'collectionItems' => function ($query) use ($publicCollectionIds) {
                $query->whereIn('collection_id', $publicCollectionIds);
            }]);

        if ($this->search !== '') {
            $cardsQuery->where('name', 'like', '%'.$this->search.'%');
        }

        if ($this->rarityFilter !== '') {
            $cardsQuery->where('rarity', $this->rarityFilter);
        }

        $cards = $cardsQuery->get();

        $resolver = new CardPriceResolver();
        $priced = $cards->map(fn ($card) => [
            'card' => $card,
            'snapshot' => $resolver->resolve($card),
            'ownedItem' => $card->collectionItems->first(),
        ]);

        $priced = match ($this->sort) {
            'name' => $priced->sortBy(fn ($p) => $p['card']->name),
            'rarity' => $priced->sortBy(fn ($p) => $p['card']->rarity ?? ''),
            'price' => $priced->sortByDesc(fn ($p) => $p['snapshot']?->market_minor ?? -1),
            default => $priced->sortBy(fn ($p) => $p['card']->local_id),
        };

        $allSetCards = $this->set->cards()->with('priceSnapshots')->get();
        $mostExpensive = $allSetCards
            ->map(fn ($card) => ['card' => $card, 'snapshot' => $resolver->resolve($card)])
            ->filter(fn ($p) => $p['snapshot'] !== null)
            ->sortByDesc(fn ($p) => $p['snapshot']->market_minor)
            ->first();

        $fullSetValueMinor = $allSetCards
            ->map(fn ($card) => $resolver->resolve($card)?->market_minor ?? 0)
            ->sum();

        $ownedCount = $this->set->cards()
            ->whereHas('collectionItems', fn ($q) => $q->whereIn('collection_id', $publicCollectionIds))
            ->count();

        $rarities = $this->set->cards()->whereNotNull('rarity')->distinct()->pluck('rarity');

        return view('livewire.gallery.show', [
            'priced' => $priced,
            'mostExpensive' => $mostExpensive,
            'fullSetValueMinor' => $fullSetValueMinor,
            'ownedCount' => $ownedCount,
            'totalCount' => $this->set->card_count ?? $allSetCards->count(),
            'rarities' => $rarities,
        ]);
    }
}
