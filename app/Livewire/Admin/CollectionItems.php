<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Support\CardVariants;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

    public ?int $confirmingDeleteItemId = null;

    /**
     * Snapshot of the item being deleted, captured at confirmDelete() time
     * so the confirmation modal keeps its card name/variant/qty context
     * even if that row isn't on whatever page happens to be rendered.
     *
     * @var array{name?: string, variant?: ?string, quantity?: int}
     */
    public array $deletingSummary = [];

    public ?int $editingItemId = null;

    public string $editingNotes = '';

    public ?int $editingQtyItemId = null;

    #[Validate('required|integer|min:1')]
    public int $editingQtyValue = 1;

    public ?int $editingFullItemId = null;

    #[Validate('required|in:NM,LP,MP,HP,DMG')]
    public string $editingCondition = 'NM';

    #[Validate('required|integer|min:1')]
    public int $editingQuantity = 1;

    #[Validate('nullable|in:normal,holofoil,reverse-holofoil')]
    public ?string $editingVariant = null;

    #[Validate('nullable|string|max:32')]
    public ?string $editingGradeCompany = null;

    #[Validate('nullable|string|max:16')]
    public ?string $editingGradeValue = null;

    /**
     * Not every card actually has all 3 known variants (some are
     * holofoil-only, some never printed a reverse-holofoil, etc.), so the
     * modal's Variant dropdown is narrowed to what's real for THIS item's
     * card, sourced from its synced pricing snapshots. Falls back to just
     * the item's current value (or nothing, if it has none) when the card
     * was never synced with pricing rather than showing all 3 or crashing.
     *
     * @var array<int, string>
     */
    public array $editingAvailableVariants = [];

    private const KNOWN_VARIANTS = ['normal', 'holofoil', 'reverse-holofoil'];

    /**
     * `CollectionItem` has no `user_id` column, so it can never carry
     * `TenantScope` directly (Task 2's design). That means
     * `CollectionItem::findOrFail($itemId)` alone is an IDOR: any logged-in
     * user could pass any item ID, including another tenant's, and read or
     * mutate it. This helper closes that — it walks through `Collection`
     * (which DOES carry the scope) so an item belonging to a collection
     * that isn't the authenticated user's throws a 404 `ModelNotFoundException`
     * exactly like a real not-found, not a 403 that would confirm the ID
     * exists. Every lookup by raw item ID in this class MUST go through
     * this method — never call `CollectionItem::find()`/`findOrFail()`
     * directly.
     *
     * Rethrows as `NotFoundHttpException` rather than letting the bare
     * `ModelNotFoundException` propagate: Livewire's test harness
     * (`RequestBroker::temporarilyDisableExceptionHandlingAndMiddleware`)
     * disables exception handling for everything except `HttpException`
     * and `AuthorizationException` during `Livewire::test()->call()`, so
     * only an `HttpException` subtype renders as a real 404 response —
     * a raw `ModelNotFoundException` would bubble up as an uncaught PHP
     * error in tests instead of the 404 `assertStatus(404)` expects. In
     * production this still reaches the same 404 page either way.
     */
    private function ownedItemOrFail(int $itemId): CollectionItem
    {
        try {
            $item = CollectionItem::findOrFail($itemId);
            Collection::findOrFail($item->collection_id); // throws if not the caller's collection
        } catch (ModelNotFoundException) {
            throw new NotFoundHttpException();
        }

        return $item;
    }

    public function startEditingNotes(int $itemId): void
    {
        $item = $this->ownedItemOrFail($itemId);
        $this->editingItemId = $itemId;
        $this->editingNotes = (string) $item->notes;
    }

    public function saveNotes(): void
    {
        if ($this->editingItemId === null) {
            return;
        }

        $this->ownedItemOrFail($this->editingItemId)->update(['notes' => $this->editingNotes]);
        $this->editingItemId = null;
    }

    public function startEditingQty(int $itemId): void
    {
        $item = $this->ownedItemOrFail($itemId);
        $this->editingQtyItemId = $itemId;
        $this->editingQtyValue = $item->quantity;
    }

    public function saveQty(): void
    {
        if ($this->editingQtyItemId === null) {
            return;
        }

        $this->validate(['editingQtyValue' => 'required|integer|min:1']);

        $this->ownedItemOrFail($this->editingQtyItemId)->update(['quantity' => $this->editingQtyValue]);
        $this->editingQtyItemId = null;
    }

    public function confirmDelete(int $itemId): void
    {
        // ownedItemOrFail() throws (404) for another tenant's item before
        // the modal ever opens — same IDOR posture as every other lookup
        // in this class.
        $item = $this->ownedItemOrFail($itemId);
        $this->confirmingDeleteItemId = $itemId;
        $this->deletingSummary = [
            'name' => $item->card->name,
            'variant' => $item->variant,
            'quantity' => $item->quantity,
        ];
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteItemId = null;
        $this->deletingSummary = [];
    }

    public function delete(int $itemId): void
    {
        $item = $this->ownedItemOrFail($itemId);

        if ($item->photo_path) {
            // Guarded: a file that's already gone (manual cleanup, a prior
            // failed delete) must not block removing the row.
            Storage::disk('collection-photos')->delete($item->photo_path);
        }

        $item->delete();
        $this->confirmingDeleteItemId = null;
    }

    public function startEditingItem(int $itemId): void
    {
        $item = $this->ownedItemOrFail($itemId);
        $this->editingFullItemId = $itemId;
        $this->editingCondition = $item->condition;
        $this->editingQuantity = $item->quantity;
        $this->editingVariant = $item->variant;
        $this->editingGradeCompany = $item->grade_company;
        $this->editingGradeValue = $item->grade_value;

        // The card's OWN print flags (from tcgdex, always synced) are the
        // real source of truth for what variants exist — not which
        // CardPriceSnapshot rows happen to be synced. The cardmarket
        // importer names its only foil-tier price 'holofoil' regardless
        // of whether the card has a straight holo print or only a
        // reverse-holo one, which would otherwise silently narrow this
        // dropdown to the wrong single option for a card that is normal +
        // reverse-holofoil only.
        $variants = CardVariants::available($item->card->variants ?? []);

        // Fall back to synced pricing coverage only when the card has no
        // real variant flags at all (never observed in production, but
        // test fixtures and any card synced before `variants` existed
        // may still hit this path).
        if ($variants === []) {
            $variants = array_values(array_intersect(
                self::KNOWN_VARIANTS,
                CardPriceSnapshot::where('card_id', $item->card_id)->distinct()->pluck('variant')->all(),
            ));
        }

        $this->editingAvailableVariants = $variants !== []
            ? $variants
            : array_values(array_filter([$item->variant]));

        // Only one real variant for this card and the item has no explicit
        // choice yet — default to it instead of making the user pick from a
        // single option. Never overrides an existing value.
        if ($this->editingVariant === null && count($this->editingAvailableVariants) === 1) {
            $this->editingVariant = $this->editingAvailableVariants[0];
        }
    }

    public function cancelEditingItem(): void
    {
        $this->editingFullItemId = null;
        $this->resetValidation();
    }

    public function saveItem(): void
    {
        if ($this->editingFullItemId === null) {
            return;
        }

        $this->validate();

        $item = $this->ownedItemOrFail($this->editingFullItemId);

        $item->update([
            'condition' => $this->editingCondition,
            'quantity' => $this->editingQuantity,
            'variant' => $this->editingVariant,
            'grade_company' => $this->editingGradeCompany,
            'grade_value' => $this->editingGradeValue,
            // Assigning a real variant is exactly what resolves the
            // ambiguity the importer flagged — never touched by editing
            // any other field (Notes has its own separate save method).
            'needs_variant_review' => $this->editingVariant !== null ? false : $item->needs_variant_review,
        ]);

        $this->editingFullItemId = null;
    }

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

    public function render()
    {
        // Reached only through Collection::items(), which is scoped via
        // Collection's TenantScope — never query CollectionItem::query()
        // directly here, that would bypass the tenant filter entirely.
        $collectionIds = Collection::query()->pluck('id');

        $itemsQuery = CollectionItem::query()
            ->whereIn('collection_id', $collectionIds)
            // card.priceSnapshots is eager-loaded here because
            // CardPriceResolver::resolve() reads it as a property below —
            // without this, resolving price for every row (up to
            // VALUE_SORT_ROW_LIMIT on the default 'value' sort) triggers
            // one query PER card instead of one query total.
            ->with(['card.set', 'card.priceSnapshots']);

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

        if ($this->sort === 'name') {
            $itemsQuery->join('cards', 'cards.id', '=', 'collection_items.card_id')
                ->orderBy('cards.name')
                ->select('collection_items.*');
        } elseif ($this->sort === 'newest') {
            $itemsQuery->orderByDesc('collection_items.created_at');
        }
        // 'value' (default) is resolved in PHP below — market_minor lives on
        // a separate priceSnapshots relation, not a joinable flat column,
        // and CardPriceResolver's "pick the right source" logic can't be
        // expressed as a single SQL ORDER BY.

        $items = $itemsQuery->paginate($this->sort === 'value' ? self::VALUE_SORT_ROW_LIMIT : 24, page: $this->sort === 'value' ? 1 : null);

        $resolver = new CardPriceResolver;

        if ($this->sort === 'value') {
            // Resolve value once per item, sort in memory, then slice the
            // requested page — CardPriceResolver's resolve() can't be
            // expressed as a SQL ORDER BY (it walks a source-priority
            // chain across a separate table). Bounded by a real personal
            // collection's size (dozens–low hundreds), not thousands.
            $withValue = $items->getCollection()->map(function (CollectionItem $item) use ($resolver) {
                // resolveForVariant, not resolve(): this row IS a specific
                // variant (or null, pending review) — resolve()'s
                // card-level chain would ignore that and could pick a
                // cheaper (or pricier) variant than the one this copy is.
                $snapshot = $resolver->resolveForVariant($item->card, $item->variant);
                // _valueMinor is a transient, in-memory-only sort key — it
                // is never persisted, so it must never be passed to save().
                $item->setAttribute('_valueMinor', $snapshot?->market_minor !== null ? $snapshot->market_minor * $item->quantity : -1);

                return $item;
            })->sortByDesc('_valueMinor')->values();

            $page = $this->getPage();
            $perPage = 24;
            $paged = $withValue->slice(($page - 1) * $perPage, $perPage)->values();

            $items = new LengthAwarePaginator(
                $paged,
                $withValue->count(),
                $perPage,
                $page,
            );
        }

        return view('livewire.admin.collection-items', ['items' => $items, 'resolver' => $resolver]);
    }
}
