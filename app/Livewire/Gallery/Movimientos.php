<?php

declare(strict_types=1);

namespace App\Livewire\Gallery;

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Scopes\TenantScope;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Layout('layouts.public')]
final class Movimientos extends Component
{
    public User $targetUser;

    public function mount(string $username): void
    {
        $user = User::where('username', $username)->first();

        if ($user === null) {
            throw new NotFoundHttpException();
        }

        $this->targetUser = $user;
    }

    public function render()
    {
        $publicCollectionIds = Collection::withoutGlobalScope(TenantScope::class)
            ->where('user_id', $this->targetUser->id)
            ->where('is_public', true)
            ->pluck('id');

        $resolver = new CardPriceResolver();

        // Cards capped at 500: this is a public, unauthenticated route,
        // and an ever-growing collection would otherwise mean an
        // unbounded per-card query on every render(). Snapshots capped
        // to the last 14 days: a delta only ever needs "today vs. the
        // most recent prior day," so eager-loading a card's ENTIRE price
        // history here (which could be a year of daily rows) would still
        // leave the request's memory footprint unbounded even with the
        // card cap in place.
        $cards = Card::whereHas('collectionItems', function ($query) use ($publicCollectionIds) {
            $query->whereIn('collection_id', $publicCollectionIds);
        })->with(['priceSnapshots' => fn ($q) => $q->where('captured_on', '>=', now()->subDays(14))])
            ->take(500)
            ->get();

        $deltas = $cards
            ->map(function (Card $card) use ($resolver) {
                $latest = $resolver->resolve($card);

                if ($latest === null || $latest->market_minor === null) {
                    return null;
                }

                $previous = $resolver->previousComparable($card, $latest);

                if ($previous === null) {
                    return null;
                }

                return [
                    'card' => $card,
                    'latest' => $latest,
                    'deltaMinor' => $latest->market_minor - $previous->market_minor,
                ];
            })
            ->filter()
            ->values();

        $recentItems = CollectionItem::whereIn('collection_id', $publicCollectionIds)
            ->with('card')
            ->latest()
            ->take(20)
            ->get();

        return view('livewire.gallery.movimientos', [
            'deltas' => $deltas,
            'recentItems' => $recentItems,
        ]);
    }
}
