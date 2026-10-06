<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tcgcsv;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use UnexpectedValueException;

/**
 * tcgcsv.com's TCGplayer mirror: the daily build time and one group's
 * prices. Every request carries the custom User-Agent tcgcsv asks for;
 * callers fetch each group at most once per build.
 */
final class TcgcsvClient
{
    private const TIMEOUT = 8;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $userAgent,
        private readonly TcgcsvPriceParser $parser,
    ) {}

    /** Above this a response is not a price file; refuse it before buffering. */
    private const MAX_BODY_BYTES = 5_000_000;

    /**
     * When tcgcsv last rebuilt its data; a build is pulled once. Only the
     * exact ISO-8601 shape is accepted, so a stray body is never read as
     * "now" and never reaches a log line through a parse error.
     */
    public function lastUpdated(): CarbonImmutable
    {
        $body = trim($this->http()->get('last-updated.txt')->throw()->body());

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/', $body) !== 1) {
            throw new UnexpectedValueException('tcgcsv last-updated.txt is not an ISO-8601 timestamp.');
        }

        return CarbonImmutable::parse($body);
    }

    /**
     * TCGplayer's Pokémon groups (sets), as tcgcsv lists them.
     *
     * @return list<array{groupId: int, name: string, abbreviation: ?string, isSupplemental: bool}>
     */
    public function groups(): array
    {
        $json = $this->http()->get('tcgplayer/3/groups')->throw()->json();
        if (! is_array($json) || ($json['success'] ?? null) !== true || ! is_array($json['results'] ?? null)) {
            throw new UnexpectedValueException('tcgcsv groups response is not a successful list.');
        }
        $results = $json['results'];

        return array_values(array_map(fn (array $g) => [
            'groupId' => (int) $g['groupId'],
            'name' => (string) $g['name'],
            'abbreviation' => isset($g['abbreviation']) ? (string) $g['abbreviation'] : null,
            'isSupplemental' => (bool) ($g['isSupplemental'] ?? false),
        ], array_filter($results, fn (mixed $g) => is_array($g) && is_int($g['groupId'] ?? null) && is_string($g['name'] ?? null))));
    }

    /** @return list<TcgcsvPriceRow> */
    public function prices(int $groupId): array
    {
        return $this->parser->parse($groupId, $this->http()->get("tcgplayer/3/{$groupId}/prices")->throw()->json());
    }

    /**
     * IPv4 for the same reason as TcgdexCardCatalogProvider::http(): the
     * production container's IPv6 egress route is unreliable.
     */
    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout(self::TIMEOUT)
            ->withUserAgent($this->userAgent)
            ->withOptions([
                'force_ip_resolve' => 'v4',
                // A redirect could point the horizon container anywhere.
                'allow_redirects' => false,
                'on_headers' => function (ResponseInterface $response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BODY_BYTES) {
                        throw new UnexpectedValueException('tcgcsv response is larger than any price file.');
                    }
                },
            ]);
    }
}
