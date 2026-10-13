<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Providers\TcgdexCardCatalogProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Set extends Model
{
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

    /**
     * The stored logo url when it is on the tcgdex assets host, else null, so
     * a row written before that host check never renders a foreign image.
     */
    public function getSafeLogoUrlAttribute(): ?string
    {
        $logo = $this->logo_url;

        return is_string($logo) && str_starts_with($logo, TcgdexCardCatalogProvider::LOGO_URL_PREFIX) ? $logo : null;
    }

    /** @return HasMany<Card, $this> */
    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }
}
