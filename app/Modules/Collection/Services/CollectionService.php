<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Modules\Catalog\Services\CatalogSyncService;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;

final class CollectionService
{
    public function __construct(private readonly CatalogSyncService $catalogSyncService) {}

    /**
     * @param array{variant?: ?string, condition: string, grade_company?: ?string, grade_value?: ?string, quantity?: int, notes?: ?string, photo_path?: ?string} $itemData
     */
    public function addItem(Collection $collection, string $tcgdexCardId, array $itemData): CollectionItem
    {
        $card = $this->catalogSyncService->syncCard($tcgdexCardId);

        return $collection->items()->create([
            'card_id' => $card->id,
            'card_tcgdex_id' => $tcgdexCardId,
            'variant' => $itemData['variant'] ?? null,
            'condition' => $itemData['condition'],
            'grade_company' => $itemData['grade_company'] ?? null,
            'grade_value' => $itemData['grade_value'] ?? null,
            'quantity' => $itemData['quantity'] ?? 1,
            'notes' => $itemData['notes'] ?? null,
            'photo_path' => $itemData['photo_path'] ?? null,
        ]);
    }
}
