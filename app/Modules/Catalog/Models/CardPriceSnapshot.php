<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The foreign keys behind these are NOT NULL and constrained, so the
 * related row always exists.
 *
 * @property-read Card $card
 */
final class CardPriceSnapshot extends Model
{
    protected $fillable = [
        'card_id',
        'source',
        'variant',
        'captured_on',
        'currency',
        'market_minor',
        'low_minor',
        'trend_minor',
        'raw',
        'source_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'captured_on' => 'date',
            'market_minor' => 'integer',
            'low_minor' => 'integer',
            'trend_minor' => 'integer',
            'raw' => 'array',
            'source_updated_at' => 'datetime',
        ];
    }

    /**
     * The capture day as a 'Y-m-d' string, read straight from the raw
     * attribute. Reading `captured_on` builds a fresh Carbon on every
     * access; CardPriceResolver sorts and filters on the day for every
     * snapshot of every card on a gallery page, so it compares these
     * keys instead (they order the same way the dates do). The slice
     * covers both raw shapes: 'Y-m-d' from the database, 'Y-m-d H:i:s'
     * on a model created in memory.
     */
    public function capturedOnKey(): string
    {
        return substr((string) $this->attributes['captured_on'], 0, 10);
    }

    /** @return BelongsTo<Card, $this> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
