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
final class TcgplayerIdentityMatcher
{
    /** Bumped when the rules change, so stored evidence says which rules made a link. */
    public const VERSION = 1;

    /** @var array<int, array<int, int>> set id → its groups, looked up once per set */
    private array $setGroups = [];

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
            // A group that failed this run has no products to judge by, and a
            // product in it could be the second match the rule must see.
            if (! isset($prices[$group], $identities[$group])) {
                return $refuse('group-without-identities');
            }
        }

        $printed = [];
        foreach ($groups as $group) {
            foreach ($prices[$group] as $productId => $subTypes) {
                $number = PrintedNumber::parse($identities[$group][$productId]['number'] ?? null);
                if ($number !== null) {
                    $printed[] = [$group, $productId, $subTypes, $identities[$group][$productId]['name'], (string) $identities[$group][$productId]['number'], $number];
                }
            }
        }

        $sameNumber = array_filter($printed, fn (array $p) => $p[5]->isNumber($card->local_id));
        $candidates = array_values(array_filter($sameNumber, fn (array $p) => $this->isSamePrint($card, $p[3], $p[4], $p[5])));

        $outcomes = [];
        foreach ($prints as $variant => $loneStamped) {
            $outcomes[] = $this->outcome($card, $variant, $loneStamped, $candidates, count($sameNumber));
        }

        return $outcomes;
    }

    /**
     * @param  list<array{int, int, array<string, TcgcsvPriceRow>, string, string, PrintedNumber}>  $candidates
     */
    private function outcome(Card $card, string $variant, bool $loneStamped, array $candidates, int $sharingNumber): IdentityOutcome
    {
        $refused = fn (string $reason) => new IdentityOutcome($card->tcgdex_id, $variant, IdentityOutcome::REFUSED, $reason);

        if (count($candidates) === 0) {
            return $refused('no-candidate');
        }
        if (count($candidates) > 1) {
            return $refused('ambiguous');
        }

        [$group, $productId, $subTypes, , $rawNumber, $number] = $candidates[0];

        // A lone stamped print can be told from a plain one only by its
        // product: when another priced product shares the number, the one
        // that matched may be the plain print's.
        if ($loneStamped && $sharingNumber !== 1) {
            return $refused('number-not-unique');
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
            'path' => 'identity', 'rule' => 'same-print', 'number' => $rawNumber, 'total' => $number->total,
            'candidates' => count($candidates), 'lone_stamped' => $loneStamped, 'matcher' => self::VERSION,
        ]);
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
    private function isSamePrint(Card $card, string $name, string $rawNumber, PrintedNumber $number): bool
    {
        $official = is_array($card->raw) ? ($card->raw['set']['cardCount']['official'] ?? null) : null;
        if (is_int($official) && $number->total !== null && ! $number->isTotal($official)) {
            return false;
        }

        $words = $this->words($name);
        $suffix = $this->words($rawNumber);
        if ($suffix !== '' && ($words === $suffix || str_ends_with($words, ' '.$suffix))) {
            $words = trim(substr($words, 0, -strlen($suffix)));
        }

        return $words !== '' && $words === $this->words($card->name);
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
