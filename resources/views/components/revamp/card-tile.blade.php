@use('App\Modules\Catalog\Support\CardImage')
@use('App\Modules\Catalog\Support\CardVariants')
@use('App\Modules\Catalog\Support\Rarity')
@use('App\Support\Money')
@use('Illuminate\Support\Facades\Storage')

@props([
    'card',              // App\Modules\Catalog\Models\Card with `set` loaded
    'snapshot' => null,  // ?CardPriceSnapshot — the resolved display price
    'weekDelta' => null, // ?PriceDelta — move against the same print a week earlier
    'items' => null,     // owned copies (CollectionItem collection); null/empty = none
    'href',
    'index' => 0,        // position inside the batch that just loaded (entrance stagger)
    'eager' => false,
    'priority' => false, // fetchpriority=high: the first tile only
    'lead' => false,     // double-size pocket: high-resolution art, a string on the tag
])

@php
    $items = collect($items ?? []);
    $quantity = (int) $items->sum('quantity');
    // What marks an owned print as not the plain one (a Prize Pack's stamp), once per stamp.
    $stamps = $items->flatMap(fn ($i) => CardVariants::stampLabels($i->variant))->unique()->sort()->values();
    $stampText = $stamps->take(2)->implode(', ').($stamps->count() > 2 ? ' +'.($stamps->count() - 2) : '');
    $graded = $items->first(fn ($i) => $i->grade_company !== null && $i->grade_value !== null);
    $photo = $items->first(fn ($i) => $i->photo_path !== null);
    // The collector's own photo is always the primary image when it exists.
    $imageUrl = $photo ? Storage::disk('collection-photos')->url($photo->photo_path) : CardImage::sized($card->official_image_url, $lead);
    // A graded slab replaces the rarity chip entirely, so a slab gets no tier accent.
    $tier = $graded ? 'standard' : Rarity::tier($card->rarity);
    $abbreviation = Rarity::abbreviate($card->rarity);
    $priced = $snapshot !== null && $snapshot->market_minor !== null;
    $currency = $priced ? $snapshot->currency : null;
    $move = null;
    if ($priced && $weekDelta !== null) {
        $d = $weekDelta->deltaMinor;
        $move = [
            'up' => $d > 0,
            'text' => Money::move($d, $currency).' · 7d',
            'spoken' => $d > 0 ? 'up '.Money::format($d, $currency).' in 7 days'
                : ($d < 0 ? 'down '.Money::format(-$d, $currency).' in 7 days' : 'unchanged in 7 days'),
        ];
    }
    $parts = [$card->name, trim(($card->set?->name ?? '').' '.$card->local_id)];
    if ($quantity > 1) {
        $parts[] = $quantity.' copies';
    }
    if ($graded) {
        $parts[] = 'graded '.$graded->grade_company.' '.$graded->grade_value;
    }
    if ($stamps->isNotEmpty()) {
        $parts[] = $stamps->implode(', ');
    }
    $parts[] = $priced ? Money::format($snapshot->market_minor, $currency) : 'no price';
    if ($move) {
        $parts[] = $move['spoken'];
    }
    $label = implode(', ', $parts);
    $loading = $eager ? ($priority ? 'fetchpriority="high"' : '') : 'loading="lazy"';
@endphp

<li wire:key="tile-{{ $card->id }}" class="col-slot{{ $lead ? ' col-lead' : '' }} col-enter" style="--i: {{ $index }}">
    <a href="{{ $href }}" class="col-card col-tier-{{ $tier }}" aria-label="{{ $label }}">
        <span class="col-frame">
            @if ($tier === 'chase')
                <span class="col-ring" aria-hidden="true"></span>
            @endif
            <span class="col-pic{{ $imageUrl ? '' : ' col-pic--empty' }}">
                @if ($imageUrl)
                    <img src="{{ $imageUrl }}" alt="" width="245" height="342" decoding="async" data-name="{{ $card->name }}" {!! $loading !!}>
                @else
                    <b>{{ $card->name }}</b><span>No image</span>
                @endif
            </span>
        </span>
        <span class="col-tag" aria-hidden="true">
            @if ($lead)
                <svg class="col-string" viewBox="0 0 30 40"><path d="M3 38 C 1 26, 9 14, 20 2"/></svg>
            @endif
            <span class="col-tag-face">
                @if ($priced)
                    <span class="col-price">{{ Money::format($snapshot->market_minor, $currency) }}</span>
                    @if ($move)
                        <span class="col-delta{{ $move['up'] ? ' col-delta--up' : '' }}">{{ $move['text'] }}</span>
                    @endif
                @else
                    <span class="col-price">No price</span>
                @endif
            </span>
            <span class="col-tag-hole"></span>
        </span>
        <span class="col-meta" aria-hidden="true">
            <span class="col-nm">{{ $card->name }}</span>
            <span class="col-sub">
                <span class="col-mono">{{ $card->set?->abbreviation ? $card->set->abbreviation.' ' : '' }}{{ $card->local_id }}</span>
                @if ($graded)
                    <span class="col-slab">{{ $graded->grade_company }} {{ $graded->grade_value }}</span>
                @elseif ($abbreviation !== '')
                    <span class="col-rar{{ $tier !== 'standard' ? ' col-rar--'.$tier : '' }}" title="{{ Rarity::label($card->rarity) }}">{{ $abbreviation }}</span>
                @endif
                @if ($quantity > 1)
                    <span class="col-qty" title="{{ $quantity }} copies">×{{ $quantity }}</span>
                @endif
            </span>
            @if ($stamps->isNotEmpty())
                <span class="col-sub col-sub--stamp" title="{{ $stamps->implode(', ') }}">{{ $stampText }}</span>
            @endif
        </span>
    </a>
</li>
