<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;

final class CardPriceResolver
{
    public function resolve(Card $card): ?CardPriceSnapshot
    {
        $snapshots = $card->priceSnapshots()->orderByDesc('captured_on')->get();

        if ($snapshots->isEmpty()) {
            return null;
        }

        $tcgplayerPreferred = $snapshots->first(
            fn (CardPriceSnapshot $s) => $s->source === 'tcgplayer' && in_array($s->variant, ['normal', 'holofoil'], true),
        );

        if ($tcgplayerPreferred) {
            return $tcgplayerPreferred;
        }

        $cardmarketDefault = $snapshots->first(
            fn (CardPriceSnapshot $s) => $s->source === 'cardmarket' && $s->variant === 'default',
        );

        if ($cardmarketDefault) {
            return $cardmarketDefault;
        }

        return $snapshots->first();
    }
}
