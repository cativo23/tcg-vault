@use('App\Modules\Catalog\Support\CardImage')
@use('App\Modules\Catalog\Support\CardVariants')
@use('App\Modules\Catalog\Support\ChartRange')
@use('App\Modules\Catalog\Support\Rarity')
@use('App\Modules\Collection\Support\ValueChart')
@use('App\Support\DayLabel')
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
            <p class="act-summary"><span class="act-mono">{{ Money::format($totals[$headCurrency], $headCurrency) }}</span>@if ($updatedAt) on <span class="act-mono">{{ DayLabel::of($updatedAt) }}</span>@endif</p>
        @endif
        @if ($otherTotals->isNotEmpty())
            <p class="act-note">@foreach ($otherTotals as $currency => $minor)Also {{ Money::format($minor, $currency) }} priced in {{ $currency }}, not added to the total. @endforeach</p>
        @endif
    </section>

    <section class="act-history" aria-labelledby="act-cv-h" data-act-history>
        <div class="act-tabs" role="tablist" aria-label="Collection value range" data-act-tabs>
            @foreach (ChartRange::cases() as $option)
                @php $isOn = $option === $chartRange; @endphp
                <button type="button" role="tab" id="act-tab-{{ $option->value }}" class="act-tab" aria-selected="{{ $isOn ? 'true' : 'false' }}" tabindex="{{ $isOn ? 0 : -1 }}" aria-controls="act-chart-panel" wire:click="selectRange('{{ $option->value }}')" wire:key="range-{{ $option->value }}">{{ $option->button() }}</button>
            @endforeach
        </div>
        <div class="act-sheet act-chart-sheet" role="tabpanel" id="act-chart-panel" aria-labelledby="act-tab-{{ $chartRange->value }}">
            <div class="act-ch-head">
                <div>
                    <h2 class="act-k" id="act-cv-h">Collection value · {{ $chartRange->label() }}</h2>
                    @if ($chart)
                        @php $first = $series->first()['minor']; $last = $series->last()['minor']; @endphp
                        <p class="act-v">{{ Money::format($last, $primaryCurrency) }} <small class="act-move{{ $chart->up ? ' act-move--up' : '' }}">{{ Money::move($last - $first, $primaryCurrency) }} · {{ $chartRange->label() }}</small></p>
                        <p class="act-span act-mono">{{ DayLabel::of($series->first()['date']) }} – {{ DayLabel::of($series->last()['date']) }}</p>
                    @else
                        <p class="act-v">{{ $headCurrency ? Money::format($totals[$headCurrency], $headCurrency) : 'No prices yet' }}</p>
                    @endif
                </div>
                {{-- The slot shows pointer and keyboard readings to the eye; only a keyboard reading reaches a screen reader, through the slider's aria-valuetext, so a mouse sweep does not flood it. --}}
                <p class="act-read act-mono" data-act-read aria-hidden="true"></p>
            </div>
            <div class="act-plot">
                @if ($chart)
                    @php
                        // The slider rests on the newest day; its value text is the same words the script writes for a reading.
                        $lastIndex = count($chart->points) - 1;
                        $restText = $chart->points[$lastIndex]['d'].' · '.$chart->points[$lastIndex]['v'];
                        $highLineY = round($chart->highY * ValueChart::HEIGHT / 100, 1);
                        $lowLineY = round($chart->lowY * ValueChart::HEIGHT / 100, 1);
                    @endphp
                    {{-- A slider's children are presentational, so the drawing is described here and the slider points at it. --}}
                    <p class="sr-only" id="act-chart-sum">Collection value over {{ $chartRange->label() }}, from {{ Money::format($first, $primaryCurrency) }} to {{ Money::format($last, $primaryCurrency) }}</p>
                    <div class="act-chart" tabindex="0" role="slider" aria-label="Value by day" aria-valuemin="0" aria-valuemax="{{ $lastIndex }}" aria-valuenow="{{ $lastIndex }}" aria-valuetext="{{ $restText }}" aria-describedby="act-chart-sum" data-act-chart data-points='@json($chart->points)'>
                        <svg class="act-svg" viewBox="0 0 {{ ValueChart::WIDTH }} {{ ValueChart::HEIGHT }}" preserveAspectRatio="none" aria-hidden="true">
                            <line class="act-grid" x1="0" x2="{{ ValueChart::WIDTH }}" y1="{{ $highLineY }}" y2="{{ $highLineY }}"/>
                            <line class="act-grid" x1="0" x2="{{ ValueChart::WIDTH }}" y1="{{ $lowLineY }}" y2="{{ $lowLineY }}"/>
                            <path class="act-area" d="{{ $chart->area }}"/>
                            <path class="act-line" d="{{ $chart->line }}"/>
                        </svg>
                        <span class="act-ylab act-mono" style="top: {{ $chart->highY }}%" aria-hidden="true">{{ $chart->high }}</span>
                        <span class="act-ylab act-mono" style="top: {{ $chart->lowY }}%" aria-hidden="true">{{ $chart->low }}</span>
                        <span class="act-cross" aria-hidden="true"></span>
                        <span class="act-dot" aria-hidden="true"></span>
                        <span class="act-end{{ $chart->up ? ' act-end--up' : '' }}" style="left: 100%; top: {{ $chart->endY }}%" aria-hidden="true"></span>
                        <span class="act-cover" aria-hidden="true"></span>
                    </div>
                    <p class="act-xlabs act-mono" aria-hidden="true"><span>{{ DayLabel::of($series->first()['date']) }}</span><span>{{ DayLabel::of($series->last()['date']) }}</span></p>
                @else
                    <p class="act-no-hist">A value chart appears once two daily price snapshots exist.</p>
                @endif
            </div>
            @if ($chart && $historySince)
                <p class="act-since">History since <span class="act-mono">{{ DayLabel::of($historySince) }}</span></p>
            @endif
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
                <h3 class="act-dtab" id="act-day-{{ $dayKey }}"><b>{{ DayLabel::of($day['date']) }}</b><span class="sr-only">, </span><span>{{ $dayCount }} {{ Str::plural('update', $dayCount) }}</span></h3>
                {{-- role="list": list-style: none makes WebKit/VoiceOver drop the list semantics otherwise --}}
                <ul class="act-dsheet" role="list">
                    @foreach ($day['entries'] as $entry)
                        @php
                            $card = $entry['card'];
                            $href = $card && $card->set ? route('gallery.card', ['username' => $targetUser->username, 'setTcgdexId' => $card->set->tcgdex_id, 'localId' => $card->local_id]) : null;
                            $thumb = CardImage::sized($card?->official_image_url, false);
                            // The key and wire:ignore keep a morph from rewriting a retried picture's src or putting back one the script removed.
                            $rowKey = $entry['kind'] === 'move' ? 'move-'.$card->id : 'added-'.$entry['item']->id;
                        @endphp
                        <li class="act-row" wire:key="{{ $rowKey }}">
                            <span class="act-thumb" wire:ignore>
                                @if ($thumb)<img src="{{ $thumb }}" alt="" width="46" height="64" loading="lazy" decoding="async">@endif
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

@push('scripts')
    @vite('resources/js/activity.js')
@endpush
