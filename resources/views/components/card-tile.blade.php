@use('App\Modules\Catalog\Support\Rarity')
@use('App\Support\Money')
@use('Illuminate\Support\Facades\Storage')

@props([
    'card',              // App\Modules\Catalog\Models\Card with `set` loaded
    'snapshot' => null,  // ?CardPriceSnapshot — the resolved display price
    'delta' => null,     // ?PriceDelta — direction since the previous snapshot day
    'items' => null,     // owned copies (CollectionItem collection); null/empty = not in the collection
    'href',
    'index' => 0,
    'eager' => false,
    'showSet' => true,
])

@php
    $items = collect($items ?? []);
    $owned = $items->isNotEmpty();
    $quantity = (int) $items->sum('quantity');
    $graded = $items->first(fn ($i) => $i->grade_company !== null && $i->grade_value !== null);
    $photo = $items->first(fn ($i) => $i->photo_path !== null);
    $imageUrl = $photo ? Storage::disk('collection-photos')->url($photo->photo_path) : $card->official_image_url;
    $direction = $delta === null ? null : ($delta->isUp() ? 'up' : ($delta->isDown() ? 'down' : null));
@endphp

<div class="nw-slot" style="--i: {{ $index }}">
    <a href="{{ $href }}" class="nw-tile {{ $owned ? '' : 'ghost' }}" wire:navigate
       aria-label="{{ $card->name }}, number {{ $card->local_id }}{{ $card->set ? ', '.$card->set->name : '' }}{{ $owned ? '' : ', not in the collection' }}">
        <div class="chead">
            <span class="num">#{{ $card->local_id }}</span>
            @if ($graded)
                <span class="rar slab" title="Graded {{ $graded->grade_company }} {{ $graded->grade_value }}">{{ $graded->grade_company }} {{ $graded->grade_value }}</span>
            @elseif (Rarity::abbreviate($card->rarity) !== '')
                <span class="rar" title="{{ Rarity::label($card->rarity) }}">{{ Rarity::abbreviate($card->rarity) }}</span>
            @endif
        </div>

        <x-card-image :url="$imageUrl" :name="$card->name.' #'.$card->local_id" :eager="$eager" />

        <div class="cbody">
            <div class="cname">{{ $card->name }}</div>
            <div class="cset">
                <span class="truncate">{{ $showSet && $card->set ? $card->set->name : Rarity::label($card->rarity) }}</span>
                @if ($quantity > 1)
                    <span class="mono" style="color: var(--ink)" title="{{ $quantity }} copies">×{{ $quantity }}</span>
                @elseif (! $owned)
                    <span>Not owned</span>
                @endif
            </div>
        </div>

        <div class="ticker {{ $direction === 'up' ? 'up' : '' }}">
            @if ($snapshot && $snapshot->market_minor !== null)
                <span>{{ Money::format($snapshot->market_minor, $snapshot->currency) }}<span class="cur">{{ $snapshot->currency }}</span></span>
                @if ($direction === 'up')
                    <span class="arrow up" aria-label="Price up since the previous snapshot">&#9650;</span>
                @elseif ($direction === 'down')
                    <span class="arrow down" aria-label="Price down since the previous snapshot">&#9660;</span>
                @else
                    <span class="arrow" aria-hidden="true">&#9679;</span>
                @endif
            @else
                <span>No price</span>
                <span class="arrow" aria-hidden="true">&#9679;</span>
            @endif
        </div>
    </a>
</div>
