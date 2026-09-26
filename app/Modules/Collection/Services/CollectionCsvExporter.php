<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;

/**
 * Writes the authenticated user's whole collection as CSV, one row per
 * collection item, with every field the item carries plus its current
 * market price for its own variant — the same price the collection page
 * shows. Scoped through Collection's TenantScope, so it only ever sees
 * the current user's items.
 */
final class CollectionCsvExporter
{
    public const HEADER = [
        'card_name', 'set_name', 'set_id', 'number', 'tcgdex_id', 'variant', 'condition',
        'grade_company', 'grade_value', 'quantity', 'notes', 'market_price', 'currency', 'price_date',
    ];

    public function __construct(private readonly CardPriceResolver $resolver = new CardPriceResolver) {}

    /**
     * @param  resource  $out
     */
    public function write($out): void
    {
        // A BOM so Excel reads the file as UTF-8 instead of mangling
        // accented names; other spreadsheet apps ignore it.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::HEADER, escape: '');

        CollectionItem::query()
            ->whereIn('collection_id', Collection::query()->select('id'))
            ->with(['card.set', 'card.priceSnapshots'])
            ->lazyById(200)
            ->each(fn (CollectionItem $item) => fputcsv($out, $this->row($item), escape: ''));
    }

    /**
     * @return array<int, string>
     */
    private function row(CollectionItem $item): array
    {
        $snapshot = $this->resolver->resolveForVariant($item->card, $item->variant);

        return array_map($this->neutraliseFormula(...), [
            $item->card->name,
            $item->card->set->name,
            $item->card->set->tcgdex_id,
            $item->card->local_id,
            $item->card_tcgdex_id,
            (string) $item->variant,
            $item->condition,
            (string) $item->grade_company,
            (string) $item->grade_value,
            (string) $item->quantity,
            (string) $item->notes,
            $snapshot?->market_minor !== null ? number_format($snapshot->market_minor / 100, 2, '.', '') : '',
            (string) $snapshot?->currency,
            (string) $snapshot?->captured_on?->toDateString(),
        ]);
    }

    /**
     * Spreadsheet apps run a cell starting with = + - @ (or a tab/CR that
     * precedes one) as a formula, so a note like =HYPERLINK(...) would
     * execute on open. A leading apostrophe makes it plain text.
     */
    private function neutraliseFormula(string $value): string
    {
        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }
}
