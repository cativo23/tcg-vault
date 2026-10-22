<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tcgcsv;

use App\Modules\Catalog\Contracts\LinkedCardImage;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Support\CardVariants;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * A card's image from its TCGplayer product, for cards tcgdex has no image
 * for. The product is the one the card's links agree on: only the plain
 * prints' links count (a stamped print's product shows a different card
 * face), the most trusted method decides, and a card whose remaining links
 * name more than one product gets none rather than a guess. A link only
 * tcgdex's third-party ids vouch for is enough: unlike a price, a picture
 * is not held against another source, so those cards' names should be
 * compared with their products' before the backfill is written.
 *
 * The address is built from the integer product id alone and is only
 * returned after a HEAD request confirms the CDN serves an image there (a
 * missing product answers 403), so a card with no picture keeps the app's
 * placeholder. Redirects are not followed, and a failed request returns
 * null.
 */
final class TcgplayerProductImage implements LinkedCardImage
{
    private const IMAGE_URL = 'https://tcgplayer-cdn.tcgplayer.com/product/%d_in_1000x1000.jpg';

    private const TIMEOUT_SECONDS = 5;

    public function resolve(Card $card): ?string
    {
        $productId = $this->productFor($card);
        if ($productId === null) {
            return null;
        }

        $url = sprintf(self::IMAGE_URL, $productId);

        try {
            $response = $this->http()->head($url);
        } catch (ConnectionException) {
            return null;
        }

        return $response->status() === 200 && str_starts_with(strtolower($response->header('Content-Type')), 'image/') ? $url : null;
    }

    private function productFor(Card $card): ?int
    {
        $links = CardTcgplayerLink::where('card_id', $card->id)->get(['variant', 'product_id', 'method'])
            ->reject(fn (CardTcgplayerLink $link) => CardVariants::isSpecial($link->variant));

        $trust = $links->max(fn (CardTcgplayerLink $link) => CardTcgplayerLink::TRUST[$link->method] ?? 0);
        $products = $links->filter(fn (CardTcgplayerLink $link) => (CardTcgplayerLink::TRUST[$link->method] ?? 0) === $trust)
            ->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values();

        return $products->count() === 1 && $products[0] > 0 ? $products[0] : null;
    }

    private function http(): PendingRequest
    {
        // IPv4 only, for the same broken-IPv6-route reason as the tcgdex lookups.
        return Http::timeout(self::TIMEOUT_SECONDS)->withoutRedirecting()->withOptions(['force_ip_resolve' => 'v4']);
    }
}
