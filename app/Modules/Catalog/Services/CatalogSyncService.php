<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Exceptions\CatalogIdentityMismatchException;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CatalogSyncService
{
    /** @var array<string, Set> */
    private array $syncedSets = [];

    public function __construct(private readonly CardCatalogProvider $provider) {}

    public function syncCard(string $tcgdexCardId): Card
    {
        $cardDetail = $this->provider->findCard($tcgdexCardId);

        if ($cardDetail->tcgdexId !== $tcgdexCardId) {
            throw CatalogIdentityMismatchException::forCardMismatch($tcgdexCardId, $cardDetail->tcgdexId);
        }

        // A card already in the Catalog has a known, valid set_id — no
        // need to re-fetch/re-upsert the Set from tcgdex just to look
        // it up again. This matters specifically for SyncCardPricingJob
        // (Phase 4's daily refresh): every job used to call findSet()
        // unconditionally, doubling the daily HTTP-call count the design
        // spec explicitly budgeted for ("refreshing N cards is N HTTP
        // calls") to 2N. A brand-new card (catalog:import-set's actual
        // job, Phase 1) still goes through the full syncSet() below,
        // since its set_id isn't known yet. Trade-off: a set's OWN
        // metadata (card_count, logo_url, etc.) no longer gets refreshed
        // as a side effect of a pricing-only sync — acceptable, since
        // this job's whole point is prices, not set discovery, and sets
        // rarely change after release.
        $existingCard = Card::where('tcgdex_id', $tcgdexCardId)->first();

        // The Set upsert (and its memoization cache) must complete and commit
        // independently of the card/snapshot transaction below. If it were
        // nested inside DB::transaction() and that transaction rolled back
        // (e.g. because the Card write fails), the in-memory $syncedSets
        // cache would still reference a Set row that no longer exists in the
        // database, causing a foreign-key violation on the next card synced
        // from the same set.
        $set = $existingCard->set ?? $this->syncSet($cardDetail->setTcgdexId);

        return DB::transaction(function () use ($cardDetail, $set): Card {
            $card = Card::updateOrCreate(
                ['tcgdex_id' => $cardDetail->tcgdexId],
                [
                    'set_id' => $set->id,
                    'local_id' => $cardDetail->localId,
                    'name' => $cardDetail->name,
                    'rarity' => $cardDetail->rarity,
                    'variants' => $cardDetail->variants,
                    'official_image_url' => $cardDetail->officialImageUrl,
                    'raw' => $cardDetail->raw,
                    'synced_at' => CarbonImmutable::now(),
                ],
            );

            $this->storePriceSnapshots($card, $cardDetail);

            // The row was written inside this transaction, so it exists.
            return $card->fresh(['priceSnapshots']) ?? $card;
        });
    }

    private function syncSet(string $setTcgdexId): Set
    {
        return $this->syncedSets[$setTcgdexId] ??= $this->doSyncSet($setTcgdexId);
    }

    private function doSyncSet(string $setTcgdexId): Set
    {
        $setSummary = $this->provider->findSet($setTcgdexId);

        if ($setSummary->tcgdexId !== $setTcgdexId) {
            throw CatalogIdentityMismatchException::forSetMismatch($setTcgdexId, $setSummary->tcgdexId);
        }

        return Set::updateOrCreate(
            ['tcgdex_id' => $setSummary->tcgdexId],
            [
                'name' => $setSummary->name,
                'abbreviation' => $setSummary->abbreviation,
                'series' => $setSummary->series,
                'released_on' => $setSummary->releasedOn,
                'card_count' => $setSummary->cardCount,
                'logo_url' => $setSummary->logoUrl,
            ],
        );
    }

    private function storePriceSnapshots(Card $card, CardDetailData $cardDetail): void
    {
        $today = CarbonImmutable::today()->toDateString();

        foreach ($cardDetail->prices as $price) {
            CardPriceSnapshot::updateOrCreate(
                [
                    'card_id' => $card->id,
                    'source' => $price->source,
                    'variant' => $price->variant,
                    'captured_on' => $today,
                ],
                [
                    'currency' => $price->currency,
                    'market_minor' => $price->marketMinor,
                    'low_minor' => $price->lowMinor,
                    'trend_minor' => $price->trendMinor,
                    'raw' => $price->raw,
                    'source_updated_at' => $price->sourceUpdatedAt,
                ],
            );
        }
    }
}
