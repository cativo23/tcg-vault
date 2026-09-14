<div class="max-w-5xl mx-auto py-10 px-4">
    <div class="mb-6">
        <h1 class="text-xl font-semibold" style="color: var(--ink)">{{ $set->name }}</h1>
        <div class="text-xs mb-3" style="color: var(--muted)">{{ $set->series }}</div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3 text-sm">
            <div>
                <div style="color: var(--muted)">Total cards</div>
                <div class="mono" style="color: var(--ink)">{{ $totalCount }}</div>
            </div>
            <div>
                <div style="color: var(--muted)">Most expensive</div>
                <div style="color: var(--ink)">
                    @if ($mostExpensive)
                        {{ $mostExpensive['card']->name }}
                        <span class="mono">({{ number_format($mostExpensive['snapshot']->market_minor / 100, 2) }} {{ $mostExpensive['snapshot']->currency }})</span>
                    @else
                        —
                    @endif
                </div>
            </div>
            <div>
                <div style="color: var(--muted)">Full set value</div>
                <div class="mono" style="color: var(--ink)">{{ number_format($fullSetValueMinor / 100, 2) }}</div>
            </div>
            <div>
                <div style="color: var(--muted)">You own</div>
                <div class="mono" style="color: var(--ink)">{{ $ownedCount }} / {{ $totalCount }}</div>
            </div>
        </div>

        <div class="w-full rounded-full h-1.5" style="background: var(--bone-2)">
            @php $pct = $totalCount > 0 ? (int) round(($ownedCount / $totalCount) * 100) : 0; @endphp
            <div class="h-1.5 rounded-full" style="background: var(--signal); width: {{ $pct }}%"></div>
        </div>
    </div>

    <div class="flex flex-wrap gap-3 mb-6">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search this set..."
               class="nw-input flex-1 min-w-[180px]">

        <select wire:model.live="sort" class="nw-input">
            <option value="number">Sort: Number</option>
            <option value="name">Sort: Name</option>
            <option value="rarity">Sort: Rarity</option>
            <option value="price">Sort: Price</option>
        </select>

        <select wire:model.live="rarityFilter" class="nw-input">
            <option value="">All rarities</option>
            @foreach ($rarities as $rarity)
                <option value="{{ $rarity }}">{{ $rarity }}</option>
            @endforeach
        </select>
    </div>

    <div class="grid gap-4" style="grid-template-columns: repeat(auto-fill, minmax(160px, 1fr))">
        @foreach ($priced as $p)
            @php
                $card = $p['card'];
                $ownedItem = $p['ownedItem'];
                $photoUrl = $ownedItem?->photo_path
                    ? \Illuminate\Support\Facades\Storage::disk('collection-photos')->url($ownedItem->photo_path)
                    : $card->official_image_url;
            @endphp
            <div class="nw-card p-2" style="{{ $ownedItem ? 'box-shadow: 0 0 0 2px var(--signal)' : '' }}">
                <x-card-image :url="$photoUrl" :name="$card->name" />
                <div class="text-sm font-medium mt-2" style="color: var(--ink)">{{ $card->name }}</div>
                <div class="mono text-xs" style="color: var(--muted)">{{ $card->local_id }}</div>
                @if ($p['snapshot'])
                    <div class="mono text-xs" style="color: var(--ink)">
                        {{ number_format($p['snapshot']->market_minor / 100, 2) }} {{ $p['snapshot']->currency }}
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
