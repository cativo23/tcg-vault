<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tcgcsv;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Support\CardVariants;
use Illuminate\Support\Facades\Log;

/**
 * Works out which TCGplayer product each of a card's prints is, from what
 * tcgdex has told us, and stores it. Links are sticky: tcgdex serves
 * product ids inconsistently, so a print keeps its link until a method at
 * least as trusted disagrees (CardTcgplayerLink::TRUST).
 *
 * A link is only made for a product found in this run's prices, which
 * also gives its subtype and group.
 */
final class TcgplayerLinkDiscovery
{
    /** @param  array<int, array<string, TcgcsvPriceRow>>  $prices  productId → subType → row */
    public function discover(Card $card, array $prices): void
    {
        foreach ($this->candidates($card) as $variant => [$productId, $method]) {
            $subType = $this->subTypeFor($variant, $prices[$productId] ?? []);

            if ($subType === null) {
                continue;
            }

            $this->store($card, $variant, $productId, $subType, $prices[$productId][$subType]->groupId, $method);
        }
    }

    /**
     * Product ids per variant key, the more trusted method winning: what
     * tcgdex priced on TCGplayer, then what its catalog lists.
     *
     * @return array<string, array{int, string}>
     */
    private function candidates(Card $card): array
    {
        $candidates = [];
        $raw = is_array($card->raw) ? $card->raw : [];
        $specialEntries = array_column(CardVariants::specialPrints($raw), 'entry', 'key');

        foreach (is_array($raw['variants_detailed'] ?? null) ? $raw['variants_detailed'] : [] as $entry) {
            $productId = is_array($entry) ? ($entry['thirdParty']['tcgplayer'] ?? null) : null;
            if (! is_int($productId) || ($entry['size'] ?? 'standard') !== 'standard') {
                continue;
            }

            $key = array_search($entry, $specialEntries, true);
            if ($key === false) {
                // A plain entry, or a lone foil/stamped one that is the base print.
                $plain = empty($entry['foil']) && empty($entry['stamp']);
                $key = $plain || CardVariants::keyFor($entry) !== null
                    ? CardVariants::baseKey((string) ($entry['type'] ?? ''))
                    : null;
            }

            if (is_string($key)) {
                $candidates[$key] ??= [$productId, 'tcgdex-thirdparty'];
            }
        }

        // Queried, not the loaded relation: callers load snapshots through
        // a date window, and this needs every tcgdex-priced print.
        $priced = $card->priceSnapshots()->where('source', 'tcgplayer')->where('origin', 'tcgdex')->get()
            ->filter(fn (CardPriceSnapshot $s) => is_int($s->raw['productId'] ?? null))
            ->sortByDesc(fn (CardPriceSnapshot $s) => $s->capturedOnKey())
            ->unique('variant');

        foreach ($priced as $snapshot) {
            $productId = $snapshot->raw['productId'] ?? null;
            if (is_int($productId)) {
                $candidates[$snapshot->variant] = [$productId, 'tcgdex-price'];
            }
        }

        return $candidates;
    }

    /**
     * The product's subtype that prices this print: its only one, or the
     * one matching the key's base print. Null when the product isn't in
     * this run's prices or the subtype can't be told.
     *
     * @param  array<string, TcgcsvPriceRow>  $subTypes
     */
    private function subTypeFor(string $variant, array $subTypes): ?string
    {
        if (count($subTypes) === 1) {
            return array_key_first($subTypes);
        }

        $wanted = TcgcsvPriceParser::subTypeFor($variant);

        return $wanted !== null && isset($subTypes[$wanted]) ? $wanted : null;
    }

    private function store(Card $card, string $variant, int $productId, string $subType, int $groupId, string $method): void
    {
        $existing = CardTcgplayerLink::where('card_id', $card->id)->where('variant', $variant)->first();

        if ($existing !== null && CardTcgplayerLink::TRUST[$existing->method] > CardTcgplayerLink::TRUST[$method]) {
            return;
        }

        if ($existing !== null && ($existing->product_id !== $productId || $existing->sub_type !== $subType)) {
            Log::info('TCGplayer link changed', [
                'card' => $card->tcgdex_id, 'variant' => $variant,
                'from' => [$existing->product_id, $existing->sub_type, $existing->method],
                'to' => [$productId, $subType, $method],
            ]);
        }

        CardTcgplayerLink::updateOrCreate(
            ['card_id' => $card->id, 'variant' => $variant],
            ['product_id' => $productId, 'sub_type' => $subType, 'group_id' => $groupId, 'method' => $method],
        );
    }
}
