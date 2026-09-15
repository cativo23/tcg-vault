<?php

declare(strict_types=1);

namespace App\Modules\Collection\Models;

use App\Modules\Catalog\Models\Card;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CollectionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'collection_id',
        'card_id',
        'card_tcgdex_id',
        'variant',
        'condition',
        'grade_company',
        'grade_value',
        'quantity',
        'notes',
        'photo_path',
        'needs_variant_review',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'needs_variant_review' => 'boolean',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
