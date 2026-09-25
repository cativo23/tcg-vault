<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Jobs\ImportSetJob;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Services\CatalogSyncService;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class CollectionService
{
    public function __construct(private readonly CatalogSyncService $catalogSyncService) {}

    /**
     * @param  array{variant?: ?string, condition: string, grade_company?: ?string, grade_value?: ?string, quantity?: int, notes?: ?string, photo_path?: ?string, needs_variant_review?: bool}  $itemData
     */
    public function addItem(Collection $collection, string $tcgdexCardId, array $itemData): CollectionItem
    {
        $card = $this->syncCardAndQueueImport($tcgdexCardId);

        return $this->addItemForCard($collection, $card, $itemData);
    }

    /**
     * Syncs the Catalog card (and queues a full-set import when needed) —
     * split out from addItem() so a caller adding MULTIPLE items for the
     * SAME card in one request can sync once and reuse the result via
     * addItemForCard(), instead of re-syncing (and re-dispatching
     * ImportSetJob) once per item.
     */
    public function syncCardAndQueueImport(string $tcgdexCardId): Card
    {
        $card = $this->catalogSyncService->syncCard($tcgdexCardId);

        // The Catalog is global, never tenant-scoped — a set only needs
        // backfilling ONCE, ever, no matter which user's call triggers it.
        // card_count is set on syncCard()'s first sync of a card from this
        // set (via syncSet()'s findSet() call); a null card_count (tcgdex
        // didn't report one) is treated as "unknown size," not "already
        // complete," so it still gets queued rather than silently left
        // partial forever.
        $set = $card->set;

        if ($set->card_count === null || $set->cards()->count() < $set->card_count) {
            // afterCommit(): a caller may dispatch this from inside its own
            // DB::transaction() (e.g. one wrapping several CollectionItem
            // inserts) — this project's queue config has after_commit =>
            // false and runs Horizon on Redis, so a worker could otherwise
            // pick the job up before that transaction commits, referencing
            // catalog rows a later rollback then removes.
            ImportSetJob::dispatch($set->tcgdex_id)->afterCommit();
        }

        return $card;
    }

    /**
     * @param  array{variant?: ?string, condition: string, grade_company?: ?string, grade_value?: ?string, quantity?: int, notes?: ?string, photo_path?: ?string, needs_variant_review?: bool}  $itemData
     */
    public function addItemForCard(Collection $collection, Card $card, array $itemData): CollectionItem
    {
        $variant = $itemData['variant'] ?? null;
        $condition = $itemData['condition'];
        $gradeCompany = $itemData['grade_company'] ?? null;
        $gradeValue = $itemData['grade_value'] ?? null;
        $quantity = $itemData['quantity'] ?? 1;

        $identityColumns = [
            'variant' => $variant,
            'condition' => $condition,
            'grade_company' => $gradeCompany,
            'grade_value' => $gradeValue,
        ];

        $findExisting = fn () => $collection->items()
            ->where('card_id', $card->id)
            ->where(function ($query) use ($identityColumns): void {
                foreach ($identityColumns as $column => $value) {
                    if ($value === null) {
                        $query->whereNull($column);
                    } else {
                        $query->where($column, $value);
                    }
                }
            })
            ->first();

        $existingItem = $findExisting();

        if ($existingItem !== null) {
            return $this->mergeIntoExistingItem($existingItem, $itemData, $quantity);
        }

        try {
            // Wrapped explicitly so a violation only rolls back THIS
            // statement (via a savepoint when nested inside a wider
            // transaction, e.g. under RefreshDatabase in tests) — same
            // shape as InviteManager::createInvite().
            return DB::transaction(fn () => $collection->items()->create([
                'card_id' => $card->id,
                'card_tcgdex_id' => $card->tcgdex_id,
                'variant' => $variant,
                'condition' => $condition,
                'grade_company' => $gradeCompany,
                'grade_value' => $gradeValue,
                'quantity' => $quantity,
                'notes' => $itemData['notes'] ?? null,
                'photo_path' => $itemData['photo_path'] ?? null,
                'needs_variant_review' => $itemData['needs_variant_review'] ?? false,
            ]));
        } catch (QueryException $exception) {
            // The SELECT above already covers the common sequential case;
            // this catches the genuine race it can't (two concurrent adds
            // of the same identity both missing the existing row before
            // either commits) — the database's own unique index (see the
            // collection_items migration) is the real guarantee.
            if (! str_contains($exception->getMessage(), 'collection_items_identity_unique')) {
                throw $exception;
            }

            $existingItem = $findExisting();

            if ($existingItem === null) {
                throw $exception;
            }

            return $this->mergeIntoExistingItem($existingItem, $itemData, $quantity);
        }
    }

    /**
     * @param  array{photo_path?: ?string}  $itemData
     */
    private function mergeIntoExistingItem(CollectionItem $existingItem, array $itemData, int $quantity): CollectionItem
    {
        $newPhotoPath = $itemData['photo_path'] ?? null;
        $extra = [];

        if ($newPhotoPath !== null) {
            if ($existingItem->photo_path === null) {
                $extra['photo_path'] = $newPhotoPath;
            } else {
                // The existing item already has a photo — the caller
                // already stored $newPhotoPath to disk before this call,
                // so it must be deleted here or it's orphaned forever. We
                // never guess which photo the user "meant" to keep.
                Storage::disk('collection-photos')->delete($newPhotoPath);
            }
        }

        $existingItem->increment('quantity', $quantity, $extra);

        return $existingItem;
    }
}
