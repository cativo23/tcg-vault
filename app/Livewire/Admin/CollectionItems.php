<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Support\CardVariants;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\CollectionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Layout('layouts.app')]
final class CollectionItems extends Component
{
    use WithFileUploads;
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

    public function updateVisibility(): void
    {
        $this->resolveCollection()->update(['is_public' => $this->isPublic]);

        $this->dispatch('visibility-saved');
    }

    public function toggleVisibility(): void
    {
        $this->isPublic = ! $this->isPublic;
        $this->updateVisibility();
    }

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
            throw new NotFoundHttpException;
        }

        return $item;
    }

    public ?int $editingCardId = null;

    public ?int $editingCollectionId = null;

    public string $editingCardName = '';

    /**
     * @var array<int, array{id:int, variant:?string, condition:string, quantity:int, grade_company:?string, grade_value:?string, notes:?string, showDetails:bool}>
     */
    public array $editingRows = [];

    /** @var array<int, string> */
    public array $editingAvailableVariants = [];

    /**
     * Same IDOR posture as ownedItemOrFail() above, scoped to every item
     * of one card instead of a single item ID — walks through Collection
     * (which carries TenantScope) so a card_id with no items in the
     * caller's OWN collection throws a 404, never a 403 that would
     * confirm the card exists in someone else's.
     */
    private function ownedCardItemsOrFail(int $cardId): \Illuminate\Support\Collection
    {
        $collectionIds = Collection::query()->pluck('id');
        $items = CollectionItem::where('card_id', $cardId)
            ->whereIn('collection_id', $collectionIds)
            ->with('card')
            ->get();

        if ($items->isEmpty()) {
            throw new NotFoundHttpException;
        }

        return $items;
    }

    public function openCardEditor(int $cardId): void
    {
        $items = $this->ownedCardItemsOrFail($cardId);

        $this->editingCardId = $cardId;
        $this->editingCollectionId = $items->first()->collection_id;
        $this->editingRows = $items->map(fn (CollectionItem $i) => [
            'id' => $i->id,
            'variant' => $i->variant,
            'condition' => $i->condition,
            'quantity' => $i->quantity,
            'grade_company' => $i->grade_company,
            'grade_value' => $i->grade_value,
            'notes' => $i->notes,
            'showDetails' => false,
        ])->values()->all();

        $card = $items->first()->card;
        $this->editingCardName = $card->name;

        // Same variant-sourcing priority as the old startEditingItem():
        // the card's own tcgdex print flags first, synced pricing
        // coverage only as a fallback for fixtures/pre-`variants` cards.
        $variants = CardVariants::available($card->variants ?? []);
        if ($variants === []) {
            $variants = array_values(array_intersect(
                self::KNOWN_VARIANTS,
                CardPriceSnapshot::where('card_id', $card->id)->distinct()->pluck('variant')->all(),
            ));
        }
        if ($variants === []) {
            // No tcgdex print flags AND no synced pricing at all for this
            // card — fall back to whatever variant(s) the owned rows
            // already have, so an item's own existing choice never
            // disappears from its own dropdown.
            $variants = collect($this->editingRows)->pluck('variant')->filter()->unique()->values()->all();
        }
        $this->editingAvailableVariants = $variants;

        // Only one real variant for this card and a row has no explicit
        // choice yet — default it instead of leaving the dropdown blank.
        // Never overrides a row's existing value.
        if (count($this->editingAvailableVariants) === 1) {
            foreach ($this->editingRows as $index => $row) {
                if ($row['variant'] === null) {
                    $this->editingRows[$index]['variant'] = $this->editingAvailableVariants[0];
                }
            }
        }
    }

    public function closeCardEditor(): void
    {
        $this->editingCardId = null;
        $this->editingCollectionId = null;
        $this->editingCardName = '';
        $this->editingRows = [];
        $this->editingAvailableVariants = [];
        $this->confirmingRemoveRowIndex = null;
    }

    public function addVariantRow(CollectionService $service): void
    {
        if ($this->editingCardId === null || $this->editingCollectionId === null) {
            return;
        }

        // The Catalog is global and not tenant-scoped (CollectionService.php:23-24)
        // — this is a straight lookup of catalog data, not a user's own
        // record, so it doesn't go through ownedItemOrFail()/ownedCardItemsOrFail().
        $card = Card::findOrFail($this->editingCardId);
        $collection = Collection::findOrFail($this->editingCollectionId);

        $item = $service->addItem($collection, $card->tcgdex_id, ['condition' => 'NM', 'quantity' => 1]);

        $existingIndex = collect($this->editingRows)->search(fn ($row) => $row['id'] === $item->id);

        if ($existingIndex !== false) {
            // addItem() merged into a row already open in this modal (an
            // unspecified-variant/NM row already existed) — reflect its
            // bumped quantity instead of silently doing nothing visible.
            $this->editingRows[$existingIndex]['quantity'] = $item->quantity;

            return;
        }

        $this->editingRows[] = [
            'id' => $item->id,
            'variant' => null,
            'condition' => 'NM',
            'quantity' => 1,
            'grade_company' => null,
            'grade_value' => null,
            'notes' => null,
            'showDetails' => false,
        ];
    }

    protected function rules(): array
    {
        return [
            'editingRows.*.variant' => 'nullable|in:normal,holofoil,reverse-holofoil',
            'editingRows.*.condition' => 'required|in:NM,LP,MP,HP,DMG',
            'editingRows.*.quantity' => 'required|integer|min:1',
            'editingRows.*.grade_company' => 'nullable|string|max:32',
            'editingRows.*.grade_value' => 'nullable|string|max:16',
            'editingRows.*.notes' => 'nullable|string|max:2000',
        ];
    }

    public function updateRow(int $index): void
    {
        if (! isset($this->editingRows[$index])) {
            return;
        }

        $this->validateOnly("editingRows.$index.variant");
        $this->validateOnly("editingRows.$index.condition");
        $this->validateOnly("editingRows.$index.quantity");
        $this->validateOnly("editingRows.$index.grade_company");
        $this->validateOnly("editingRows.$index.grade_value");
        $this->validateOnly("editingRows.$index.notes");

        $row = $this->editingRows[$index];
        $item = $this->ownedItemOrFail($row['id']);

        $item->update([
            'variant' => $row['variant'],
            'condition' => $row['condition'],
            'quantity' => $row['quantity'],
            'grade_company' => $row['grade_company'],
            'grade_value' => $row['grade_value'],
            'notes' => $row['notes'],
            // Assigning a real variant resolves the importer's ambiguity
            // flag — same rule as the old saveItem() (CollectionItems.php:342).
            'needs_variant_review' => $row['variant'] !== null ? false : $item->needs_variant_review,
        ]);

        $this->dispatch('row-saved', index: $index);
    }

    public ?int $confirmingRemoveRowIndex = null;

    public function confirmRemoveRow(int $index): void
    {
        $this->confirmingRemoveRowIndex = $index;
    }

    public function cancelRemoveRow(): void
    {
        $this->confirmingRemoveRowIndex = null;
    }

    public function removeVariantRow(int $index): void
    {
        if (! isset($this->editingRows[$index])) {
            return;
        }

        $item = $this->ownedItemOrFail($this->editingRows[$index]['id']);

        if ($item->photo_path) {
            Storage::disk('collection-photos')->delete($item->photo_path);
        }
        $item->delete();

        unset($this->editingRows[$index]);
        $this->editingRows = array_values($this->editingRows);
        $this->confirmingRemoveRowIndex = null;
    }

    public function toggleRowDetails(int $index): void
    {
        if (isset($this->editingRows[$index])) {
            $this->editingRows[$index]['showDetails'] = ! $this->editingRows[$index]['showDetails'];
        }
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

        $resolver = new CardPriceResolver;

        // Bounded by a real personal collection's size (dozens–low
        // hundreds) — same ceiling the old single-item 'value' sort
        // already assumed. Grouping-then-paginating can't be expressed as
        // a single SQL query here: market_minor lives on a separate
        // priceSnapshots relation CardPriceResolver walks in PHP, so the
        // aggregate a card-row sorts/pages by can only be computed after
        // every matching item is loaded.
        $allItems = $itemsQuery->limit(self::VALUE_SORT_ROW_LIMIT)->get();

        $groups = $allItems->groupBy('card_id')->map(function ($items) use ($resolver) {
            $valued = $items->map(function (CollectionItem $item) use ($resolver) {
                // resolveForVariant, not resolve(): each item IS a
                // specific variant — the card-level chain would price
                // every item for this card identically regardless of
                // which variant it actually is.
                $snapshot = $resolver->resolveForVariant($item->card, $item->variant);
                $item->setAttribute('_valueMinor', $snapshot?->market_minor);

                return $item;
            });

            return (object) [
                'card' => $valued->first()->card,
                'items' => $valued->sortBy('variant')->values(),
                'totalQuantity' => (int) $valued->sum('quantity'),
                'totalValueMinor' => (int) $valued->sum(
                    fn (CollectionItem $i) => ($i->_valueMinor ?? 0) * $i->quantity,
                ),
                'needsReview' => $valued->contains(fn (CollectionItem $i) => $i->needs_variant_review),
                'newestAt' => $valued->max('created_at'),
            ];
        })->values();

        $groups = (match ($this->sort) {
            'name' => $groups->sortBy(fn ($g) => $g->card->name),
            'newest' => $groups->sortByDesc('newestAt'),
            default => $groups->sortByDesc('totalValueMinor'),
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
            'totalCards' => $groups->count(),
            'totalCopies' => (int) $groups->sum('totalQuantity'),
            'resolver' => $resolver,
        ]);
    }
}
