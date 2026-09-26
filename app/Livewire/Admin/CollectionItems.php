<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Livewire\Concerns\StripsUploadedPhotos;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Support\CardVariants;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\CollectionService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
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
    use StripsUploadedPhotos;
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
     * @var array<int, array{id:int, variant:?string, condition:string, quantity:int, grade_company:?string, grade_value:?string, notes:?string, photo:mixed, photo_path:?string, showDetails:bool}>
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
     *
     * @return EloquentCollection<int, CollectionItem>
     */
    private function ownedCardItemsOrFail(int $cardId): EloquentCollection
    {
        $collectionIds = Collection::query()->pluck('id');
        $items = CollectionItem::where('card_id', $cardId)
            ->whereIn('collection_id', $collectionIds)
            ->with('card')
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            throw new NotFoundHttpException;
        }

        return $items;
    }

    public function openCardEditor(int $cardId): void
    {
        // A previous card's stale error bag would otherwise bleed into this
        // one — editingRows indexes reset per card, so an error on
        // "editingRows.0.condition" from the last card would misleadingly
        // reattach to row 0 of this one.
        $this->resetValidation();

        $items = $this->ownedCardItemsOrFail($cardId);
        $primary = $items->firstOrFail();

        $this->editingCardId = $cardId;
        $this->editingCollectionId = $primary->collection_id;
        $this->editingRows = $items->map(fn (CollectionItem $i) => [
            'id' => $i->id,
            'variant' => $i->variant,
            'condition' => $i->condition,
            'quantity' => $i->quantity,
            'grade_company' => $i->grade_company,
            'grade_value' => $i->grade_value,
            'notes' => $i->notes,
            'photo' => null,
            'photo_path' => $i->photo_path,
            'showDetails' => false,
        ])->values()->all();

        $card = $primary->card;
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
            // card — fall back to just the primary item's own already-set
            // variant (same single-item fallback the old startEditingItem()
            // had), not the union of every row's variant in this card
            // group, so one row's choice never leaks into another row's
            // dropdown as a selectable option.
            $variants = array_filter([$primary->variant]);
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
        // The modal's whole subtree — including whatever inside it held
        // focus — is removed from the DOM by this method returning, not
        // just hidden. Without telling the page which row opened it,
        // focus silently drops to <body> and a keyboard/screen-reader
        // user loses their place in the table entirely.
        $triggerId = $this->editingCardId !== null ? "card-editor-trigger-{$this->editingCardId}" : null;

        $this->resetValidation();
        $this->editingCardId = null;
        $this->editingCollectionId = null;
        $this->editingCardName = '';
        $this->editingRows = [];
        $this->editingAvailableVariants = [];
        $this->confirmingRemoveRowIndex = null;

        if ($triggerId !== null) {
            $this->dispatch('card-editor-closed', triggerId: $triggerId);
        }
    }

    public function addVariantRow(CollectionService $service): void
    {
        if ($this->editingCardId === null || $this->editingCollectionId === null) {
            return;
        }

        // The Catalog is global and not tenant-scoped (CollectionService.php:23-24)
        // — this is a straight lookup of catalog data, not a user's own
        // record, so it doesn't go through ownedItemOrFail()/ownedCardItemsOrFail().
        // Uses addItemForCard(), not addItem() — this card is already in the
        // Catalog (it has existing items in this editor), so re-syncing it
        // from tcgdex here would just be a redundant HTTP round-trip.
        $card = Card::findOrFail($this->editingCardId);
        $collection = Collection::findOrFail($this->editingCollectionId);

        $item = $service->addItemForCard($collection, $card, ['condition' => 'NM', 'quantity' => 1]);

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
            'photo' => null,
            'photo_path' => null,
            'showDetails' => false,
        ];
    }

    /** @return array<string, string> */
    protected function rules(): array
    {
        return [
            'editingRows.*.variant' => 'nullable|in:normal,holofoil,reverse-holofoil',
            'editingRows.*.condition' => 'required|in:NM,LP,MP,HP,DMG',
            'editingRows.*.quantity' => 'required|integer|min:1|max:9999',
            'editingRows.*.grade_company' => 'nullable|string|max:32',
            'editingRows.*.grade_value' => 'nullable|string|max:16',
            'editingRows.*.notes' => 'nullable|string|max:2000',
            'editingRows.*.photo' => 'nullable|image|mimes:jpeg,png,webp|max:5120',
        ];
    }

    /**
     * A file input can't autosave on wire:change the way the other
     * fields do — Livewire's own JS uploads the file and only THEN
     * assigns the bound property, so a wire:change fired at native
     * selection time would race the upload and still see the old value.
     * This generic hook instead fires after Livewire has finished
     * assigning the property (upload included), so updateRow() only
     * ever sees an already-uploaded file.
     */
    public function updated(string $name): void
    {
        if (preg_match('/^editingRows\.(\d+)\.photo$/', $name, $matches) && isset($this->editingRows[(int) $matches[1]]['photo'])) {
            $this->updateRow((int) $matches[1]);
        }
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
        $this->validateOnly("editingRows.$index.photo");

        $row = $this->editingRows[$index];
        $item = $this->ownedItemOrFail($row['id']);

        // See CollectionService::nullIfEmpty() — normalize before
        // storing, or this row silently stops matching that service's
        // own null-based identity comparisons (and the
        // collection_items_identity_unique index, which treats null and
        // '' as different values unless both sides agree on one).
        $variant = CollectionService::nullIfEmpty($row['variant']);
        $gradeCompany = CollectionService::nullIfEmpty($row['grade_company']);
        $gradeValue = CollectionService::nullIfEmpty($row['grade_value']);

        $update = [
            'variant' => $variant,
            'condition' => $row['condition'],
            'quantity' => $row['quantity'],
            'grade_company' => $gradeCompany,
            'grade_value' => $gradeValue,
            'notes' => $row['notes'],
            // A row's variant is only ever ambiguous ("needs review") until
            // it's given an explicit value — clearing the flag here, not
            // when notes/quantity/etc change, and never re-flagging it once
            // cleared.
            'needs_variant_review' => $variant !== null ? false : $item->needs_variant_review,
        ];

        // A new upload replaces the stored file; not touching this field
        // must leave the existing photo alone rather than nulling it out
        // just because this particular save didn't carry a new one.
        if ($row['photo'] !== null) {
            // Stripped before the old photo is deleted, so a file that
            // can't be processed leaves the item's current photo in place.
            if (! $this->stripUploadedPhoto($row['photo'], "editingRows.$index.photo")) {
                $this->editingRows[$index]['photo'] = null;

                return;
            }

            if ($item->photo_path) {
                Storage::disk('collection-photos')->delete($item->photo_path);
            }

            $newPath = basename($row['photo']->store('/', 'collection-photos'));
            $update['photo_path'] = $newPath;
            $this->editingRows[$index]['photo_path'] = $newPath;
            $this->editingRows[$index]['photo'] = null;
        }

        try {
            // Wrapped explicitly so a violation only rolls back THIS
            // statement (via a savepoint when nested inside a wider
            // transaction, e.g. under RefreshDatabase in tests) — without
            // this, a failed UPDATE poisons every later query in an
            // enclosing transaction, same shape as
            // InviteManager::createInvite().
            DB::transaction(fn () => $item->update($update));
        } catch (QueryException $e) {
            // The edit didn't go through CollectionService, so it has no
            // merge-on-collision handling of its own — the identity index
            // (added to close the concurrent-add race) is what's actually
            // catching this, same as it would a raw duplicate insert.
            if (! str_contains($e->getMessage(), 'collection_items_identity_unique')) {
                throw $e;
            }

            $this->addError("editingRows.$index.variant", 'This matches another row for this card — remove or adjust one of them instead.');

            return;
        }

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

        // The card no longer has any items left in this collection once
        // its last row is removed — leaving the modal open would show an
        // empty editor for a card that, from the collection's point of
        // view, doesn't exist anymore.
        if ($this->editingRows === []) {
            $this->closeCardEditor();
        }
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

    public function render(): View
    {
        // PERF: every Livewire action on this component — including ones
        // that only touch the card-editor modal (toggleRowDetails,
        // confirmRemoveRow, addVariantRow, updateRow) — re-runs this whole
        // method, so the grouped query + per-item price resolution below
        // (up to VALUE_SORT_ROW_LIMIT rows) reruns on every modal
        // interaction, not just page loads/listing changes. There's no
        // low-risk fix within this single component: Livewire re-renders
        // the whole template every action, and the computed $cardGroups
        // isn't a public property that could be memoized across requests.
        // The real fix is extracting the card editor into its own nested
        // Livewire component so its actions only re-render that child —
        // out of scope here as a larger architectural change, not a
        // targeted fix.

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
            'totalCards' => $groups->count(),
            'totalCopies' => (int) $groups->sum('totalQuantity'),
            'possiblyTruncated' => $possiblyTruncated,
        ]);
    }
}
