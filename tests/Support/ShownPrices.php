<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Livewire\Gallery\Index;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Livewire\Livewire;

/**
 * What a visitor to a public gallery is shown, for tests that check a price
 * write reaches the next render through the gallery's cached price rows.
 * Not used by the app.
 */
final class ShownPrices
{
    /** Puts one copy of $card in a new user's public collection and returns their username. */
    public static function ownPublicly(Card $card, ?string $variant): string
    {
        $user = User::factory()->create();
        $collection = Collection::factory()->for($user)->create(['is_public' => true]);
        CollectionItem::create([
            'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => $card->tcgdex_id,
            'variant' => $variant, 'condition' => 'NM', 'quantity' => 1,
        ]);

        return (string) $user->username;
    }

    /**
     * The gallery home's collection value, by currency.
     *
     * @return array<string, int>
     */
    public static function totals(string $username): array
    {
        return Livewire::test(Index::class, ['username' => $username])->viewData('totals');
    }
}
