@use('App\Modules\Catalog\Support\CardImage')
@use('App\Modules\Catalog\Support\CardVariants')
@use('App\Modules\Catalog\Support\Rarity')
@use('App\Support\Money')

@php
    $headCurrency = array_key_first(array_filter($totals));
    $otherTotals = collect(array_filter($totals))->except($headCurrency);
    // A move's sign comes from Money::move (true minus for a fall, ± for none). Colour is the view's job:
    // a rise is the accent, a fall is --color-flat, nothing else is coloured.
@endphp

<div class="act-page">
    <section class="act-head" aria-labelledby="act-h">
        <h1 id="act-h" class="act-who">Activity</h1>
        @if ($headCurrency)
            <p class="act-summary"><span class="act-mono">{{ Money::format($totals[$headCurrency], $headCurrency) }}</span>@if ($updatedAt) on <span class="act-mono">{{ $updatedAt->format('j M') }}</span>@endif</p>
        @endif
        @if ($otherTotals->isNotEmpty())
            <p class="act-note">@foreach ($otherTotals as $currency => $minor)Also {{ Money::format($minor, $currency) }} priced in {{ $currency }}, not added to the total. @endforeach</p>
        @endif
    </section>

    <section class="act-history" aria-label="Collection value">
        <div class="nw-panel">
            <div class="head">
                <div>
                    <div class="k">Collection value</div>
                    <div class="v">
                        <x-value-totals :totals="$totals" empty="No prices yet" />
                        @if ($series->count() >= 2)
                            @php $first = $series->first()['minor']; $last = $series->last()['minor']; @endphp
                            <small class="{{ $last > $first ? 'up' : '' }}">{{ Money::signed($last - $first, $primaryCurrency) }} · {{ $chartRange->label() }}</small>
                        @endif
                    </div>
                    @if ($series->count() >= 2)
                        <div class="k mt-1">{{ $series->first()['date']->format('j M') }} – {{ $series->last()['date']->format('j M') }}</div>
                    @endif
                </div>
                <x-range-pills :active="$chartRange" label="Collection value range" />
            </div>
            <div class="nw-chart">
                @if ($series->count() >= 2)
                    <x-sparkline :points="$series->pluck('minor')->all()" :up="$last > $first" :height="150"
                        :dates="$series->pluck('date')->all()" :currency="$primaryCurrency"
                        :label="'Collection value over '.$chartRange->label().', from '.Money::format($first, $primaryCurrency).' to '.Money::format($last, $primaryCurrency)" />
                    @if ($historySince)
                        <p class="text-xs px-2" style="color: var(--muted)">History since <span class="mono">{{ $historySince->format('j M') }}</span></p>
                    @endif
                @else
                    <div class="h-16 flex items-center px-2 text-xs" style="color: var(--muted)">A value chart appears once two daily price snapshots exist.</div>
                @endif
            </div>
        </div>
    </section>

    <section class="act-feed" aria-labelledby="act-fd-h">
        <div class="act-sec-head">
            <h2 class="act-sec-t" id="act-fd-h">Latest · {{ $feedCount }} {{ Str::plural('update', $feedCount) }}</h2>
            <p class="act-sec-r">Price moves under $1 are not listed.</p>
        </div>

        @forelse ($days as $day)
            @php $dayKey = $day['date']->toDateString(); $dayCount = $day['entries']->count(); @endphp
            <section class="act-day" style="--i: {{ $loop->index }}" wire:key="day-{{ $dayKey }}">
                <h3 class="act-dtab" id="act-day-{{ $dayKey }}"><b>{{ $day['date']->format('j M') }}</b> <span>{{ $dayCount }} {{ Str::plural('update', $dayCount) }}</span></h3>
                {{-- role="list": list-style: none makes WebKit/VoiceOver drop the list semantics otherwise --}}
                <ul class="act-dsheet" role="list">
                    @foreach ($day['entries'] as $entry)
                        @php
                            $card = $entry['card'];
                            $href = $card && $card->set ? route('gallery.card', ['username' => $targetUser->username, 'setTcgdexId' => $card->set->tcgdex_id, 'localId' => $card->local_id]) : null;
                            $thumb = CardImage::sized($card?->official_image_url, false);
                        @endphp
                        <li class="act-row">
                            <span class="act-thumb">
                                @if ($thumb)<img src="{{ $thumb }}" alt="" width="46" height="64" loading="lazy" decoding="async" data-optional>@endif
                                @if ($entry['kind'] === 'added')<span class="act-plus" aria-hidden="true">+</span>@endif
                            </span>
                            @if ($entry['kind'] === 'move')
                                @php $delta = $entry['delta']; $up = $delta->isUp(); @endphp
                                <div class="act-body">
                                    <p class="act-rt">@if ($href)<a href="{{ $href }}">{{ $card->name }} <span class="act-mono">#{{ $card->local_id }}</span></a>@else{{ $card->name }} <span class="act-mono">#{{ $card->local_id }}</span>@endif <span class="act-verb">{{ $up ? 'went up' : 'went down' }}</span></p>
                                    <p class="act-rs">{{ Rarity::label($card->rarity) ?: 'Card' }} · {{ $card->set?->name }} · {{ $delta->latest->sourceLabel() }}</p>
                                </div>
                                <p class="act-amt {{ $up ? 'act-amt--up' : 'act-amt--down' }}">{{ Money::move($delta->deltaMinor, $delta->latest->currency) }} <small>now {{ Money::format($delta->latest->market_minor, $delta->latest->currency) }}</small></p>
                            @else
                                @php $item = $entry['item']; $snapshot = $entry['snapshot']; @endphp
                                <div class="act-body">
                                    <p class="act-rt"><span class="act-verb">Added</span> @if ($href)<a href="{{ $href }}">{{ $card->name }}</a>@else{{ $card?->name ?? 'a card' }}@endif @if ($item->quantity > 1)<span class="act-mono">×{{ $item->quantity }}</span>@endif</p>
                                    <p class="act-rs">{{ $card?->set?->name }} · {{ $item->grade_company ? $item->grade_company.' '.$item->grade_value : $item->condition }}@if ($item->variant) · {{ CardVariants::label($item->variant) }}@endif</p>
                                </div>
                                <p class="act-amt">@if ($snapshot && $snapshot->market_minor !== null){{ Money::format($snapshot->market_minor, $snapshot->currency) }} @endif<small title="{{ $entry['at']->toDayDateTimeString() }}">{{ $entry['at']->diffForHumans(short: true) }}</small></p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <div class="act-empty">
                <div class="act-pockets" aria-hidden="true"><i></i><i></i><i></i></div>
                <h3 class="act-empty-t">Nothing yet</h3>
                <p>Additions and price movements show up here as they happen.</p>
            </div>
        @endforelse
    </section>
</div>

@push('head')
    @vite('resources/css/activity.css')
@endpush
