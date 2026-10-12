<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Livewire\Concerns\PicksCustomPrint;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Edits every copy of one card in the signed-in user's collection: variant,
 * condition, quantity, grading, notes, photo, a manual market price and
 * removal. The host (the collection list) mounts it once and opens
 * it with the `open-card-editor` event; it reports back with
 * `card-copies-changed` so a host can refresh its own counts. It is its own
 * component so its actions re-render only this markup, not the host's list.
 */
final class CardEditor extends Component
{
    use PicksCustomPrint;
    use StripsUploadedPhotos;
    use WithFileUploads;

    // 'modal' centres the editor over the page; 'panel' docks it to the
    // right edge, or to the bottom of the screen on a phone.
    #[Locked]
    public string $presentation = 'modal';

    public const KNOWN_VARIANTS = ['normal', 'holofoil', 'reverse-holofoil'];

    public function mount(string $presentation = 'modal'): void
    {
        $this->presentation = $presentation === 'panel' ? 'panel' : 'modal';
    }

    /**
     * `CollectionItem` has no `user_id` column, so it can never carry
     * `TenantScope` directly. That means
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
     * @var array<int, array{id:int, variant:?string, condition:string, quantity:int, grade_company:?string, grade_value:?string, notes:?string, photo:mixed, photo_path:?string, showDetails:bool, manual_price:?string}>
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
        $items = $this->ownedCardItems($cardId);

        if ($items->isEmpty()) {
            throw new NotFoundHttpException;
        }

        return $items;
    }

    /**
     * Every copy of one card across the caller's own collections, possibly none.
     *
     * @return EloquentCollection<int, CollectionItem>
     */
    private function ownedCardItems(int $cardId): EloquentCollection
    {
        $collectionIds = Collection::query()->pluck('id');

        return CollectionItem::where('card_id', $cardId)
            ->whereIn('collection_id', $collectionIds)
            ->with('card')
            ->orderBy('id')
            ->get();
    }

    /** The collection new copies go into, created on first use. */
    private function ownCollection(): Collection
    {
        return Collection::firstOrCreate(
            ['user_id' => auth()->id(), 'slug' => 'my-collection'],
            ['name' => 'My Collection', 'is_public' => false],
        );
    }

    #[On('open-card-editor')]
    public function openCardEditor(int $cardId, bool $allowEmpty = false): void
    {
        // A previous card's stale error bag would otherwise bleed into this
        // one — editingRows indexes reset per card, so an error on
        // "editingRows.0.condition" from the last card would misleadingly
        // reattach to row 0 of this one.
        $this->resetValidation();

        // Strict by default: a card the caller owns nothing of is a 404,
        // never a hint that the card exists. A host that offers every card
        // of a set opts in with $allowEmpty and gets an editor with no rows.
        $items = $allowEmpty ? $this->ownedCardItems($cardId) : $this->ownedCardItemsOrFail($cardId);
        $primary = $items->first();

        $this->editingCardId = $cardId;
        $this->editingCollectionId = $primary->collection_id ?? $this->ownCollection()->id;
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
            'manual_price' => $this->currentManualPrice($i),
        ])->values()->all();

        $card = $primary !== null ? $primary->card : Card::findOrFail($cardId);
        $this->editingCardName = $card->name;

        // Variant-sourcing priority: the card's own tcgdex print flags
        // first, synced pricing coverage only as a fallback for
        // fixtures/pre-`variants` cards.
        $variants = CardVariants::available($card->variants ?? [], $card->raw ?? []);
        if ($variants === []) {
            $variants = array_values(array_intersect(
                self::KNOWN_VARIANTS,
                CardPriceSnapshot::where('card_id', $card->id)->distinct()->pluck('variant')->all(),
            ));
        }
        $fromCardData = $variants !== [];
        if ($variants === []) {
            // No tcgdex print flags AND no synced pricing at all for this
            // card — fall back to just the primary item's own already-set
            // variant, not the union of every row's variant in this card
            // group, so one row's choice never leaks into another row's
            // dropdown as a selectable option.
            $variants = array_filter([$primary?->variant]);
        }
        // A copy's own saved print stays selectable even when the card's
        // list doesn't offer it — a hand-entered print tcgdex doesn't
        // list — or the dropdown would show it as unset. Not in the
        // no-data fallback above, which deliberately offers one row's
        // variant only.
        $stored = ! $fromCardData ? [] : $items->pluck('variant')
            ->filter(fn (?string $v) => $v !== null && CardVariants::isValid($v))
            ->reject(fn (string $v) => in_array($v, $variants, true))
            ->unique()
            ->values()
            ->all();
        $this->editingAvailableVariants = [...array_values($variants), ...$stored];

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

        $this->dispatch('card-copies-changed', cardId: $this->editingCardId);

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
            'manual_price' => null,
        ];
    }

    public function useCustomVariant(int $index): void
    {
        if (! isset($this->editingRows[$index]) || ($key = $this->composeCustomVariant($index)) === null) {
            return;
        }

        if (! in_array($key, $this->editingAvailableVariants, true)) {
            $this->editingAvailableVariants[] = $key;
        }

        $this->editingRows[$index]['variant'] = $key;

        // The dropdown saves on change; a programmatic pick fires no
        // change event, so save here — otherwise a manual price entered
        // next would land on the print still stored, not the one shown.
        $this->updateRow($index);
    }

    /**
     * A market price for a print tcgdex doesn't price. Stored as a
     * catalog snapshot like tcgdex's own — a print's market price is the
     * same for every collector — so it is gated to who may edit the
     * shared catalog, not to who owns a copy.
     */
    public function saveManualPrice(int $index): void
    {
        abort_unless(auth()->user()?->can('manage-catalog-prices') === true, 403);

        if (! isset($this->editingRows[$index])) {
            return;
        }

        $this->validateOnly("editingRows.$index.manual_price");

        $price = CollectionService::nullIfEmpty($this->editingRows[$index]['manual_price'] ?? null);
        $item = $this->ownedItemOrFail($this->editingRows[$index]['id']);

        $refusal = match (true) {
            $item->variant === null => 'Save a variant for this copy first — the price belongs to a specific print.',
            $item->variant !== CollectionService::nullIfEmpty($this->editingRows[$index]['variant']) => 'Save this copy\'s variant first — the price would go to the print still stored.',
            $price === null => 'Enter a price.',
            (new CardPriceResolver)->hasCurrentMarketPrice(
                // The same 30-day window every screen prices from.
                $item->card->load(['priceSnapshots' => fn ($q) => $q->recent()]),
                $item->variant,
            ) => 'A market price for this print is already synced (tcgdex or tcgcsv) — a manual price is only for prints nothing prices, or that stopped being priced.',
            default => null,
        };

        if ($refusal !== null) {
            $this->addError("editingRows.$index.manual_price", $refusal);

            return;
        }

        CardPriceSnapshot::updateOrCreate(
            [
                'card_id' => $item->card_id,
                'source' => 'manual',
                'variant' => $item->variant,
                'captured_on' => today()->toDateString(),
            ],
            [
                'origin' => 'hand',
                'currency' => 'USD',
                'market_minor' => (int) round((float) $price * 100),
                'low_minor' => null,
                'trend_minor' => null,
                'source_updated_at' => now(),
                'raw' => ['set_by_user_id' => auth()->id()],
            ],
        );

        $this->dispatch('card-copies-changed', cardId: $this->editingCardId);
    }

    private function currentManualPrice(CollectionItem $item): ?string
    {
        if ($item->variant === null) {
            return null;
        }

        $minor = CardPriceSnapshot::where('card_id', $item->card_id)
            ->where('source', 'manual')
            ->where('variant', $item->variant)
            ->orderByDesc('captured_on')
            ->value('market_minor');

        return $minor !== null ? number_format($minor / 100, 2, '.', '') : null;
    }

    /** @return array<string, string|array<int, string>> */
    protected function rules(): array
    {
        return [
            'editingRows.*.variant' => CardVariants::rules(),
            'editingRows.*.condition' => 'required|in:NM,LP,MP,HP,DMG',
            'editingRows.*.quantity' => 'required|integer|min:1|max:9999',
            'editingRows.*.grade_company' => 'nullable|string|max:32',
            'editingRows.*.grade_value' => 'nullable|string|max:16',
            'editingRows.*.notes' => 'nullable|string|max:2000',
            'editingRows.*.photo' => 'nullable|image|mimes:jpeg,png,webp|max:5120',
            'editingRows.*.manual_price' => 'nullable|numeric|decimal:0,2|min:0.01|max:99999.99',
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
            if (! $this->stripUploadedPhoto(
                $row['photo'],
                "editingRows.$index.photo",
                function () use ($index): void {
                    $this->editingRows[$index]['photo'] = null;
                },
            )) {
                return;
            }

            if ($item->photo_path) {
                Storage::disk('collection-photos')->delete($item->photo_path);
            }

            $newPath = basename($row['photo']->store('/', 'collection-photos'));
            $update['photo_path'] = $newPath;
            $this->editingRows[$index] = ['photo_path' => $newPath, 'photo' => null] + $row;
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

        // The manual price shown belongs to the print stored; a new
        // variant means a different print's price, or none. Any other
        // field leaves it alone, so a price typed but not yet saved
        // survives a condition or quantity change.
        if ($item->wasChanged('variant')) {
            $this->editingRows[$index]['manual_price'] = $this->currentManualPrice($item);
        }

        $this->dispatch('row-saved', index: $index);
        $this->dispatch('card-copies-changed', cardId: $this->editingCardId);
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

        $this->dispatch('card-copies-changed', cardId: $this->editingCardId);

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

    public function render(): View
    {
        return view('livewire.admin.card-editor');
    }
}
