<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Card extends Model
{
    use HasFactory;

    protected $fillable = [
        'tcgdex_id',
        'set_id',
        'local_id',
        'name',
        'rarity',
        'variants',
        'official_image_url',
        'raw',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'variants' => 'array',
            'raw' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function set(): BelongsTo
    {
        return $this->belongsTo(Set::class);
    }

    public function priceSnapshots(): HasMany
    {
        return $this->hasMany(CardPriceSnapshot::class);
    }

    public function collectionItems(): HasMany
    {
        return $this->hasMany(CollectionItem::class);
    }
}
