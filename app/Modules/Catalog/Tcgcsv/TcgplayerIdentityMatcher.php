<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tcgcsv;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Support\CardVariants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds the TCGplayer product of a card's base prints from what the product
 * itself says — its printed number and its name — instead of from what tcgdex
 * claims. Deliberately strict: a wrong link silently misprices a print and
 * persists in its price history, while a missing one shows as a price in the
 * wrong currency and is reported, so anything short of exactly one product
 * with the card's number, set total and exact name is refused.
 *
 * Only a card's base prints (normal, holofoil, reverse-holofoil, or its lone
 * stamped print) are judged, only for standard-size cards, and only among the
 * priced products of the groups its own set maps — never an extra group, never
 * a group only an existing link uses. It decides nothing and writes nothing:
 * each outcome says what a link would be, and how it compares with the link
 * the print already has.
 */
class TcgplayerIdentityMatcher
{
    /** Bumped when the rules change, so stored evidence says which rules made a link. */
    public const VERSION = 1;

    /** @var array<int, array<int, int>> set id → its groups, looked up once per set */
    private array $setGroups = [];

    /** @var array<int, array{products: array<int, array{string, string, ?PrintedNumber}>, byNumber: array<string, list<int>>, unreadable: list<int>}> group id → its products indexed by number */
    private array $indexes = [];

    /**
     * @param  array<int, array<int, array<string, TcgcsvPriceRow>>>  $prices  groupId → productId → subType → row
     * @param  array<int, array<int, array{name: string, number: ?string}>>  $identities  groupId → productId → name and printed number
     * @return list<IdentityOutcome>
     */
    public function evaluate(Card $card, array $prices, array $identities): array
    {
        $raw = is_array($card->raw) ? $card->raw : [];
        $prints = $this->basePrints($card, $raw);

        if ($prints === []) {
            return [];
        }

        $refuse = fn (string $reason) => array_map(
            fn (string $variant) => new IdentityOutcome($card->tcgdex_id, $variant, IdentityOutcome::REFUSED, $reason),
            array_keys($prints),
        );

        if (! $this->isStandardSize($raw)) {
            return $refuse('not-standard-size');
        }

        $groups = $this->groupsOf($card);
        foreach ($groups as $group) {
            // A group whose products could not be read this run has nothing to
            // judge by, and a product in it could be the second match the rule
            // must see. A group with products and no prices is read as usual.
            if (! isset($identities[$group])) {
                return $refuse('group-without-identities');
            }
        }

        // Every product of the groups takes part, priced or not: an unpriced
        // twin is still a second match. A product with no readable number is a
        // possible match when its name is the card's.
        $sameNumber = [];
        $candidates = [];
        $name = $this->words($card->name);
        $wanted = strtolower((string) PrintedNumber::parse($card->local_id)?->number);
        foreach ($groups as $group) {
            $index = $this->indexOf($group, $identities[$group]);
            foreach ($index['byNumber'][$wanted] ?? [] as $productId) {
                $sameNumber[] = $productId;
                [$productName, , $number] = $index['products'][$productId];
                if ($number !== null && $this->isSamePrint($card, $productName, $number)) {
                    $candidates[] = ['group' => $group, 'product' => $productId, 'readable' => true];
                }
            }
            foreach ($index['unreadable'] as $productId) {
                if ($this->words($index['products'][$productId][0]) === $name) {
                    $candidates[] = ['group' => $group, 'product' => $productId, 'readable' => false];
                }
            }
        }

        $outcomes = [];
        foreach ($prints as $variant => $loneStamped) {
            $outcomes[] = $this->outcome($card, $variant, $loneStamped, $candidates, count($sameNumber), $prices, $identities);
        }

        return $outcomes;
    }

    /**
     * @param  list<array{group: int, product: int, readable: bool}>  $candidates
     * @param  array<int, array<int, array<string, TcgcsvPriceRow>>>  $prices
     * @param  array<int, array<int, array{name: string, number: ?string}>>  $identities
     */
    private function outcome(Card $card, string $variant, bool $loneStamped, array $candidates, int $sharingNumber, array $prices, array $identities): IdentityOutcome
    {
        $refused = fn (string $reason) => new IdentityOutcome($card->tcgdex_id, $variant, IdentityOutcome::REFUSED, $reason);

        if (count($candidates) > 1) {
            return $refused('ambiguous');
        }
        if (count($candidates) === 0 || ! $candidates[0]['readable']) {
            return $refused('no-candidate');
        }

        ['group' => $group, 'product' => $productId] = $candidates[0];

        // A lone stamped print can be told from a plain one only by its
        // product: when another product shares the number, the one that
        // matched may be the plain print's.
        if ($loneStamped && $sharingNumber !== 1) {
            return $refused('number-not-unique');
        }

        $subTypes = $prices[$group][$productId] ?? [];
        if ($subTypes === []) {
            return $refused('no-price');
        }

        $subType = $this->subTypeFor($variant, $subTypes, $loneStamped);
        if ($subType === null) {
            return $refused('no-subtype');
        }

        $existing = CardTcgplayerLink::where('card_id', $card->id)->where('variant', $variant)->first();
        $status = match (true) {
            $existing === null => IdentityOutcome::NEW,
            $existing->product_id === $productId && $existing->sub_type === $subType => IdentityOutcome::AGREE,
            default => IdentityOutcome::DIFFERENT,
        };

        return new IdentityOutcome($card->tcgdex_id, $variant, $status, null, $productId, $subType, $group, [
            'path' => 'identity', 'rule' => 'same-print', 'number' => $identities[$group][$productId]['number'],
            'total' => PrintedNumber::parse($identities[$group][$productId]['number'])?->total,
            'candidates' => 1, 'lone_stamped' => $loneStamped, 'matcher' => self::VERSION,
        ]);
    }

    /**
     * A group's products by printed number, parsed once per run however many
     * cards are judged against it.
     *
     * @param  array<int, array{name: string, number: ?string}>  $products
     * @return array{products: array<int, array{string, string, ?PrintedNumber}>, byNumber: array<string, list<int>>, unreadable: list<int>}
     */
    private function indexOf(int $group, array $products): array
    {
        if (isset($this->indexes[$group])) {
            return $this->indexes[$group];
        }

        $index = ['products' => [], 'byNumber' => [], 'unreadable' => []];
        foreach ($products as $productId => $product) {
            $number = PrintedNumber::parse($product['number']);
            $index['products'][$productId] = [$product['name'], (string) $product['number'], $number];
            if ($number === null) {
                $index['unreadable'][] = $productId;
            } else {
                $index['byNumber'][strtolower($number->number)][] = $productId;
            }
        }

        return $this->indexes[$group] = $index;
    }

    /**
     * The print keys this matcher judges, each with whether it is the card's
     * lone stamped print: the base prints tcgdex lists, never the special
     * ones, which are products of their own.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, bool>
     */
    private function basePrints(Card $card, array $raw): array
    {
        $lone = $this->loneStampedKey($raw);
        $prints = [];

        foreach (CardVariants::available($card->variants ?? [], $raw) as $key) {
            if (! CardVariants::isSpecial($key)) {
                $prints[$key] = $key === $lone;
            }
        }

        return $prints;
    }

    /**
     * The base key of the card's only standard print when that print is
     * stamped or foiled yet stands as the base print on the card's own
     * Cardmarket product (a promo whose single entry carries its stamp).
     *
     * @param  array<string, mixed>  $raw
     */
    private function loneStampedKey(array $raw): ?string
    {
        $entries = is_array($raw['variants_detailed'] ?? null) ? array_values(array_filter($raw['variants_detailed'], 'is_array')) : [];
        $standard = array_values(array_filter($entries, fn (array $e) => ($e['size'] ?? 'standard') === 'standard'));

        if (count($standard) !== 1) {
            return null;
        }

        $entry = $standard[0];
        $plain = empty($entry['foil']) && empty($entry['stamp']);
        $key = CardVariants::baseKey((string) ($entry['type'] ?? ''));
        $cardmarket = $entry['thirdParty']['cardmarket'] ?? null;
        $main = $raw['pricing']['cardmarket']['idProduct'] ?? null;

        return ! $plain && ! in_array($entry, array_column(CardVariants::specialPrints($raw), 'entry'), true)
            && in_array($key, ['normal', 'holofoil'], true) && is_int($cardmarket) && is_int($main) && $cardmarket === $main
            ? $key
            : null;
    }

    /**
     * A card with no standard-size entry (a jumbo) has products no base
     * rule can tell apart. Old payloads without entries count as standard.
     *
     * @param  array<string, mixed>  $raw
     */
    private function isStandardSize(array $raw): bool
    {
        $entries = is_array($raw['variants_detailed'] ?? null) ? array_filter($raw['variants_detailed'], 'is_array') : [];

        return $entries === [] || array_filter($entries, fn (array $e) => ($e['size'] ?? 'standard') === 'standard') !== [];
    }

    /** @return array<int, int> */
    private function groupsOf(Card $card): array
    {
        return $this->setGroups[$card->set_id] ??= DB::table('set_tcgplayer_groups')->where('set_id', $card->set_id)
            ->pluck('group_id')->map(fn ($g) => (int) $g)->all();
    }

    /**
     * Whether a product with this printed number is the card: the set total
     * after the slash is the set's official count when both are known, and
     * the product's name, without its printed number, is the card's name.
     */
    private function isSamePrint(Card $card, string $name, PrintedNumber $number): bool
    {
        $official = is_array($card->raw) ? ($card->raw['set']['cardCount']['official'] ?? null) : null;
        if (is_int($official) && $number->total !== null && ! $number->isTotal($official)) {
            return false;
        }

        $words = $this->withoutNumber($this->words($name), $number);

        return $words !== '' && $words === $this->words($card->name);
    }

    /**
     * The words of a product name without its trailing printed number, in
     * whatever padding TCGplayer wrote it ("Pikachu - 58/102" for 058/102).
     */
    private function withoutNumber(string $words, PrintedNumber $number): string
    {
        $tokens = $words === '' ? [] : explode(' ', $words);

        foreach ([2, 1] as $count) {
            if (count($tokens) < $count) {
                continue;
            }
            $tail = PrintedNumber::parse(implode('/', array_slice($tokens, -$count)));
            if ($tail !== null && strtolower($tail->number) === strtolower($number->number) && ($number->total === null || $tail->total === null || strtolower($tail->total) === strtolower((string) $number->total))) {
                return implode(' ', array_slice($tokens, 0, -$count));
            }
        }

        return $words;
    }

    /**
     * The product's subtype that prices this print: a base print needs its
     * own (Normal / Holofoil / Reverse Holofoil), a lone stamped print takes
     * the only one its product has.
     *
     * @param  array<string, TcgcsvPriceRow>  $subTypes
     */
    private function subTypeFor(string $variant, array $subTypes, bool $loneStamped): ?string
    {
        if ($loneStamped && count($subTypes) === 1) {
            return (string) array_key_first($subTypes);
        }

        $wanted = TcgcsvPriceParser::subTypeFor($variant);

        return $wanted !== null && isset($subTypes[$wanted]) ? $wanted : null;
    }

    /** Lower-cased words without accents, so punctuation and spacing don't matter. */
    private function words(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(mb_strtolower($text))));
    }
}
