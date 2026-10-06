<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tcgcsv;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

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

    /** When tcgcsv last rebuilt its data; a build is pulled once. */
    public function lastUpdated(): CarbonImmutable
    {
        return CarbonImmutable::parse(trim($this->http()->get('last-updated.txt')->throw()->body()));
    }

    /**
     * TCGplayer's Pokémon groups (sets), as tcgcsv lists them.
     *
     * @return list<array{groupId: int, name: string, abbreviation: ?string, isSupplemental: bool}>
     */
    public function groups(): array
    {
        $json = $this->http()->get('tcgplayer/3/groups')->throw()->json();
        $results = is_array($json) && is_array($json['results'] ?? null) ? $json['results'] : [];

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
            ->withOptions(['force_ip_resolve' => 'v4']);
    }
}
