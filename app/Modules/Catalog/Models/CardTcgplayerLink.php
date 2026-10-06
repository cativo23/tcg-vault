<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which TCGplayer product and price subtype a print is.
 *
 * @property int $card_id
 * @property string $variant
 * @property int $product_id
 * @property string $sub_type
 * @property int|null $group_id
 * @property string $method
 */
final class CardTcgplayerLink extends Model
{
    /** Higher wins: a link is only replaced by a method at least this trusted. */
    public const TRUST = [
        'tcgdex-thirdparty' => 1,
        'tcgdex-price' => 2,
        'admin' => 3,
    ];

    protected $fillable = ['card_id', 'variant', 'product_id', 'sub_type', 'group_id', 'method', 'verified_at'];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'group_id' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Card, $this> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
