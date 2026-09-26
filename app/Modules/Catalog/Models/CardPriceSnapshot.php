<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CardPriceSnapshot extends Model
{
    use HasFactory;

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

    /** @return BelongsTo<Card, $this> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
