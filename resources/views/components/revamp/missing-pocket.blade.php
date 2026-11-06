@use('App\Modules\Catalog\Support\CardImage')
@use('App\Support\Money')

@props([
    'card',              // a card of the set the collector does not own, with `set` loaded
    'snapshot' => null,  // ?CardPriceSnapshot — what the card costs, on the pocket's slip
    'href',
    'index' => 0,        // entrance stagger
])

@php
    $imageUrl = CardImage::sized($card->official_image_url, false);
    $price = $snapshot !== null && $snapshot->market_minor !== null ? Money::format($snapshot->market_minor, $snapshot->currency) : null;
    $label = implode(', ', array_filter([$card->name, '#'.$card->local_id, 'missing', $price]));
@endphp

<li wire:key="miss-{{ $card->id }}" class="col-slot set-miss col-enter" style="--i: {{ $index }}">
    <a href="{{ $href }}" class="set-pocket" aria-label="{{ $label }}">
        <span class="set-pocket-face">
            @if ($imageUrl)
                {{-- The card behind the plastic is decoration: a failed load simply disappears (app.js, data-optional). --}}
                <img src="{{ $imageUrl }}" alt="" width="245" height="342" decoding="async" loading="lazy" data-optional>
            @endif
            <span class="set-pencil" aria-hidden="true">{{ $card->local_id }}</span>
            @if ($price)
                <span class="set-gprice" aria-hidden="true">{{ $price }}</span>
            @endif
        </span>
        <span class="col-meta" aria-hidden="true">
            <span class="col-nm">{{ $card->name }}</span>
            <span class="col-sub"><span class="col-mono">#{{ $card->local_id }}</span><span>missing</span></span>
        </span>
    </a>
</li>
