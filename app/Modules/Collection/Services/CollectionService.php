<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Jobs\ImportSetJob;
use App\Modules\Catalog\Services\CatalogSyncService;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;

final class CollectionService
{
    public function __construct(private readonly CatalogSyncService $catalogSyncService) {}

    /**
     * @param array{variant?: ?string, condition: string, grade_company?: ?string, grade_value?: ?string, quantity?: int, notes?: ?string, photo_path?: ?string, needs_variant_review?: bool} $itemData
     */
    public function addItem(Collection $collection, string $tcgdexCardId, array $itemData): CollectionItem
    {
        $card = $this->catalogSyncService->syncCard($tcgdexCardId);

        // The Catalog is global, never tenant-scoped — a set only needs
        // backfilling ONCE, ever, no matter which user's addItem() call
        // triggers it. card_count is set on syncCard()'s first sync of a
        // card from this set (via syncSet()'s findSet() call); a null
        // card_count (tcgdex didn't report one) is treated as "unknown
        // size," not "already complete," so it still gets queued rather
        // than silently left partial forever.
        $set = $card->set;

        if ($set->card_count === null || $set->cards()->count() < $set->card_count) {
            ImportSetJob::dispatch($set->tcgdex_id);
        }

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

        $existingItem = $collection->items()
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

        if ($existingItem !== null) {
            $existingItem->increment('quantity', $quantity);

            return $existingItem;
        }

        return $collection->items()->create([
            'card_id' => $card->id,
            'card_tcgdex_id' => $tcgdexCardId,
            'variant' => $variant,
            'condition' => $condition,
            'grade_company' => $gradeCompany,
            'grade_value' => $gradeValue,
            'quantity' => $quantity,
            'notes' => $itemData['notes'] ?? null,
            'photo_path' => $itemData['photo_path'] ?? null,
            'needs_variant_review' => $itemData['needs_variant_review'] ?? false,
        ]);
    }
}
