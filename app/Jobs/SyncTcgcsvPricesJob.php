<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Tcgcsv\TcgcsvClient;
use App\Modules\Catalog\Tcgcsv\TcgcsvPriceRow;
use App\Modules\Catalog\Tcgcsv\TcgplayerLinkDiscovery;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls each linked set's TCGplayer prices from tcgcsv once per tcgcsv
 * build (about one request per group) and links prints to products.
 *
 * Shadow mode (the only mode so far): compares tcgcsv's price with the
 * tcgdex-synced TCGplayer price for each linked print and logs the
 * result — it writes no prices. That comparison is what decides whether
 * tcgcsv can be trusted to fill gaps (ROADMAP: tcgcsv as the primary
 * TCGplayer source).
 */
final class SyncTcgcsvPricesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** tcgcsv asks for a gap between requests; 150 ms keeps well inside it. */
    private const PAUSE_MICROSECONDS = 150_000;

    private const BUILD_CACHE_KEY = 'tcgcsv:last_build';

    /** Until then, an unpublished build is retried hourly; tcgdex's midnight sync covers the day after. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->setTime(23, 30);
    }

    public function handle(TcgcsvClient $client, TcgplayerLinkDiscovery $discovery): void
    {
        $build = $client->lastUpdated()->toIso8601String();

        if (Cache::get(self::BUILD_CACHE_KEY) === $build) {
            // tcgcsv's guidance: never re-pull a build already fetched.
            $this->release(3600);

            return;
        }

        [$prices, $ok, $failed] = $this->fetchPrices($client);

        $setIds = DB::table('set_tcgplayer_groups')->distinct()->pluck('set_id');
        Card::whereIn('set_id', $setIds)->chunkById(200, function ($cards) use ($discovery, $prices) {
            foreach ($cards as $card) {
                $discovery->discover($card, $prices);
            }
        });

        Log::info('tcgcsv shadow sync', ['build' => $build, 'groups_ok' => $ok, 'groups_failed' => $failed, ...$this->compare($prices)]);

        Cache::forever(self::BUILD_CACHE_KEY, $build);
    }

    /**
     * Every configured group's prices, indexed productId → subType → row.
     * A group that fails is reported and skipped, never fatal to the rest.
     *
     * @return array{array<int, array<string, TcgcsvPriceRow>>, int, int}
     */
    private function fetchPrices(TcgcsvClient $client): array
    {
        $groups = DB::table('set_tcgplayer_groups')->pluck('group_id')
            ->merge(CardTcgplayerLink::whereNotNull('group_id')->pluck('group_id'))
            ->unique()->sort()->values();

        $prices = [];
        $ok = $failed = 0;

        foreach ($groups as $i => $groupId) {
            if ($i > 0) {
                usleep(self::PAUSE_MICROSECONDS);
            }

            try {
                foreach ($client->prices((int) $groupId) as $row) {
                    $prices[$row->productId][$row->subType] = $row;
                }
                $ok++;
            } catch (Throwable $e) {
                report($e);
                $failed++;
            }
        }

        return [$prices, $ok, $failed];
    }

    /**
     * How tcgcsv's price for each linked print compares with the latest
     * tcgdex-synced TCGplayer price for it (within the resolver's 3-day
     * window), and how many linked prints tcgdex doesn't price at all —
     * the gaps tcgcsv would fill.
     *
     * @param  array<int, array<string, TcgcsvPriceRow>>  $prices
     * @return array<string, mixed>
     */
    private function compare(array $prices): array
    {
        $since = CarbonImmutable::today()->subDays(3)->toDateString();
        $diffs = [];
        $outliers = [];
        $gaps = 0;

        CardTcgplayerLink::with('card')->chunkById(500, function ($links) use ($prices, $since, &$diffs, &$outliers, &$gaps) {
            foreach ($links as $link) {
                $row = $prices[$link->product_id][$link->sub_type] ?? null;
                if ($row === null || $row->marketMinor === null) {
                    continue;
                }

                $tcgdex = CardPriceSnapshot::where('card_id', $link->card_id)
                    ->where('source', 'tcgplayer')->where('origin', 'tcgdex')->where('variant', $link->variant)
                    ->where('captured_on', '>=', $since)->whereNotNull('market_minor')
                    ->orderByDesc('captured_on')->value('market_minor');

                if ($tcgdex === null || $tcgdex === 0) {
                    $gaps++;

                    continue;
                }

                $diff = abs($row->marketMinor - $tcgdex) / $tcgdex;
                $diffs[] = $diff;
                if ($diff > 0.25 && count($outliers) < 10) {
                    $outliers[] = ($link->card->tcgdex_id ?? "card {$link->card_id}")." {$link->variant}";
                }
            }
        });

        sort($diffs);

        return [
            'compared' => count($diffs),
            'within_2_percent' => count(array_filter($diffs, fn (float $d) => $d <= 0.02)),
            'median_diff_percent' => $diffs === [] ? null : round($diffs[intdiv(count($diffs), 2)] * 100, 2),
            'over_25_percent' => $outliers,
            'linked_without_tcgdex_price' => $gaps,
        ];
    }
}
