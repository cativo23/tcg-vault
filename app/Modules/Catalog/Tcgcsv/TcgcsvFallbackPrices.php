<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tcgcsv;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * tcgcsv's checked TCGplayer price for each linked print, held until the
 * tcgdex sync can fall back on it. tcgcsv publishes once a day and its build
 * is pulled once, while tcgdex syncs at another hour, so the price is kept
 * for MAX_BUILD_AGE_HOURS after its build and read by the sync that decides
 * whether a print needs it. It only exists in fill mode.
 *
 * @phpstan-type Entry array{market: int, low: int|null, productId: int, groupId: int, subType: string, build: string}
 */
final class TcgcsvFallbackPrices
{
    /** A build older than this is not stamped as today's price. */
    public const MAX_BUILD_AGE_HOURS = 36;

    private const KEY_PREFIX = 'tcgcsv:fallback:';

    /**
     * Stores each priced print under the build's own lifetime. A print with
     * no market price, or a zero one, is skipped.
     *
     * @param  list<array{int, string, TcgcsvPriceRow}>  $items  [card_id, variant, price]
     * @return int how many prints were stored
     */
    public function putMany(array $items, string $build): int
    {
        $expires = CarbonImmutable::parse($build)->addHours(self::MAX_BUILD_AGE_HOURS);
        if (! $this->enabled() || $expires->isPast()) {
            return 0;
        }

        $values = [];
        foreach ($items as [$cardId, $variant, $row]) {
            $entry = $this->entryFor($row, $build);
            if ($entry !== null) {
                $values[$this->key($cardId, $variant)] = $entry;
            }
        }

        if ($values !== []) {
            Cache::putMany($values, $expires);
        }

        return count($values);
    }

    /**
     * The stored prices for some of a card's variants, keyed by variant.
     *
     * @param  list<string>  $variants
     * @return array<string, Entry>
     */
    public function forCard(int $cardId, array $variants): array
    {
        if ($variants === [] || ! $this->enabled()) {
            return [];
        }

        $keys = [];
        foreach ($variants as $variant) {
            $keys[$this->key($cardId, $variant)] = $variant;
        }

        $found = [];
        foreach (Cache::many(array_keys($keys)) as $key => $entry) {
            if (is_array($entry)) {
                /** @var Entry $entry */
                $found[$keys[$key]] = $entry;
            }
        }

        return $found;
    }

    /**
     * What is kept of a tcgcsv price, or null when it carries no market price.
     *
     * @return Entry|null
     */
    public function entryFor(TcgcsvPriceRow $row, string $build): ?array
    {
        if ($row->marketMinor === null || $row->marketMinor <= 0) {
            return null;
        }

        return [
            'market' => $row->marketMinor,
            'low' => $row->lowMinor,
            'productId' => $row->productId,
            'groupId' => $row->groupId,
            'subType' => $row->subType,
            'build' => $build,
        ];
    }

    /**
     * The snapshot columns a stored price is written as — the one place a
     * tcgcsv row is defined, so every writer produces the same row.
     *
     * @param  Entry  $entry
     * @return array<string, mixed>
     */
    public function snapshotAttributes(array $entry): array
    {
        return [
            'origin' => 'tcgcsv',
            'currency' => 'USD',
            'market_minor' => $entry['market'],
            'low_minor' => $entry['low'],
            'trend_minor' => null,
            'source_updated_at' => CarbonImmutable::parse($entry['build'])->utc(),
            'raw' => [
                'productId' => $entry['productId'],
                'groupId' => $entry['groupId'],
                'subType' => $entry['subType'],
                'build' => $entry['build'],
            ],
        ];
    }

    /** Whether stored prices are used at all: only in fill mode. */
    public function enabled(): bool
    {
        return config('tcgcsv.mode') === 'fill';
    }

    private function key(int $cardId, string $variant): string
    {
        return self::KEY_PREFIX."{$cardId}|{$variant}";
    }
}
