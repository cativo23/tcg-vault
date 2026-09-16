<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\CardSummaryData;
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

    /**
     * Search tcgdex by card name (brief results only — no pricing; call
     * findCard() on a chosen result for full detail + current price).
     * Optionally narrowed to one set via $setTcgdexId — passed straight
     * through to tcgdex's own server-side filter, not applied client-side.
     *
     * Always paginated server-side (a common name unfiltered by set can
     * match 200+ cards across every printing) — $page selects which
     * page of results to fetch, 1-indexed. Callers wanting "load more"
     * fetch increasing pages and append; there is no total-count field
     * to know when the last page was reached, so a caller treats a page
     * shorter than the provider's own page size as the end.
     *
     * @return array<int, CardSummaryData>
     */
    public function searchCardsByName(string $query, ?string $setTcgdexId = null, int $page = 1): array;
}
