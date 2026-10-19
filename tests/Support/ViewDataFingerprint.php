<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Services\PriceDelta;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A plain-array fingerprint of a page's view data, for comparing what two
 * renders show. A model is its class and key (a price row also its price,
 * currency and day), so a card's loaded relations never take part: two
 * renders that loaded different price rows but show the same values match.
 * Not used by the app.
 */
final class ViewDataFingerprint
{
    public static function of(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            $value instanceof CardPriceSnapshot => ['snapshot', $value->getKey(), $value->market_minor, $value->currency, $value->capturedOnKey(), $value->source, $value->variant, $value->origin],
            $value instanceof Model => [class_basename($value), $value->getKey()],
            $value instanceof PriceDelta => ['delta', self::of($value->latest), self::of($value->previous), $value->deltaMinor],
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof BackedEnum => $value->value,
            $value instanceof Collection => self::of($value->all()),
            is_array($value) => array_map(self::of(...), $value),
            default => get_debug_type($value),
        };
    }
}
