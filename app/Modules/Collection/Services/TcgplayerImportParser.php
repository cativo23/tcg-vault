<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Catalog\Models\Card;
use App\Modules\Collection\Data\MatchedImportLine;
use App\Modules\Collection\Data\ParsedImport;
use App\Modules\Collection\Data\UnmatchedImportLine;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Parses TCGplayer's Android-app collection/decklist export format (one
 * card per line: qty, name, optional " - <disambiguator>", bracketed set
 * code, local number) into matched tcgdex cards and unrecognized lines.
 * Read-only — never writes. It resolves each candidate card against the
 * local Catalog first and only falls back to the remote CardCatalogProvider
 * for cards this install has never synced.
 */
final class TcgplayerImportParser
{
    // The `name` group is intentionally decorative: it only exists so the
    // pattern can consume the card name (and its optional " - <disambiguator>"
    // suffix) before the bracketed set code. The name we display always comes
    // from tcgdex/the local Catalog, never from the export text — don't
    // "fix" this by reading $m['name'].
    private const LINE_PATTERN = '/^(?P<qty>[1-9]\d*)\s+(?P<name>.+?)(?:\s+-\s+\S+)?\s+\[(?P<set>\w+)\]\s+(?P<local>\S+)$/';

    public function __construct(private readonly CardCatalogProvider $provider) {}

    public function parse(string $text): ParsedImport
    {
        $setMap = config('tcgvault.tcgplayer_set_map', []);

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
            // Cards this install already synced are resolved locally, which
            // removes one tcgdex round-trip per line. On a re-import of an
            // already-synced set that's the entire preview cost gone.
            $localCard = Card::where('tcgdex_id', $tcgdexCardId)->first(['id', 'name']);

            if ($localCard !== null) {
                $variantCount = $localCard->priceSnapshots()->distinct('variant')->count('variant');

                $matched[] = new MatchedImportLine(
                    qty: $candidate['qty'],
                    tcgdexId: $tcgdexCardId,
                    name: $localCard->name,
                    variantAmbiguous: $variantCount > 1,
                );

                continue;
            }

            try {
                $card = $this->provider->findCard($tcgdexCardId);
            } catch (CardNotFoundException) {
                $unmatched[] = new UnmatchedImportLine(rawLine: $candidate['rawLine'], reason: 'card_not_found');

                continue;
            } catch (Throwable $e) {
                // A transient catalog failure (timeout, 5xx, malformed body)
                // must degrade to "this one line didn't resolve" instead of
                // 500-ing the whole preview — same posture as
                // AddCollectionItem::runSearch().
                report($e);

                $unmatched[] = new UnmatchedImportLine(rawLine: $candidate['rawLine'], reason: 'lookup_failed');

                continue;
            }

            $distinctVariants = collect($card->prices)->pluck('variant')->unique()->count();

            $matched[] = new MatchedImportLine(
                qty: $candidate['qty'],
                tcgdexId: $tcgdexCardId,
                name: $card->name,
                variantAmbiguous: $distinctVariants > 1,
            );
        }

        return new ParsedImport(matched: Collection::make($matched), unmatched: Collection::make($unmatched));
    }
}
