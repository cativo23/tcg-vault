<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\CardImageFallback;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Finds a card's image when tcgdex's API leaves `image` empty. For some
 * sets (MEP Black Star Promos, for one) the API returns no image for any
 * card even though the file is on tcgdex's asset server at the usual
 * address: /{language}/{series}/{set}/{number}/high.webp.
 *
 * The series is only in the set payload, so it is looked up once per set
 * and cached for a day (a set with none is remembered for ten minutes).
 * The built address is only returned after a HEAD request confirms the
 * file exists, so a card that really has no image keeps the app's
 * placeholder instead of a broken link. Network failures and error
 * responses return null, and redirects are not followed, so the check
 * never leaves tcgdex's asset host.
 */
final class TcgdexImageFallback implements CardImageFallback
{
    private const ASSET_HOST = 'https://assets.tcgdex.net';

    private const TIMEOUT_SECONDS = 5;

    private const SERIES_CACHE_SECONDS = 86400;

    private const NO_SERIES_CACHE_SECONDS = 600;

    /**
     * Set and card numbers are plain segments like "mep", "sv03.5" or
     * "TG01": the same shape the provider accepts for tcgdex ids, so no
     * lone ".", leading "-", ".." or trailing newline.
     */
    private const SEGMENT = '/^[a-z0-9]+(?:[.-][a-z0-9]+)*\z/i';

    public function __construct(private readonly string $apiBaseUrl) {}

    public function resolve(string $setTcgdexId, string $localId): ?string
    {
        if (! preg_match(self::SEGMENT, $setTcgdexId) || ! preg_match(self::SEGMENT, $localId)) {
            return null;
        }

        try {
            $series = $this->seriesOf($setTcgdexId);
            if ($series === null) {
                return null;
            }

            $url = sprintf('%s/%s/%s/%s/%s/high.webp', self::ASSET_HOST, $this->language(), $series, $setTcgdexId, $localId);

            return $this->http()->head($url)->successful() ? $url : null;
        } catch (ConnectionException) {
            return null;
        }
    }

    private function seriesOf(string $setTcgdexId): ?string
    {
        $key = 'tcgdex-series:'.$this->language().':'.$setTcgdexId;

        // '' marks "no series", which Cache::remember would not store.
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return $cached === '' ? null : $cached;
        }

        $response = $this->http()->get(rtrim($this->apiBaseUrl, '/')."/sets/{$setTcgdexId}");

        // A server error says nothing about the set, so it isn't remembered;
        // only a real answer (or a 404) is.
        if (! $response->successful() && $response->status() !== 404) {
            return null;
        }

        $series = $response->successful() ? $response->json('serie.id') : null;
        $series = is_string($series) && preg_match(self::SEGMENT, $series) ? $series : null;

        Cache::put($key, $series ?? '', $series === null ? self::NO_SERIES_CACHE_SECONDS : self::SERIES_CACHE_SECONDS);

        return $series;
    }

    /** The API base ends in the language, e.g. https://api.tcgdex.net/v2/en. */
    private function language(): string
    {
        return basename(rtrim($this->apiBaseUrl, '/'));
    }

    private function http(): PendingRequest
    {
        // IPv4 only, for the same broken-IPv6-route reason as the provider.
        return Http::timeout(self::TIMEOUT_SECONDS)->withoutRedirecting()->withOptions(['force_ip_resolve' => 'v4']);
    }
}
