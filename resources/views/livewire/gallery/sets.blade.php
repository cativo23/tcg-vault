@use('App\Modules\Catalog\Support\Completion')
@use('App\Modules\Catalog\Support\PrintedStrip')
@use('App\Support\Money')

<div class="sts-page">
    <section class="sts-head" aria-labelledby="sts-title">
        <h1 id="sts-title" class="sts-title">Sets</h1>
        @if ($sets->isNotEmpty())
            <p class="sts-summary"><span class="sts-mono">{{ $sets->count() }}</span> {{ Str::plural('set', $sets->count()) }} <span class="sts-sep" aria-hidden="true">·</span> newest first</p>
        @endif
    </section>

    @if ($sets->isEmpty())
        <section class="sts-empty" aria-labelledby="sts-empty-t">
            <div class="sts-pockets" aria-hidden="true"><i></i><i></i><i></i></div>
            <h2 class="sts-empty-t" id="sts-empty-t">No sets yet</h2>
            <p>Sets appear here once at least one card from them is in the public collection.</p>
        </section>
    @else
        {{-- role="list": list-style: none makes WebKit/VoiceOver drop the list semantics otherwise --}}
        <ol class="sts-shelf" role="list">
            @foreach ($sets as $set)
                @php
                    $owned = (int) $set->owned_card_count;
                    $total = $totals[$set->id];
                    $pct = Completion::percent($owned, $total);
                    $code = $set->display_code;
                    $year = $set->released_on?->format('Y');
                    // The accessible name carries the headline value only; the visible row also lists other currencies.
                    $values = array_filter($setValues[$set->id]);
                    $head = array_key_first($values);
                    // Built here, not with @foreach in the markup: Livewire's morph markers would land inside the strip.
                    // Printed raw, so it may only ever hold the class constants and PrintedStrip's float percents, never catalogue text.
                    $runs = '';
                    foreach ($strips[$set->id] as $run) {
                        $class = $run['state'] === PrintedStrip::OWNED ? 'sts-own' : 'sts-miss';
                        $runs .= '<i class="'.$class.'" style="left: '.$run['left'].'%; width: '.$run['width'].'%"></i>';
                    }
                    $label = "{$set->name}, {$owned} of {$total} cards, {$pct}% collected".($head !== null ? ', '.Money::format($values[$head], $head).' owned' : '');
                @endphp
                <li class="sts-set" style="--i: {{ min($loop->index, 12) }}">
                    <a class="sts-card" href="{{ route('gallery.show', ['username' => $targetUser->username, 'setTcgdexId' => $set->tcgdex_id]) }}" aria-label="{{ $label }}">
                        <span class="sts-tab" aria-hidden="true"><span class="sts-tab-code">{{ $code }}</span>@if ($year) <span class="sts-mono">{{ $year }}</span>@endif</span>
                        <span class="sts-face">
                            <span class="sts-tray">
                                @if ($set->safe_logo_url)
                                    <img src="{{ $set->safe_logo_url }}" alt="" loading="lazy" decoding="async" data-optional>
                                @endif
                                <span class="sts-code">{{ $code }}</span>
                            </span>
                            <span class="sts-info" aria-hidden="true">
                                <span class="sts-name">{{ $set->name }}</span>
                                <span class="sts-series">{{ $set->series ?: 'Pokémon TCG' }} · {{ $code }}@if ($year) · {{ $year }}@endif</span>
                                <span class="sts-strip">{!! $runs !!}</span>
                                <span class="sts-row">
                                    <span>Completed <b>{{ $owned }} / {{ $total }} <small>{{ $pct }}%</small></b></span>
                                    <span class="sts-r">Owned value <b><x-value-totals :totals="$setValues[$set->id]" /></b></span>
                                </span>
                            </span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ol>
    @endif
</div>

@push('head')
    @vite('resources/css/sets.css')
@endpush
