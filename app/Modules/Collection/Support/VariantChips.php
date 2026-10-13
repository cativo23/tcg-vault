<?php

declare(strict_types=1);

namespace App\Modules\Collection\Support;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Support\CardVariants;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Support\Collection;

/**
 * The variants a card is shown with, each with how many copies the given
 * items hold. The count and the chip come from one pass over the items, so
 * they cannot disagree: every owned variant gets a chip, whether or not the
 * card's catalog data lists it, and every condition and grade of a variant
 * counts toward its chip.
 */
final class VariantChips
{
    /**
     * @param  Collection<int, CollectionItem>  $items
     * @return list<array{variant: ?string, label: string, count: int}>
     */
    public static function for(Card $card, Collection $items): array
    {
        $offered = CardVariants::available($card->variants ?? [], $card->raw ?? []);

        $perVariant = $items
            ->groupBy(fn (CollectionItem $item): string => (string) $item->variant)
            ->map(fn ($group): int => (int) $group->sum('quantity'));

        $unspecified = (int) ($perVariant[''] ?? 0);

        $chips = [];

        // A card with no known prints keeps one plain chip for copies
        // without a variant; on a card with prints, those copies only get a
        // chip once they exist.
        if ($offered === []) {
            $chips[] = ['variant' => null, 'label' => '', 'count' => $unspecified];
        }

        foreach ($offered as $variant) {
            $chips[] = ['variant' => $variant, 'label' => CardVariants::label($variant), 'count' => (int) ($perVariant[$variant] ?? 0)];
        }

        // Owned prints the card's data does not list (hand-entered custom prints).
        $extras = $perVariant->except(array_merge($offered, ['']))->sortKeys();

        foreach ($extras as $variant => $count) {
            $chips[] = ['variant' => (string) $variant, 'label' => CardVariants::label((string) $variant), 'count' => $count];
        }

        if ($offered !== [] && $unspecified > 0) {
            $chips[] = ['variant' => null, 'label' => 'Unspecified', 'count' => $unspecified];
        }

        return $chips;
    }
}
