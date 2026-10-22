<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tcgcsv;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Support\CardVariants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
    /** @var array<int, array<int, int>> set id → its groups, looked up once per set per run */
    private array $setGroups = [];

    /**
     * @param  array<int, array<int, array<string, TcgcsvPriceRow>>>  $prices  groupId → productId → subType → row
     * @param  array<int, array<int, array{name: string, number: ?string}>>  $identities  groupId → productId → name and printed number, for the extra groups
     * @return int how many candidate prints were skipped because their product has no subtype that prices them
     */
    public function discover(Card $card, array $prices, array $identities = []): int
    {
        $products = $this->productsInGroupsOf($card, $prices);
        $skipped = 0;

        foreach ($this->candidates($card) as $variant => [$productId, $method, $ownProduct]) {
            // Checked on every run, not only when the link is made: once it
            // points at an extra group the product also turns up among the
            // card's own groups' products, and must not lose its verification.
            $subTypes = $ownProduct ? $this->extraGroupProduct($card, $productId, $prices, $identities) : null;
            $verified = $subTypes !== null;
            $subTypes ??= $products[$productId] ?? null;

            if ($subTypes === null) {
                continue;
            }

            $subType = $this->subTypeFor($variant, $subTypes);

            if ($subType === null) {
                $skipped++;

                continue;
            }

            $this->store($card, $variant, $productId, $subType, $subTypes[$subType]->groupId, $method, $verified);
        }

        return $skipped;
    }

    /**
     * The prices of a product in one of the extra groups (config
     * tcgcsv.extra_groups: groups of products that belong to many sets, such
     * as deck exclusives), only when its name and printed number are this
     * card's. A group's payload is otherwise never allowed to price another
     * set's card, and a product id tcgdex serves can be wrong.
     *
     * @param  array<int, array<int, array<string, TcgcsvPriceRow>>>  $prices
     * @param  array<int, array<int, array{name: string, number: ?string}>>  $identities
     * @return array<string, TcgcsvPriceRow>|null
     */
    private function extraGroupProduct(Card $card, int $productId, array $prices, array $identities): ?array
    {
        foreach (TcgcsvExtraGroups::ids() as $group) {
            $subTypes = $prices[$group][$productId] ?? null;
            $identity = $identities[$group][$productId] ?? null;

            if ($subTypes !== null && $identity !== null && $this->isPrintOf($card, $identity)) {
                return $subTypes;
            }
        }

        return null;
    }

    /**
     * Whether a product's printed number and name are the card's: the number
     * before the slash equals the card's local id, the total after it is the
     * set's printed total when the card says what that is, and the product's
     * name starts with the card's name as whole words ("Mew" is not "Mewtwo").
     *
     * @param  array{name: string, number: ?string}  $identity
     */
    private function isPrintOf(Card $card, array $identity): bool
    {
        if ($identity['number'] === null) {
            return false;
        }

        [$number, $total] = [...explode('/', $identity['number'], 2), ''];
        $printed = ltrim($number, '0');
        $name = $this->words($card->name);

        $official = is_array($card->raw) ? ($card->raw['set']['cardCount']['official'] ?? null) : null;
        $sameTotal = ! is_int($official) || $total === '' || ltrim($total, '0') === (string) $official;

        return $printed !== '' && $printed === ltrim($card->local_id, '0') && $sameTotal
            && $name !== '' && str_starts_with($this->words($identity['name']).' ', $name.' ');
    }

    /**
     * Lower-cased words without accents, separated by single spaces, so
     * punctuation, spacing and accent differences (TCGplayer spells
     * "Flabébé" as "Flabebe") don't matter.
     */
    private function words(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(mb_strtolower($text))));
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
        $this->setGroups[$card->set_id] ??= DB::table('set_tcgplayer_groups')->where('set_id', $card->set_id)
            ->pluck('group_id')->map(fn ($g) => (int) $g)->all();

        $groups = collect($this->setGroups[$card->set_id])
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
     * The third element is whether the print is the card's plain `normal`
     * print on a product of its own (its cardmarket product differs from the
     * card's top-level one) — the only kind an extra group may link.
     *
     * @return array<string, array{int, string, bool}>
     */
    private function candidates(Card $card): array
    {
        $candidates = [];
        /** @var array<int, true> $ownProducts the plain normal entries' own products, whichever method names them */
        $ownProducts = [];
        $raw = is_array($card->raw) ? $card->raw : [];
        // Matched by position, not by key: two entries can share a key.
        $specialPrints = CardVariants::specialPrints($raw);
        $specialEntries = array_column($specialPrints, 'entry');

        foreach (is_array($raw['variants_detailed'] ?? null) ? $raw['variants_detailed'] : [] as $entry) {
            $productId = is_array($entry) ? ($entry['thirdParty']['tcgplayer'] ?? null) : null;
            if (! is_int($productId) || ($entry['size'] ?? 'standard') !== 'standard') {
                continue;
            }

            $plain = empty($entry['foil']) && empty($entry['stamp']);
            $position = array_search($entry, $specialEntries, true);
            if ($position !== false) {
                $key = $specialPrints[$position]['key'];
            } else {
                // A plain entry, or a foil/stamped one specialPrints() treats as the base print.
                $key = $plain || CardVariants::keyFor($entry) !== null
                    ? CardVariants::baseKey((string) ($entry['type'] ?? ''))
                    : null;
            }

            if (is_string($key)) {
                $cardmarketProduct = $entry['thirdParty']['cardmarket'] ?? null;
                $mainProduct = $raw['pricing']['cardmarket']['idProduct'] ?? null;
                $ownProduct = $position === false && $plain && $key === 'normal'
                    && is_int($cardmarketProduct) && is_int($mainProduct) && $cardmarketProduct !== $mainProduct;

                if ($ownProduct) {
                    $ownProducts[$productId] = true;
                }
                $candidates[$key] ??= [$productId, 'tcgdex-thirdparty', $ownProduct];
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
                $candidates[(string) $row->variant] = [(int) $row->product_id, 'tcgdex-price', $row->variant === 'normal' && isset($ownProducts[(int) $row->product_id])];
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

    /** @param  bool  $verified  the product's name and number were checked against the card's (an extra group's product) */
    private function store(Card $card, string $variant, int $productId, string $subType, int $groupId, string $method, bool $verified = false): void
    {
        $existing = CardTcgplayerLink::where('card_id', $card->id)->where('variant', $variant)->first();

        if ($existing !== null && (CardTcgplayerLink::TRUST[$existing->method] ?? 0) > CardTcgplayerLink::TRUST[$method]) {
            return;
        }

        if ($existing !== null && ($existing->product_id !== $productId || $existing->sub_type !== $subType)) {
            Log::channel('tcgcsv')->info('TCGplayer link changed', [
                'card' => $card->tcgdex_id, 'variant' => $variant,
                'from' => [$existing->product_id, $existing->sub_type, $existing->method],
                'to' => [$productId, $subType, $method],
            ]);
        }

        CardTcgplayerLink::updateOrCreate(
            ['card_id' => $card->id, 'variant' => $variant],
            ['product_id' => $productId, 'sub_type' => $subType, 'group_id' => $groupId, 'method' => $method, 'verified_at' => $verified ? now() : null],
        );
    }
}
