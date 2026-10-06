<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tcgcsv;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

/**
 * tcgcsv.com's TCGplayer mirror: the daily build time and one group's
 * prices. Every request carries the custom User-Agent tcgcsv asks for;
 * callers fetch each group at most once per build.
 */
final class TcgcsvClient
{
    private const TIMEOUT = 8;

    /** Above this a response is not a price file; refused while reading, after decompression. */
    private const MAX_BODY_BYTES = 5_000_000;

    /** Reading a price file (~35 KB) never takes this long unless the connection is stalling. */
    private const BODY_DEADLINE_SECONDS = 30;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $userAgent,
        private readonly TcgcsvPriceParser $parser,
        private readonly float $bodyDeadlineSeconds = self::BODY_DEADLINE_SECONDS,
    ) {
        // Redirects are off, so a plain-http URL would only ever 301.
        if (! str_starts_with($baseUrl, 'https://')) {
            throw new InvalidArgumentException('The tcgcsv base URL must be https.');
        }
    }

    /**
     * When tcgcsv last rebuilt its data; a build is pulled once. Only the
     * exact ISO-8601 shape is accepted, so a stray body is never read as
     * "now" and never reaches a log line through a parse error.
     */
    public function lastUpdated(): CarbonImmutable
    {
        $body = trim($this->boundedBody('last-updated.txt'));

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/D', $body) !== 1) {
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
        $json = json_decode($this->boundedBody('tcgplayer/3/groups'), true);
        if (! is_array($json) || ($json['success'] ?? null) !== true || ! is_array($json['results'] ?? null)) {
            throw new UnexpectedValueException('tcgcsv groups response is not a successful list.');
        }
        $results = $json['results'];

        // Names reach a terminal (the proposal command): control characters
        // are stripped, not just console style tags.
        $plain = fn (string $text) => (string) preg_replace('/\p{Cc}/u', '', $text);

        return array_values(array_map(fn (array $g) => [
            'groupId' => $g['groupId'],
            'name' => $plain($g['name']),
            'abbreviation' => is_string($g['abbreviation'] ?? null) ? $plain($g['abbreviation']) : null,
            'isSupplemental' => ($g['isSupplemental'] ?? false) === true,
        ], array_filter($results, fn (mixed $g) => is_array($g) && is_int($g['groupId'] ?? null) && is_string($g['name'] ?? null))));
    }

    /** @return list<TcgcsvPriceRow> */
    public function prices(int $groupId): array
    {
        return $this->parser->parse($groupId, json_decode($this->boundedBody("tcgplayer/3/{$groupId}/prices"), true));
    }

    /**
     * The response body, read in chunks and refused past MAX_BODY_BYTES of
     * decompressed data — a Content-Length check would see only the
     * compressed size, or nothing on a chunked response.
     */
    private function boundedBody(string $path): string
    {
        $stream = $this->http()->withOptions(['stream' => true])->get($path)->throw()->toPsrResponse()->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        // A streamed body isn't covered by the request timeout, so the read
        // keeps its own deadline; a stall or a dropped connection mid-body
        // is a connection failure like any other.
        $deadline = microtime(true) + $this->bodyDeadlineSeconds;
        $body = '';

        try {
            while (! $stream->eof()) {
                $body .= $stream->read(65536);

                if (strlen($body) > self::MAX_BODY_BYTES) {
                    throw new UnexpectedValueException('tcgcsv response is larger than any price file.');
                }

                if (microtime(true) > $deadline) {
                    throw new ConnectionException('tcgcsv response took too long to read.');
                }
            }
        } catch (UnexpectedValueException|ConnectionException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new ConnectionException('tcgcsv response could not be read.', 0, $e);
        }

        return $body;
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
                // Per read on a streamed body, which `timeout` doesn't cover.
                'read_timeout' => self::TIMEOUT,
                // A redirect could point the horizon container anywhere.
                'allow_redirects' => false,
            ]);
    }
}
