<div class="nw-wrap">
    <section class="nw-masthead">
        <div class="nw-eyebrow"><span>{{ $targetUser->username }} · by official set</span></div>
        <h1 class="nw-display nw-h1 nw-h1--md">Sets</h1>
    </section>

    @if ($sets->isEmpty())
        <div class="nw-empty">
            <div class="t">No sets yet</div>
            <p>Sets appear here once at least one card from them is in the public collection.</p>
        </div>
    @else
        <div class="nw-setgrid">
            @foreach ($sets as $set)
                @php
                    $total = $set->card_count ?? $set->real_card_count;
                    $owned = $set->owned_card_count;
                    // Carlos flagged live (2026-09-15): owning 1 of 217
                    // cards rounds to 0% and renders an empty bar —
                    // visually indistinguishable from owning nothing.
                    // Never round a real, nonzero owned count down to 0.
                    $pct = $total > 0 ? max($owned > 0 ? 1 : 0, (int) round(($owned / $total) * 100)) : 0;
                @endphp
                <a href="{{ route('gallery.show', ['username' => $targetUser->username, 'setTcgdexId' => $set->tcgdex_id]) }}"
                   class="nw-setcard nw-slot" style="--i: {{ $loop->index }}" wire:navigate>
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
                        <div class="value">
                            <span class="k">Owned value</span>
                            <span class="v"><x-value-totals :totals="$setValues[$set->id]" /></span>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
