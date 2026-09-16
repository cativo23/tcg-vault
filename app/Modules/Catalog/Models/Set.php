<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Set extends Model
{
    use HasFactory;

    protected $fillable = [
        'tcgdex_id',
        'name',
        'abbreviation',
        'series',
        'released_on',
        'card_count',
        'logo_url',
    ];

    protected function casts(): array
    {
        return [
            'released_on' => 'date',
            'card_count' => 'integer',
        ];
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }
}
