@use('App\Modules\Catalog\Support\Rarity')
@use('App\Support\Money')
@use('Illuminate\Support\Facades\Storage')

@php
    $card = $entry['card'];
    $owned = $entry['owned'];
    $snapshot = $entry['snapshot'];
    $photo = $card->collectionItems->first(fn ($item) => $item->photo_path !== null);
    $imageUrl = $photo ? Storage::disk('collection-photos')->url($photo->photo_path) : $card->official_image_url;
    $rarityTier = Rarity::tier($card->rarity);
@endphp

<div class="nw-slot" style="--i: {{ min($index, 11) }}" wire:key="tile-{{ $card->id }}">
    <div class="nw-tile {{ $owned ? '' : 'ghost' }} {{ $rarityTier !== 'standard' ? 'rarity-'.$rarityTier : '' }}" data-tile="{{ $card->id }}">
        <div class="chead">
            <span class="num">#{{ $card->local_id }}</span>
            @if (Rarity::abbreviate($card->rarity) !== '')
                <span class="rar {{ $rarityTier !== 'standard' ? 'rarity-'.$rarityTier : '' }}" title="{{ Rarity::label($card->rarity) }}">{{ Rarity::abbreviate($card->rarity) }}</span>
            @endif
        </div>

        <x-card-image :url="$imageUrl" :name="$card->name.' #'.$card->local_id" :eager="$index < 6" />

        <div class="cbody">
            <div class="cname">{{ $card->name }}</div>
            <div class="cset">
                <span class="truncate">{{ Rarity::label($card->rarity) }}</span>
                <span class="mono" data-tile-qty style="color: var(--ink)" @if ($entry['quantity'] < 1) hidden @endif>×{{ $entry['quantity'] }}</span>
                <span data-tile-missing @if ($entry['quantity'] > 0) hidden @endif>Not owned</span>
            </div>
        </div>

        <div class="ticker">
            @if ($snapshot && $snapshot->market_minor !== null)
                <span>{{ Money::format($snapshot->market_minor, $snapshot->currency) }}<span class="cur">{{ $snapshot->currency }}</span></span>
            @else
                <span>No price</span>
            @endif
        </div>
    </div>
</div>
