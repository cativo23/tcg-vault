<?php

declare(strict_types=1);

namespace App\Livewire\Gallery;

use App\Livewire\Gallery\Concerns\ResolvesPublicCollection;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Collection\Services\Valuation;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One card, presented as an object: the collector's own photo (or the
 * official art), the market read across every source, the copies they
 * hold, and the card's own facts from the catalog payload.
 *
 * Reachable for every synced card in a set the collector shows publicly,
 * owned or not — the set screen links to both, and a "not in the
 * collection" page is honest context, not a dead end.
 */
#[Layout('layouts.public')]
final class CardShow extends Component
{
    use ResolvesPublicCollection;

    public Card $card;

    public function mount(string $username, string $setTcgdexId, string $localId): void
    {
        $this->resolveTargetUser($username);

        $set = Set::where('tcgdex_id', $setTcgdexId)->first();

        if ($set === null || ! $this->publicCollection()->ownsSet($set)) {
            throw new NotFoundHttpException();
        }

        $card = $set->cards()->where('local_id', $localId)->first();

        if ($card === null) {
            throw new NotFoundHttpException();
        }

        $this->card = $card;
    }

    public function render()
    {
        $public = $this->publicCollection();
        $resolver = new CardPriceResolver();
        $valuation = new Valuation($resolver);

        $card = $this->card->load([
            'set',
            'priceSnapshots',
            'collectionItems' => fn ($q) => $public->scopeItems($q)->orderBy('created_at'),
        ]);

        $items = $card->collectionItems;
        $snapshot = $resolver->resolve($card);
        $delta = $resolver->resolveDelta($card);
        $history = $resolver->history($card);
        $ownedTotal = $items->isNotEmpty() ? $valuation->cardTotal($card) : null;

        // Latest reading per source+variant — the full market picture the
        // grid tile only summarises. Sorted so the resolved one leads.
        $latestDay = $card->priceSnapshots->max(fn (CardPriceSnapshot $s) => $s->captured_on->toDateString());
        $marketReads = $card->priceSnapshots
            ->filter(fn (CardPriceSnapshot $s) => $s->market_minor !== null)
            ->groupBy(fn (CardPriceSnapshot $s) => $s->source.'|'.$s->variant)
            ->map(fn ($group) => $group->sortByDesc(fn (CardPriceSnapshot $s) => $s->captured_on->toDateString())->first())
            ->sortBy(fn (CardPriceSnapshot $s) => $snapshot && $s->is($snapshot) ? 0 : 1)
            ->values();

        $photos = $items
            ->filter(fn ($item) => $item->photo_path !== null)
            ->map(fn ($item) => [
                'url' => Storage::disk('collection-photos')->url($item->photo_path),
                'label' => trim(($item->grade_company ? "{$item->grade_company} {$item->grade_value}" : $item->condition)),
            ])
            ->values();

        // The collector's own photograph is the primary image whenever one
        // exists — that is the thing nobody else can show.
        $images = $photos->map(fn ($p) => $p + ['kind' => 'photo'])->all();
        if ($card->official_image_url) {
            $images[] = ['url' => $card->official_image_url, 'label' => 'Official art', 'kind' => 'official'];
        }

        $related = $card->set->cards()
            ->whereKeyNot($card->id)
            ->whereHas('collectionItems', fn ($q) => $public->scopeItems($q))
            ->with(['set', 'priceSnapshots', 'collectionItems' => fn ($q) => $public->scopeItems($q)])
            ->take(8)
            ->get()
            ->map(fn (Card $c) => [
                'card' => $c,
                'snapshot' => $resolver->resolve($c),
                'delta' => $resolver->resolveDelta($c),
                'items' => $c->collectionItems,
            ]);

        $raw = $card->raw ?? [];
        $name = $this->collectorName();

        return view('livewire.gallery.card', [
            'items' => $items,
            'owned' => $items->isNotEmpty(),
            'quantity' => (int) $items->sum('quantity'),
            'snapshot' => $snapshot,
            'delta' => $delta,
            'history' => $history,
            'ownedTotal' => $ownedTotal,
            'marketReads' => $marketReads,
            'images' => $images,
            'related' => $related,
            'facts' => $this->facts($raw),
            'priceUpdatedAt' => $latestDay,
        ])->layoutData([
            'title' => "{$card->name} #{$card->local_id} · {$card->set->name}",
            'description' => sprintf(
                '%s #%s from %s%s — %s.',
                $card->name,
                $card->local_id,
                $card->set->name,
                $card->rarity ? ", {$card->rarity}" : '',
                $items->isNotEmpty() ? "in {$name}'s collection" : "not yet in {$name}'s collection",
            ),
            'ogImage' => $images[0]['url'] ?? null,
        ]);
    }

    /**
     * The card's own facts, read straight from the cached tcgdex payload
     * (`cards.raw`) so no schema change is needed to show them. Only
     * keys that are present make it to the page.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, string>
     */
    private function facts(array $raw): array
    {
        $facts = [];

        if (! empty($raw['illustrator'])) {
            $facts['Illustrator'] = (string) $raw['illustrator'];
        }
        if (! empty($raw['types']) && is_array($raw['types'])) {
            $facts['Type'] = implode(' / ', array_map('strval', $raw['types']));
        }
        if (! empty($raw['hp'])) {
            $facts['HP'] = (string) $raw['hp'];
        }
        if (! empty($raw['stage'])) {
            $facts['Stage'] = (string) $raw['stage'];
        }
        if (! empty($raw['dexId']) && is_array($raw['dexId'])) {
            $facts['Pokédex'] = implode(', ', array_map(fn ($n) => '#'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), $raw['dexId']));
        }
        if (! empty($raw['regulationMark'])) {
            $facts['Regulation mark'] = (string) $raw['regulationMark'];
        }
        if (isset($raw['legal']) && is_array($raw['legal'])) {
            $legal = array_keys(array_filter($raw['legal']));
            if ($legal !== []) {
                $facts['Legal in'] = implode(', ', array_map('ucfirst', $legal));
            }
        }

        return $facts;
    }
}
