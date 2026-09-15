<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Collection\Data\MatchedImportLine;
use App\Modules\Collection\Data\ParsedImport;
use App\Modules\Collection\Data\UnmatchedImportLine;
use Illuminate\Support\Collection;

/**
 * Parses TCGplayer's Android-app collection/decklist export format (one
 * card per line: qty, name, optional " - <disambiguator>", bracketed set
 * code, local number) into matched tcgdex cards and unrecognized lines.
 * Pure — never touches the database, only reads from the Catalog via
 * CardCatalogProvider to validate each candidate card actually exists.
 */
final class TcgplayerImportParser
{
    private const LINE_PATTERN = '/^(?P<qty>\d+)\s+(?P<name>.+?)(?:\s+-\s+\S+)?\s+\[(?P<set>\w+)\]\s+(?P<local>\S+)$/';

    public function __construct(private readonly CardCatalogProvider $provider) {}

    public function parse(string $text): ParsedImport
    {
        $setMap = config('tcgvault.tcgplayer_set_map');

        /** @var array<string, array{qty: int, rawLine: string}> $candidates keyed by "{tcgdexSetId}-{localId}" */
        $candidates = [];
        $unmatched = [];

        $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];

        foreach ($lines as $rawLine) {
            $rawLine = trim($rawLine);
            if ($rawLine === '') {
                continue;
            }

            if (! preg_match(self::LINE_PATTERN, $rawLine, $m)) {
                $unmatched[] = new UnmatchedImportLine(rawLine: $rawLine, reason: 'unparsed');

                continue;
            }

            $qty = (int) $m['qty'];
            $setCode = $m['set'];
            $localRaw = explode('/', $m['local'])[0];

            $tcgdexSetId = $setMap[$setCode] ?? null;
            if ($tcgdexSetId === null) {
                $unmatched[] = new UnmatchedImportLine(rawLine: $rawLine, reason: 'unknown_set');

                continue;
            }

            // Both mapped sets (me05, mee) zero-pad to 3 digits; a future
            // mapping entry with a different width legitimately surfaces as
            // card_not_found below rather than silently mismatching.
            $localId = str_pad($localRaw, 3, '0', STR_PAD_LEFT);
            $tcgdexCardId = "{$tcgdexSetId}-{$localId}";

            if (isset($candidates[$tcgdexCardId])) {
                $candidates[$tcgdexCardId]['qty'] += $qty;
            } else {
                $candidates[$tcgdexCardId] = ['qty' => $qty, 'rawLine' => $rawLine];
            }
        }

        $matched = [];

        foreach ($candidates as $tcgdexCardId => $candidate) {
            try {
                $card = $this->provider->findCard($tcgdexCardId);
            } catch (CardNotFoundException) {
                $unmatched[] = new UnmatchedImportLine(rawLine: $candidate['rawLine'], reason: 'card_not_found');

                continue;
            }

            $matched[] = new MatchedImportLine(qty: $candidate['qty'], tcgdexId: $tcgdexCardId, name: $card->name);
        }

        return new ParsedImport(matched: Collection::make($matched), unmatched: Collection::make($unmatched));
    }
}
