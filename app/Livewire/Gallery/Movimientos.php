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

        $cards = Card::whereHas('collectionItems', function ($query) use ($publicCollectionIds) {
            $query->whereIn('collection_id', $publicCollectionIds);
        })->with('priceSnapshots')->get();

        $deltas = $cards
            ->map(function (Card $card) use ($resolver) {
                $dates = $resolver->distinctSnapshotDates($card);

                if ($dates->count() < 2) {
                    return null;
                }

                $latest = $resolver->resolveAsOf($card, $dates[0]);
                $previous = $resolver->resolveAsOf($card, $dates[1]);

                if ($latest === null || $previous === null) {
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
