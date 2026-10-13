<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\CardSummaryData;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Catalog\Exceptions\CatalogUnavailableException;
use App\Modules\Catalog\Exceptions\MalformedCatalogResponseException;
use App\Modules\Catalog\Exceptions\SetNotFoundException;

interface CardCatalogProvider
{
    /**
     * Fetch full detail + current pricing for one card.
     *
     * @throws CardNotFoundException
     */
    public function findCard(string $tcgdexId): CardDetailData;

    /**
     * Fetch metadata for one set (no card list).
     *
     * @throws SetNotFoundException
     */
    public function findSet(string $tcgdexId): SetSummaryData;

    /**
     * List every set tcgdex knows, as brief metadata (no cards). The
     * listing carries no series or release date, so those are null; the
     * logo has no extension upstream and gets ".png" like findSet().
     *
     * Cached for 24 hours. When tcgdex cannot be reached (or answers with
     * a body that is not a set list) the last good copy is served instead;
     * with none, the failure is thrown as a CatalogUnavailableException
     * (a body that is not a set list is reported through its previous
     * exception), and for a minute afterwards the same exception is thrown
     * without another request.
     *
     * @return array<int, SetSummaryData>
     *
     * @throws CatalogUnavailableException
     */
    public function listSets(): array;

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
