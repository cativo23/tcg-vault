@props(['set', 'owned', 'total', 'href', 'index' => 0])

@php
    // A real, nonzero owned count never rounds down to 0%: an empty bar
    // would read as owning nothing.
    $pct = $total > 0 ? max($owned > 0 ? 1 : 0, (int) round(($owned / $total) * 100)) : 0;
@endphp

<a href="{{ $href }}" class="nw-setcard nw-slot" style="--i: {{ $index }}" wire:navigate>
    <div class="band">
        @if ($set->logo_url)
            <img src="{{ $set->logo_url }}" alt="{{ $set->name }} logo" loading="lazy" decoding="async" data-optional>
        @endif
        {{-- shown when there is no logo, or when the logo failed to load and was removed --}}
        <span class="placeholder">{{ $set->tcgdex_id }}</span>
    </div>
    <div class="body">
        <div class="name">{{ $set->name }}</div>
        <div class="series">{{ $set->series ?: 'Pokémon TCG' }} · {{ Str::upper($set->tcgdex_id) }}@if ($set->released_on) · {{ $set->released_on->format('Y') }}@endif</div>
        <div class="progress">
            <div class="row"><span>Completed</span><span class="n">{{ $owned }} / {{ $total }}</span></div>
            <div class="nw-bar" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $owned }}" aria-label="{{ $pct }}% of {{ $set->name }} collected"><i style="width: {{ $pct }}%"></i></div>
        </div>
        @isset($value)
            <div class="value">
                <span class="k">Owned value</span>
                <span class="v">{{ $value }}</span>
            </div>
        @endisset
    </div>
</a>
