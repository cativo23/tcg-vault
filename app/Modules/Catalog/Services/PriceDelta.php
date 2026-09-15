<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\CardPriceSnapshot;

/**
 * The movement of one card's resolved price between its two most recent
 * snapshot days. Only ever built from two snapshots that share source,
 * variant, and currency — a "delta" across any of those would be a number
 * that looks like a price change but isn't one.
 */
final readonly class PriceDelta
{
    public function __construct(
        public CardPriceSnapshot $latest,
        public CardPriceSnapshot $previous,
        public int $deltaMinor,
    ) {}

    public function isUp(): bool
    {
        return $this->deltaMinor > 0;
    }

    public function isDown(): bool
    {
        return $this->deltaMinor < 0;
    }

    /** Percentage change against the previous price, or null when the previous price was zero. */
    public function percent(): ?float
    {
        if (($this->previous->market_minor ?? 0) === 0) {
            return null;
        }

        return round($this->deltaMinor / $this->previous->market_minor * 100, 1);
    }
}
