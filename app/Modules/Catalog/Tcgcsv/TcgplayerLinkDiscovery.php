<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tcgcsv;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Support\CardVariants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Works out which TCGplayer product each of a card's prints is, from what
 * tcgdex has told us, and stores it. Links are sticky: tcgdex serves
 * product ids inconsistently, so a print keeps its link until a method at
 * least as trusted disagrees (CardTcgplayerLink::TRUST).
 *
 * A link is only made for a product found in this run's prices, within
 * the groups the card's own set pulls (or a group one of its links already
 * uses) — so one group's payload can never price another set's card.
 */
final class TcgplayerLinkDiscovery
{
    /**
     * @param  array<int, array<int, array<string, TcgcsvPriceRow>>>  $prices  groupId → productId → subType → row
     * @return int how many candidate prints were skipped for a subtype this app has no key for
     */
    public function discover(Card $card, array $prices): int
    {
        $products = $this->productsInGroupsOf($card, $prices);
        $skipped = 0;

        foreach ($this->candidates($card) as $variant => [$productId, $method]) {
            if (! isset($products[$productId])) {
                continue;
            }

            $subType = $this->subTypeFor($variant, $products[$productId]);

            if ($subType === null) {
                $skipped++;

                continue;
            }

            $this->store($card, $variant, $productId, $subType, $products[$productId][$subType]->groupId, $method);
        }

        return $skipped;
    }

    /**
     * The fetched products this card may link to: those in its set's
     * groups, or in a group one of its links already points at.
     *
     * @param  array<int, array<int, array<string, TcgcsvPriceRow>>>  $prices
     * @return array<int, array<string, TcgcsvPriceRow>>
     */
    private function productsInGroupsOf(Card $card, array $prices): array
    {
        $groups = DB::table('set_tcgplayer_groups')->where('set_id', $card->set_id)->pluck('group_id')
            ->merge(CardTcgplayerLink::where('card_id', $card->id)->whereNotNull('group_id')->pluck('group_id'))
            ->unique();

        $products = [];
        foreach ($groups as $group) {
            $products += $prices[(int) $group] ?? [];
        }

        return $products;
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
        // Matched by position, not by key: two entries can share a key.
        $specialPrints = CardVariants::specialPrints($raw);
        $specialEntries = array_column($specialPrints, 'entry');

        foreach (is_array($raw['variants_detailed'] ?? null) ? $raw['variants_detailed'] : [] as $entry) {
            $productId = is_array($entry) ? ($entry['thirdParty']['tcgplayer'] ?? null) : null;
            if (! is_int($productId) || ($entry['size'] ?? 'standard') !== 'standard') {
                continue;
            }

            $position = array_search($entry, $specialEntries, true);
            if ($position !== false) {
                $key = $specialPrints[$position]['key'];
            } else {
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

        // The newest tcgdex-priced TCGplayer product per print — one row
        // each, not the card's whole price history.
        $priced = $card->priceSnapshots()
            ->where('source', 'tcgplayer')->where('origin', 'tcgdex')
            ->whereNotNull('raw->productId')
            ->selectRaw("DISTINCT ON (variant) variant, raw->>'productId' AS product_id")
            ->orderBy('variant')->orderByDesc('captured_on')
            ->toBase()->get();

        foreach ($priced as $row) {
            if (is_numeric($row->product_id) && (int) $row->product_id > 0) {
                $candidates[(string) $row->variant] = [(int) $row->product_id, 'tcgdex-price'];
            }
        }

        return $candidates;
    }

    /**
     * The product's subtype that prices this print. A base print needs its
     * own subtype (Normal / Holofoil / Reverse Holofoil); a special print is
     * a product of its own, so its only subtype is it. Null otherwise —
     * e.g. an old set's "1st Edition Holofoil", which this app has no key for.
     *
     * @param  array<string, TcgcsvPriceRow>  $subTypes
     */
    private function subTypeFor(string $variant, array $subTypes): ?string
    {
        if (CardVariants::isSpecial($variant) && count($subTypes) === 1) {
            return (string) array_key_first($subTypes);
        }

        $wanted = TcgcsvPriceParser::subTypeFor($variant);

        return $wanted !== null && isset($subTypes[$wanted]) ? $wanted : null;
    }

    private function store(Card $card, string $variant, int $productId, string $subType, int $groupId, string $method): void
    {
        $existing = CardTcgplayerLink::where('card_id', $card->id)->where('variant', $variant)->first();

        if ($existing !== null && (CardTcgplayerLink::TRUST[$existing->method] ?? 0) > CardTcgplayerLink::TRUST[$method]) {
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
