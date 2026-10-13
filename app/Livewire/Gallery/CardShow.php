<?php

declare(strict_types=1);

namespace App\Livewire\Gallery;

use App\Livewire\Gallery\Concerns\ResolvesPublicCollection;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Support\CardVariants;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\Valuation;
use App\Modules\Collection\Support\VariantChips;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
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

    /** URL value for copies stored without a variant. */
    public const UNSPECIFIED = 'unspecified';

    public Card $card;

    /**
     * The pill the visitor picked. Untrusted (it comes from the URL, where
     * `?variant[]=x` arrives as an array, hence the union): selectedPill()
     * only ever compares a string against this card's own pill keys.
     *
     * @var string|array<array-key, mixed>
     */
    #[Url(as: 'variant', except: '')]
    public string|array $variant = '';

    public function selectVariant(string $key): void
    {
        $this->variant = $key;
    }

    public function mount(string $username, string $setTcgdexId, string $localId): void
    {
        $this->resolveTargetUser($username);

        $set = Set::where('tcgdex_id', $setTcgdexId)->first();

        if ($set === null || ! $this->publicCollection()->ownsSet($set)) {
            throw new NotFoundHttpException;
        }

        $card = $set->cards()->where('local_id', $localId)->first();

        if ($card === null) {
            throw new NotFoundHttpException;
        }

        $this->card = $card;
    }

    public function render(): View
    {
        $public = $this->publicCollection();
        $resolver = new CardPriceResolver;
        $valuation = new Valuation($resolver);

        $card = $this->card->load([
            'set',
            'priceSnapshots',
            'collectionItems' => fn ($q) => $public->scopeItems($q)->orderBy('created_at'),
        ]);

        $items = $card->collectionItems;
        // Prices come from the same recent window the listings load, so
        // this page never shows a different price (or currency) for the
        // card than its grid tile does. Only the sparkline reads the full
        // history.
        $priced = (clone $card)->setRelation(
            'priceSnapshots',
            $card->priceSnapshots->filter(fn (CardPriceSnapshot $s) => $s->isRecent())->values(),
        );
        // A card with no known prints gets an empty unlabeled chip for loose
        // copies; once an owned print sits beside it, that placeholder is
        // not a choice worth offering.
        $chips = collect(VariantChips::for($card, $items));
        $chips = $chips->count() > 1 ? $chips->reject(fn (array $chip): bool => $chip['variant'] === null && $chip['count'] === 0) : $chips;

        $pills = $chips->map(fn (array $chip): array => [
            'key' => $chip['variant'] ?? self::UNSPECIFIED,
            'variant' => $chip['variant'],
            'label' => $chip['label'] === '' ? 'Unspecified' : $chip['label'],
            'count' => $chip['count'],
            'snapshot' => $resolver->resolveForVariant($priced, $chip['variant']),
        ]);
        $switcher = $pills->count() > 1;
        $selected = $switcher ? $this->selectedPill($pills, $valuation->headlineItem($priced)) : null;

        // The collector's own copy, at the price of the variant it
        // actually is — not resolve()'s card-level priority chain, which
        // would never even consider e.g. a reverse-holofoil copy. With a
        // switcher, the selected pill's price instead.
        $snapshot = $selected !== null ? $selected['snapshot'] : $valuation->headlineSnapshot($priced);
        $delta = $resolver->deltaFor($priced, $snapshot);
        $history = $resolver->historyFor($card, $snapshot);
        $ownedTotal = $items->isNotEmpty() ? $valuation->cardTotal($priced) : null;
        $quantity = (int) $items->sum('quantity');

        // Latest reading per source+variant — the full market picture the
        // grid tile only summarises. Sorted so the resolved one leads.
        $marketReads = $priced->priceSnapshots
            ->filter(fn (CardPriceSnapshot $s) => $s->market_minor !== null)
            ->filter(fn (CardPriceSnapshot $s) => $selected === null || $this->readBelongsTo($s, $selected['variant'], $card) || $this->isSameRead($s, $snapshot))
            ->groupBy(fn (CardPriceSnapshot $s) => $s->source.'|'.$s->variant)
            ->map(fn ($group) => $group->sortByDesc(fn (CardPriceSnapshot $s) => $s->capturedOnKey())->first())
            ->sortBy(fn (CardPriceSnapshot $s) => $snapshot && $s->is($snapshot) ? 0 : 1)
            ->values();

        $shownItems = $selected === null
            ? $items
            : $items->filter(fn ($item) => (string) $item->variant === (string) $selected['variant'])->values();

        $photos = $items
            ->filter(fn ($item) => $item->photo_path !== null)
            ->map(fn ($item) => [
                'url' => Storage::disk('collection-photos')->url((string) $item->photo_path),
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
            ->with(['set', 'priceSnapshots' => fn ($q) => $q->recent(), 'collectionItems' => fn ($q) => $public->scopeItems($q)])
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
            'items' => $shownItems,
            'owned' => $items->isNotEmpty(),
            'quantity' => $quantity,
            'pills' => $pills,
            'switcher' => $switcher,
            'selected' => $selected,
            'snapshot' => $snapshot,
            'delta' => $delta,
            'history' => $history,
            'ownedTotal' => $ownedTotal,
            'marketReads' => $marketReads,
            'images' => $images,
            'related' => $related,
            'facts' => $this->facts($raw),
            // Where the marketplace prices on show came from (tcgdex,
            // tcgcsv), for the header; a manual tile says so itself.
            'marketOrigins' => $marketReads
                ->reject(fn (CardPriceSnapshot $s) => $s->source === 'manual')
                ->pluck('origin')
                ->unique()
                ->sort()
                ->values(),
            // The day a marketplace last priced this card. A read behind it is
            // from a source that stopped updating; manual prices don't
            // count, since they are entered once and stand.
            'newestMarketDay' => $priced->priceSnapshots
                ->reject(fn (CardPriceSnapshot $s) => $s->source === 'manual' || $s->market_minor === null)
                ->max(fn (CardPriceSnapshot $s) => $s->capturedOnKey()),
            // Rows older than the window are not "no price yet" — the page
            // must not claim the card was never priced.
            'pricedBeforeWindow' => $marketReads->isEmpty()
                && $card->priceSnapshots->contains(fn (CardPriceSnapshot $s) => $s->market_minor !== null),
            'recentDays' => CardPriceSnapshot::RECENT_DAYS,
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
            'isOwner' => $this->isOwnerViewing(),
        ]);
    }

    /**
     * The pill to show: the one the URL names if this card has it; else the
     * collector's best copy's variant (what the set grid prices the tile
     * at); else the priciest pill; else the first.
     *
     * @param  Collection<int, array{key: string, variant: ?string, label: string, count: int, snapshot: ?CardPriceSnapshot}>  $pills
     * @return array{key: string, variant: ?string, label: string, count: int, snapshot: ?CardPriceSnapshot}
     */
    private function selectedPill(Collection $pills, ?CollectionItem $best): array
    {
        $byKey = $pills->keyBy('key');

        $named = is_string($this->variant) ? $byKey->get($this->variant) : null;

        if ($named !== null) {
            return $named;
        }

        if ($best !== null) {
            $own = $byKey->get(($best->variant === null || $best->variant === '') ? self::UNSPECIFIED : $best->variant);

            if ($own !== null) {
                return $own;
            }
        }

        $priced = $pills->filter(fn (array $pill) => $pill['snapshot']?->market_minor !== null);

        return $priced->isNotEmpty()
            ? $priced->sortByDesc(fn (array $pill) => $pill['snapshot']->market_minor)->firstOrFail()
            : $pills->firstOrFail();
    }

    /**
     * Whether a read is the one the headline price came from. A variant can
     * borrow the card-wide 'default' row, and that read stays listed even
     * though it is not one of the variant's own.
     */
    private function isSameRead(CardPriceSnapshot $read, ?CardPriceSnapshot $headline): bool
    {
        return $headline !== null && $read->source === $headline->source && $read->variant === $headline->variant;
    }

    /**
     * Whether a market read belongs under a pill. The card-wide cardmarket
     * 'default' row stands for the normal print, or the holo on a card with
     * no normal print — the same prints the resolver lets it price — and
     * never for a reverse or a special print. Unspecified copies are priced
     * by the card-level chain, which reads every base print.
     */
    private function readBelongsTo(CardPriceSnapshot $read, ?string $variant, Card $card): bool
    {
        if ($variant === null) {
            return ! CardVariants::isSpecial($read->variant);
        }

        if ($read->variant === 'default') {
            $offered = CardVariants::available($card->variants ?? [], $card->raw ?? []);
            $home = in_array('normal', $offered, true) ? 'normal' : (in_array('holofoil', $offered, true) ? 'holofoil' : null);

            return $variant === $home;
        }

        return $read->variant === $variant;
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
