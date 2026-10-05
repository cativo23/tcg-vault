<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Builder;
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
    /**
     * How many days of history a listing screen loads per card. Covers
     * Activity's value-over-time chart, which is the longest look back
     * any of them takes; the card detail page reads the full history.
     */
    public const RECENT_DAYS = 30;

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

    /**
     * Snapshots captured within the last RECENT_DAYS days, today included.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->where('captured_on', '>=', today()->subDays(self::RECENT_DAYS));
    }

    /** @return BelongsTo<Card, $this> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
