<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The foreign keys behind these are NOT NULL and constrained, so the
 * related row always exists.
 *
 * @property-read Card $card
 */
final class CardPriceSnapshot extends Model
{
    /**
     * How far back any screen looks for a card's CURRENT price: listings
     * load only this window, and the card page prices from it too, so a
     * card shows the same price everywhere. Activity's value chart spans
     * the same window; the card page's sparkline still reads the full
     * history.
     */
    public const RECENT_DAYS = 30;

    protected $fillable = [
        'card_id',
        'source',
        'origin',
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

    /** How this price reached the app: synced from tcgdex or tcgcsv, or typed in. */
    public function originLabel(): string
    {
        return match ($this->origin) {
            'hand' => 'entered by hand',
            default => (string) $this->origin,
        };
    }

    /**
     * One line on where this price came from, for a tooltip: the
     * marketplace and how it reached the app, or that it was typed in —
     * and from which feed, when a manual price was copied from one.
     */
    public function provenanceLabel(): string
    {
        if ($this->source === 'manual') {
            $copiedFrom = is_array($this->raw) ? ($this->raw['origin'] ?? null) : null;

            return is_string($copiedFrom)
                ? "Manual price copied by hand from TCGplayer ({$copiedFrom})"
                : 'Manual price '.$this->originLabel();
        }

        return $this->sourceLabel().' market price via '.$this->originLabel();
    }

    /** Where this price came from, as a collector reads it. */
    public function sourceLabel(): string
    {
        return match ($this->source) {
            'tcgplayer' => 'TCGplayer',
            'cardmarket' => 'Cardmarket',
            'manual' => 'Manual',
            default => Str::headline($this->source),
        };
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

    /** The first day of the recent window: RECENT_DAYS before today, so the window holds RECENT_DAYS + 1 days. */
    public static function recentFrom(): CarbonInterface
    {
        return today()->subDays(self::RECENT_DAYS);
    }

    /**
     * A manual price counts as recent at any age: tcgdex rows are written
     * daily, but a manual one is written once and stands until replaced —
     * dropping it after the window would leave its print unpriced.
     */
    public function isRecent(): bool
    {
        return $this->source === 'manual' || $this->capturedOnKey() >= self::recentFrom()->toDateString();
    }

    /**
     * Snapshots captured on or after recentFrom(), plus every manual one
     * (see isRecent()).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('captured_on', '>=', self::recentFrom())
            ->orWhere('source', 'manual'));
    }

    /** @return BelongsTo<Card, $this> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
