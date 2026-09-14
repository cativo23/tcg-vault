<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Layout('layouts.app')]
final class CollectionItems extends Component
{
    public ?int $editingItemId = null;

    public string $editingNotes = '';

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

    public function delete(int $itemId): void
    {
        $item = $this->ownedItemOrFail($itemId);

        if ($item->photo_path) {
            // Guarded: a file that's already gone (manual cleanup, a prior
            // failed delete) must not block removing the row.
            Storage::disk('collection-photos')->delete($item->photo_path);
        }

        $item->delete();
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

        $variants = array_values(array_intersect(
            self::KNOWN_VARIANTS,
            CardPriceSnapshot::where('card_id', $item->card_id)->distinct()->pluck('variant')->all(),
        ));

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

        $this->ownedItemOrFail($this->editingFullItemId)->update([
            'condition' => $this->editingCondition,
            'quantity' => $this->editingQuantity,
            'variant' => $this->editingVariant,
            'grade_company' => $this->editingGradeCompany,
            'grade_value' => $this->editingGradeValue,
        ]);
        $this->editingFullItemId = null;
    }

    public function render()
    {
        // Reached only through Collection::items(), which is scoped via
        // Collection's TenantScope — never query CollectionItem::query()
        // directly here, that would bypass the tenant filter entirely.
        $items = Collection::with(['items.card.set'])
            ->get()
            ->flatMap(fn (Collection $c) => $c->items);

        return view('livewire.admin.collection-items', ['items' => $items]);
    }
}
