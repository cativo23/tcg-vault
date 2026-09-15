<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection as SupportCollection;

/**
 * The read model behind every public gallery screen: "what does THIS
 * user show the world?" Built once per request from the user's public
 * collections, then every query the screens need hangs off it, so the
 * `is_public` gate lives in exactly one place instead of being re-typed
 * in each Livewire component.
 *
 * Explicit opt-out of TenantScope, deliberately: these are PUBLIC routes
 * with no authenticated user, so the scope's own auth()->id() would
 * fail-closed to zero rows — correct for every OTHER query in this app,
 * wrong here where we want $user's public rows regardless of who (if
 * anyone) is logged in. Same auditable pattern DatabaseSeeder uses.
 */
final class PublicCollection
{
    /** @param SupportCollection<int, int> $collectionIds */
    private function __construct(
        public readonly User $user,
        private readonly SupportCollection $collectionIds,
    ) {}

    public static function for(User $user): self
    {
        $ids = Collection::withoutGlobalScope(TenantScope::class)
            ->where('user_id', $user->id)
            ->where('is_public', true)
            ->pluck('id');

        return new self($user, $ids);
    }

    /** @return SupportCollection<int, int> */
    public function collectionIds(): SupportCollection
    {
        return $this->collectionIds;
    }

    public function isEmpty(): bool
    {
        return $this->collectionIds->isEmpty();
    }

    /** Constrain any CollectionItem query/relation to this user's public collections. */
    public function scopeItems(Builder|Relation $query): Builder|Relation
    {
        return $query->whereIn('collection_id', $this->collectionIds);
    }

    /**
     * Every distinct card the user owns publicly, with everything a tile
     * needs eager-loaded (set, price snapshots, the owned copies).
     */
    public function cardsQuery(): Builder
    {
        return Card::query()
            ->whereHas('collectionItems', fn (Builder $q) => $this->scopeItems($q))
            ->with([
                'set',
                'priceSnapshots',
                'collectionItems' => fn ($q) => $this->scopeItems($q)->orderBy('created_at'),
            ]);
    }

    /**
     * Every set the user has at least one public card from, annotated
     * with how many distinct cards they own from it and how many cards
     * the catalog actually holds for it (`Set.card_count` was not always
     * populated by early syncs; the real count is the honest fallback).
     */
    public function setsQuery(): Builder
    {
        return Set::query()
            ->whereHas('cards.collectionItems', fn (Builder $q) => $this->scopeItems($q))
            ->withCount([
                'cards as owned_card_count' => function (Builder $q) {
                    $q->whereHas('collectionItems', fn (Builder $i) => $this->scopeItems($i));
                },
                'cards as real_card_count',
            ]);
    }

    /** The user's public copies (physical items), newest first. */
    public function itemsQuery(): Builder
    {
        return $this->scopeItems(CollectionItem::query())->latest();
    }

    public function ownsSet(Set $set): bool
    {
        return $set->cards()
            ->whereHas('collectionItems', fn (Builder $q) => $this->scopeItems($q))
            ->exists();
    }
}
