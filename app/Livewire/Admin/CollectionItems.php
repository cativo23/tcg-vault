<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Support\CardVariants;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
final class CollectionItems extends Component
{
    use WithPagination;

    private const VALUE_SORT_ROW_LIMIT = 1000;

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'condition', except: '')]
    public string $conditionFilter = '';

    #[Url(as: 'variant', except: '')]
    public string $variantFilter = '';

    #[Url(as: 'review', except: false)]
    public bool $needsReviewOnly = false;

    #[Url(except: 'value')]
    public string $sort = 'value';

    private const SORTS = ['value', 'name', 'newest'];

    /**
     * Whether the collector's page is visible to anyone but themselves.
     * Every creation path (this component's own save(), Import, the
     * seeder) defaults a brand-new Collection to false — there was never
     * a way to change it afterward anywhere in the app until this.
     */
    public bool $isPublic = false;

    public function mount(): void
    {
        $this->isPublic = $this->resolveCollection()->is_public;
    }

    /**
     * Same firstOrCreate identity (user_id + 'my-collection' slug) every
     * other collection-touching component uses — AddCollectionItem,
     * Import. A user with no items yet still gets a real row here so
     * the toggle has something to persist to before they've added
     * anything.
     */
    private function resolveCollection(): Collection
    {
        return Collection::firstOrCreate(
            ['user_id' => auth()->id(), 'slug' => 'my-collection'],
            ['name' => 'My Collection', 'is_public' => false],
        );
    }

    /**
     * One parameterless action per option rather than a toggle or a
     * setVisibility($bool): a double-click on "Public" can never land the
     * collection back on private, and there is no client-supplied value
     * for PHP's loose bool coercion to misread (the string "false" is
     * truthy).
     */
    public function makePublic(): void
    {
        $this->applyVisibility(true);
    }

    public function makePrivate(): void
    {
        $this->applyVisibility(false);
    }

    private function applyVisibility(bool $public): void
    {
        $this->isPublic = $public;
        $this->resolveCollection()->update(['is_public' => $public]);

        $this->dispatch('visibility-saved');
    }

    /**
     * Receiving the editor's event is the whole job: Livewire re-renders
     * the list after any action, so the counts and totals reflect the edit.
     */
    #[On('card-copies-changed')]
    public function refreshAfterCardChange(): void {}

    public function sortBy(string $sort): void
    {
        if (in_array($sort, self::SORTS, true)) {
            $this->sort = $sort;
            $this->resetPage();
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedConditionFilter(): void
    {
        $this->resetPage();
    }

    public function updatedVariantFilter(): void
    {
        $this->resetPage();
    }

    public function updatedNeedsReviewOnly(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        // Card-editor actions run in the CardEditor child and never reach
        // this method. It re-runs for list changes (search, filters, sort,
        // paging) and when the editor reports a changed card, which is what
        // keeps the counts and totals current.

        // Reached only through Collection::items(), which is scoped via
        // Collection's TenantScope — never query CollectionItem::query()
        // directly here, that would bypass the tenant filter entirely.
        $collectionIds = Collection::query()->pluck('id');

        $itemsQuery = CollectionItem::query()
            ->whereIn('collection_id', $collectionIds)
            ->with(['card.set', 'card.priceSnapshots' => fn ($q) => $q->recent()]);

        if ($this->search !== '') {
            $term = '%'.trim($this->search).'%';
            $itemsQuery->where(function ($q) use ($term) {
                $q->whereHas('card', fn ($cq) => $cq->where('name', 'ilike', $term))
                    ->orWhereHas('card.set', fn ($sq) => $sq->where('name', 'ilike', $term))
                    ->orWhere('notes', 'ilike', $term);
            });
        }

        if ($this->conditionFilter !== '') {
            $itemsQuery->where('condition', $this->conditionFilter);
        }

        if ($this->variantFilter === '__none__') {
            $itemsQuery->whereNull('variant');
        } elseif ($this->variantFilter !== '') {
            $itemsQuery->where('variant', $this->variantFilter);
        }

        if ($this->needsReviewOnly) {
            $itemsQuery->where('needs_variant_review', true);
        }

        $resolver = new CardPriceResolver;

        // Bounded by a real personal collection's size (dozens–low
        // hundreds) — same ceiling the old single-item 'value' sort
        // already assumed. Grouping-then-paginating can't be expressed as
        // a single SQL query here: market_minor lives on a separate
        // priceSnapshots relation CardPriceResolver walks in PHP, so the
        // aggregate a card-row sorts/pages by can only be computed after
        // every matching item is loaded.
        $allItems = $itemsQuery->orderBy('card_id')->orderBy('id')->limit(self::VALUE_SORT_ROW_LIMIT)->get();

        // A count exactly at the ceiling means the query was almost
        // certainly cut off mid-result (rather than the collection
        // coincidentally having precisely this many matching rows) — the
        // view uses this to surface an honest notice instead of silently
        // dropping items, undercounting totals, or splitting a card's own
        // item set mid-group.
        $possiblyTruncated = $allItems->count() === self::VALUE_SORT_ROW_LIMIT;

        $groups = $allItems->groupBy('card_id')->map(function ($items) use ($resolver) {
            $valued = $items->map(function (CollectionItem $item) use ($resolver) {
                // resolveForVariant, not resolve(): each item IS a
                // specific variant — the card-level chain would price
                // every item for this card identically regardless of
                // which variant it actually is.
                $snapshot = $resolver->resolveForVariant($item->card, $item->variant);
                // Transient, view-only attributes on a real Eloquent model —
                // never pass this item to save()/update() after this point,
                // it would try to persist these as real columns.
                $item->setAttribute('_valueMinor', $snapshot?->market_minor);
                $item->setAttribute('_currency', $snapshot?->currency);

                return $item;
            });

            $pricedItems = $valued->filter(fn (CollectionItem $i) => $i->getAttribute('_valueMinor') !== null);
            $currencies = $pricedItems->pluck('_currency')->unique();

            // Summing minor units across items priced in different
            // currencies (one USD variant, one EUR variant on the same
            // card) is arithmetically meaningless — only produce a total
            // when every priced item in the group shares one currency.
            $totalCurrency = $currencies->count() === 1 ? $currencies->first() : null;
            $totalValueMinor = $totalCurrency !== null
                ? (int) $pricedItems->sum(fn (CollectionItem $i) => $i->getAttribute('_valueMinor') * $i->quantity)
                : null;

            return (object) [
                'card' => $valued->firstOrFail()->card,
                'items' => $valued->sortBy('variant')->values(),
                'totalQuantity' => (int) $valued->sum('quantity'),
                'totalValueMinor' => $totalValueMinor,
                'totalValueCurrency' => $totalCurrency,
                'hasMixedCurrencyPricing' => $pricedItems->isNotEmpty() && $currencies->count() > 1,
                'needsReview' => $valued->contains(fn (CollectionItem $i) => $i->needs_variant_review),
                'newestAt' => $valued->max('created_at'),
            ];
        })->values();

        $groups = (match ($this->sort) {
            'name' => $groups->sortBy(fn ($g) => $g->card->name),
            'newest' => $groups->sortByDesc('newestAt'),
            // A group's totalValueMinor is only comparable against another
            // group priced in the SAME currency — there's no live exchange
            // rate here to convert fairly, so raw minor units are never
            // compared across currencies. Sort by currency code first
            // (null/mixed-currency groups, which have no single number to
            // rank by, always last), then by amount descending within one
            // currency.
            default => $groups->sort(function ($a, $b) {
                if ($a->totalValueCurrency !== $b->totalValueCurrency) {
                    if ($a->totalValueCurrency === null) {
                        return 1;
                    }
                    if ($b->totalValueCurrency === null) {
                        return -1;
                    }

                    return $a->totalValueCurrency <=> $b->totalValueCurrency;
                }

                return ($b->totalValueMinor ?? 0) <=> ($a->totalValueMinor ?? 0);
            }),
        })->values();

        $perPage = 24;
        $page = $this->getPage();
        $paged = $groups->slice(($page - 1) * $perPage, $perPage)->values();

        $cardGroups = new LengthAwarePaginator(
            $paged,
            $groups->count(),
            $perPage,
            $page,
            // This route only ever renders this one component — hardcode
            // it rather than trust the paginator's default path
            // inference, which resolved to "/" instead of "/admin" here.
            ['path' => route('admin.collection.index')],
        );

        return view('livewire.admin.collection-items', [
            'cardGroups' => $cardGroups,
            // The base prints are always offered; a special print only
            // once this user's own collections hold one.
            'specialVariantOptions' => CollectionItem::query()
                ->whereIn('collection_id', $collectionIds)
                ->whereNotNull('variant')
                ->whereNotIn('variant', CardEditor::KNOWN_VARIANTS)
                ->distinct()
                ->orderBy('variant')
                ->pluck('variant')
                ->filter(fn (string $v) => CardVariants::isValid($v))
                ->values(),
            'totalCards' => $groups->count(),
            'totalCopies' => (int) $groups->sum('totalQuantity'),
            'possiblyTruncated' => $possiblyTruncated,
        ]);
    }
}
