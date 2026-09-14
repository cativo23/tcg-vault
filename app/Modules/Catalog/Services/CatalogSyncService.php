<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use Carbon\CarbonImmutable;

final class CatalogSyncService
{
    public function __construct(private readonly CardCatalogProvider $provider) {}

    public function syncCard(string $tcgdexCardId): Card
    {
        $cardDetail = $this->provider->findCard($tcgdexCardId);
        $set = $this->syncSet($cardDetail->setTcgdexId);

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

        return $card->fresh(['priceSnapshots']);
    }

    private function syncSet(string $setTcgdexId): Set
    {
        $setSummary = $this->provider->findSet($setTcgdexId);

        return Set::updateOrCreate(
            ['tcgdex_id' => $setSummary->tcgdexId],
            [
                'name' => $setSummary->name,
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
