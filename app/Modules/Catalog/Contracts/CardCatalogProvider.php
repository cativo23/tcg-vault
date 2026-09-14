<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\SetSummaryData;

interface CardCatalogProvider
{
    /**
     * Fetch full detail + current pricing for one card.
     *
     * @throws \App\Modules\Catalog\Exceptions\CardNotFoundException
     */
    public function findCard(string $tcgdexId): CardDetailData;

    /**
     * Fetch metadata for one set (no card list).
     *
     * @throws \App\Modules\Catalog\Exceptions\SetNotFoundException
     */
    public function findSet(string $tcgdexId): SetSummaryData;

    /**
     * List the tcgdex card IDs belonging to a set, e.g. ['me05-001', 'me05-002', ...].
     * Does NOT include pricing — tcgdex has no bulk-pricing endpoint, so callers
     * must call findCard() per ID to get prices.
     *
     * @return array<int, string>
     */
    public function listSetCardIds(string $setTcgdexId): array;
}
