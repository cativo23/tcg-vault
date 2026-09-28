<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

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
 * and cached for a day. The built address is only returned after a HEAD
 * request confirms the file exists, so a card that really has no image
 * keeps the app's placeholder instead of a broken link. Any failure
 * returns null: a missing image must never break a sync.
 */
final class TcgdexImageFallback
{
    private const ASSET_HOST = 'https://assets.tcgdex.net';

    private const TIMEOUT_SECONDS = 5;

    private const SERIES_CACHE_SECONDS = 86400;

    /** Set and card numbers are plain segments like "mep", "sv03.5" or "TG01". */
    private const SEGMENT = '/^[A-Za-z0-9.\-]+$/';

    public function __construct(private readonly string $apiBaseUrl) {}

    public function resolve(string $setTcgdexId, string $localId): ?string
    {
        if (! preg_match(self::SEGMENT, $setTcgdexId) || ! preg_match(self::SEGMENT, $localId)
            || str_contains($setTcgdexId, '..') || str_contains($localId, '..')) {
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
        return Cache::remember(
            'tcgdex-series:'.$this->language().':'.$setTcgdexId,
            self::SERIES_CACHE_SECONDS,
            function () use ($setTcgdexId): ?string {
                $response = $this->http()->get(rtrim($this->apiBaseUrl, '/')."/sets/{$setTcgdexId}");
                $series = $response->successful() ? $response->json('serie.id') : null;

                return is_string($series) && preg_match(self::SEGMENT, $series) ? $series : null;
            },
        );
    }

    /** The API base ends in the language, e.g. https://api.tcgdex.net/v2/en. */
    private function language(): string
    {
        return basename(rtrim($this->apiBaseUrl, '/'));
    }

    private function http(): PendingRequest
    {
        // IPv4 only, for the same broken-IPv6-route reason as the provider.
        return Http::timeout(self::TIMEOUT_SECONDS)->withOptions(['force_ip_resolve' => 'v4']);
    }
}
