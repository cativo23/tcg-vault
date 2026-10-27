<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Contracts\CardImageFallback;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Exceptions\CatalogIdentityMismatchException;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Tcgcsv\TcgcsvFallbackPrices;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CatalogSyncService
{
    /** @var array<string, Set> */
    private array $syncedSets = [];

    public function __construct(
        private readonly CardCatalogProvider $provider,
        private readonly ?CardImageFallback $imageFallback = null,
        private readonly ?TcgcsvFallbackPrices $fallbackPrices = null,
    ) {}

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
        // `??` also covers a null $existingCard, not just a null set.
        $set = $existingCard->set ?? $this->syncSet($cardDetail->setTcgdexId);

        $imageUrl = $this->imageUrlFor($cardDetail, $existingCard, $set);

        return DB::transaction(function () use ($cardDetail, $set, $imageUrl): Card {
            $card = Card::updateOrCreate(
                ['tcgdex_id' => $cardDetail->tcgdexId],
                [
                    'set_id' => $set->id,
                    'local_id' => $cardDetail->localId,
                    'name' => $cardDetail->name,
                    'rarity' => $cardDetail->rarity,
                    'variants' => $cardDetail->variants,
                    'official_image_url' => $imageUrl,
                    'raw' => $cardDetail->raw,
                    'synced_at' => CarbonImmutable::now(),
                ],
            );

            $this->storePriceSnapshots($card, $cardDetail);

            // The row was written inside this transaction, so it exists.
            return $card->fresh(['priceSnapshots']) ?? $card;
        });
    }

    /**
     * tcgdex's own image when it sends one; otherwise the image already
     * stored, so a day when the asset check fails can't erase it; only
     * then the fallback, which looks the file up on tcgdex's asset server.
     * Done here, the one place the image is saved, so screens that call
     * findCard() without storing anything pay nothing for it.
     */
    private function imageUrlFor(CardDetailData $cardDetail, ?Card $existingCard, Set $set): ?string
    {
        if ($cardDetail->officialImageUrl !== null) {
            return $cardDetail->officialImageUrl;
        }

        // Kept even if tcgdex stops sending an image it used to: rare, and
        // better than an image that disappears whenever the check fails.
        if ($existingCard !== null && (string) $existingCard->official_image_url !== '') {
            return $existingCard->official_image_url;
        }

        return ($this->imageFallback ?? app(CardImageFallback::class))->resolve($set->tcgdex_id, $cardDetail->localId);
    }

    /**
     * The local row for a set, created from tcgdex when it is missing.
     * A set already stored is returned as is: no request, so choosing a
     * set the collector already has works while tcgdex is down. A missing
     * one costs a single findSet() call and leaves nothing behind when
     * that call fails.
     */
    public function ensureSet(string $setTcgdexId): Set
    {
        return Set::where('tcgdex_id', $setTcgdexId)->first() ?? $this->syncSet($setTcgdexId);
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
        $heldBack = $this->heldBackTcgplayerVariants($card, $cardDetail);

        foreach ($cardDetail->prices as $price) {
            if ($price->source === 'tcgplayer' && isset($heldBack[$price->variant])) {
                continue;
            }

            CardPriceSnapshot::updateOrCreate(
                [
                    'card_id' => $card->id,
                    'source' => $price->source,
                    'variant' => $price->variant,
                    'captured_on' => $today,
                ],
                [
                    // Explicit, not the column default: a default only
                    // applies on insert, and this may update a row another
                    // origin wrote for the same day.
                    'origin' => 'tcgdex',
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

    /**
     * The linked variants whose TCGplayer row this sync leaves unwritten,
     * keyed by variant: those with a kept tcgcsv price (the previous
     * evening's checked build) while tcgdex sent no price for them (or a
     * zero one), or one TCGplayer last updated over STALE_AFTER_DAYS ago — a
     * frozen price tcgdex keeps re-sending. A price with no update date
     * counts as current, and a kept price whose product or subtype no longer
     * matches the link is ignored.
     *
     * The day's row is written by the evening tcgcsv run, when that day's
     * build is read. Writing tcgdex's frozen price now would block that run
     * (it never overwrites a row tcgdex wrote today), and copying last
     * evening's build would date it today, so it would show yesterday's
     * price as today's until the run replaced it. Until then the resolver
     * keeps using the previous day's row.
     *
     * @return array<string, true>
     */
    private function heldBackTcgplayerVariants(Card $card, CardDetailData $cardDetail): array
    {
        // The container leaves an optional, unbound class parameter at its
        // default, so the store is resolved here like the image fallback.
        $store = $this->fallbackPrices ?? app(TcgcsvFallbackPrices::class);
        if (! $store->enabled()) {
            return [];
        }

        $links = CardTcgplayerLink::where('card_id', $card->id)->get(['variant', 'product_id', 'sub_type'])->keyBy('variant');
        if ($links->isEmpty()) {
            return [];
        }

        $theirs = [];
        foreach ($cardDetail->prices as $price) {
            if ($price->source === 'tcgplayer') {
                $theirs[$price->variant] = $price;
            }
        }

        $staleBefore = CarbonImmutable::today()->subDays(CardPriceResolver::STALE_AFTER_DAYS);
        $heldBack = [];
        foreach ($store->forCard($card->id, array_values(array_map('strval', $links->keys()->all()))) as $variant => $entry) {
            $link = $links->get($variant);
            if ($link === null || $entry['productId'] !== (int) $link->product_id || $entry['subType'] !== $link->sub_type) {
                continue;
            }

            $price = $theirs[$variant] ?? null;
            $current = $price !== null
                && ($price->marketMinor ?? 0) > 0
                && ($price->sourceUpdatedAt === null || ! $price->sourceUpdatedAt->lt($staleBefore));
            if (! $current) {
                $heldBack[$variant] = true;
            }
        }

        return $heldBack;
    }
}
