@use('App\Modules\Catalog\Support\ChartRange')
@use('App\Modules\Catalog\Support\PriceChart')
@use('App\Support\DayLabel')
@use('App\Support\Money')
@use('Carbon\CarbonImmutable')

{{-- Price history: a stock sheet behind range divider tabs. The tabs are a
     real tablist with manual activation, as the collection's set tabs: card.js
     moves focus with the arrows; Enter or Space selects, which is the only time
     Livewire is asked. The chart is drawn here from PriceChart: points by date,
     a market day with no price as a dotted gap, a manual price as a step line,
     dots and labels as HTML so the stretched SVG never distorts them. $hasMarket
     is the same answer the market tags use. --}}
@if ($hasMarket && $canChart)
    @php
        $first = $history->first();
        $last = $history->last();
        $rangeMove = $chart !== null ? $last->market_minor - $first->market_minor : 0;
        $pct = fn (float $value, int $of): string => round($value / $of * 100, 2).'%';
        $readings = $chart !== null ? array_map(fn (array $p): array => [
            'x' => round($p['x'] / PriceChart::WIDTH * 100, 2),
            'y' => round($p['y'] / PriceChart::HEIGHT * 100, 2),
            'read' => DayLabel::of(CarbonImmutable::parse($p['day'])).' · '.Money::format($p['minor'], $last->currency),
        ], $chart->points) : [];
    @endphp
    <section class="crd-history" aria-labelledby="crd-ph-h">
        <div class="crd-tabs" role="tablist" aria-label="Price history range" data-crd-tabs>
            @foreach (ChartRange::cases() as $option)
                @php $isOn = $option === $chartRange; @endphp
                <button type="button" role="tab" id="crd-range-{{ $option->value }}" class="crd-tab" wire:key="range-{{ $option->value }}" aria-selected="{{ $isOn ? 'true' : 'false' }}" aria-controls="crd-chart-panel" tabindex="{{ $isOn ? '0' : '-1' }}" wire:click="selectRange(@js($option->value))">{{ $option->button() }}</button>
            @endforeach
        </div>
        <div class="crd-sheet crd-chart-sheet" id="crd-chart-panel" role="tabpanel" aria-labelledby="crd-range-{{ $chartRange->value }}">
            <div class="crd-ch-head">
                <div>
                    <h2 class="crd-ch-k" id="crd-ph-h">Price history · {{ $chartRange->label() }}</h2>
                    @if ($chart !== null)
                        <p class="crd-ch-v">{{ Money::format($last->market_minor, $last->currency) }} <small @class(['crd-up' => $rangeMove > 0])>{{ Money::move($rangeMove, $last->currency) }} since {{ DayLabel::of($first->captured_on) }}</small></p>
                    @endif
                </div>
                <p class="crd-ch-read crd-mono" data-crd-chart-read aria-hidden="true"></p>
            </div>
            @if ($chart !== null)
                @php
                    $end = $chart->points[count($chart->points) - 1];
                    $chartLabel = 'Market price of '.$card->name.($switcher ? ' ('.$selected['label'].')' : '').' over '.$chartRange->label()
                        .', from '.Money::format($first->market_minor, $first->currency).' to '.Money::format($last->market_minor, $last->currency);
                    // The slider rests on the newest day; its value text is the same words card.js writes for a reading.
                    $lastIndex = count($readings) - 1;
                    $restText = $readings[$lastIndex]['read'];
                @endphp
                {{-- A slider's children are presentational, so the drawing is described here and the slider points at it. --}}
                <p class="sr-only" id="crd-chart-sum">{{ $chartLabel }}</p>
                <div class="crd-chart" data-crd-chart tabindex="0" role="slider" aria-label="Price chart. Left and right arrow keys read each day." aria-valuemin="0" aria-valuemax="{{ $lastIndex }}" aria-valuenow="{{ $lastIndex }}" aria-valuetext="{{ $restText }}" aria-describedby="crd-chart-sum" wire:key="chart-{{ $chartRange->value }}-{{ $selected['key'] ?? 'card' }}" data-points='@json($readings)'>
                    <div class="crd-plot">
                        <svg viewBox="0 0 {{ PriceChart::WIDTH }} {{ PriceChart::HEIGHT }}" preserveAspectRatio="none" aria-hidden="true">
                            <line class="crd-grid" x1="0" x2="{{ PriceChart::WIDTH }}" y1="{{ $chart->highY }}" y2="{{ $chart->highY }}"/>
                            <line class="crd-grid" x1="0" x2="{{ PriceChart::WIDTH }}" y1="{{ $chart->lowY }}" y2="{{ $chart->lowY }}"/>
                            @foreach ($chart->areas as $d)
                                <path class="crd-area" d="{{ $d }}"/>
                            @endforeach
                            @foreach ($chart->gaps as $gap)
                                <line class="crd-gap" x1="{{ $gap['x1'] }}" y1="{{ $gap['y1'] }}" x2="{{ $gap['x2'] }}" y2="{{ $gap['y2'] }}"/>
                            @endforeach
                            @foreach ($chart->lines as $d)
                                <path class="crd-line" d="{{ $d }}"/>
                            @endforeach
                            <rect class="crd-chart-cover" x="0" y="0" width="{{ PriceChart::WIDTH }}" height="{{ PriceChart::HEIGHT }}"/>
                        </svg>
                        @foreach ($chart->dots as $dot)
                            <span class="crd-dot" style="left: {{ $pct($dot['x'], PriceChart::WIDTH) }}; top: {{ $pct($dot['y'], PriceChart::HEIGHT) }}" aria-hidden="true"></span>
                        @endforeach
                        <span @class(['crd-end', 'crd-end--up' => $rangeMove > 0]) style="left: {{ $pct($end['x'], PriceChart::WIDTH) }}; top: {{ $pct($end['y'], PriceChart::HEIGHT) }}" aria-hidden="true"></span>
                        <span class="crd-cross" data-crd-cross aria-hidden="true"></span>
                        <span class="crd-hover-dot" data-crd-hover-dot aria-hidden="true"></span>
                        <span class="crd-ylab crd-mono" style="top: {{ $pct($chart->highY, PriceChart::HEIGHT) }}" aria-hidden="true">{{ Money::format($chart->high, $last->currency) }}</span>
                        <span class="crd-ylab crd-mono" style="top: {{ $pct($chart->lowY, PriceChart::HEIGHT) }}" aria-hidden="true">{{ Money::format($chart->low, $last->currency) }}</span>
                        <span class="crd-xlab crd-mono" aria-hidden="true">{{ DayLabel::of($first->captured_on) }}</span>
                        <span class="crd-xlab crd-xlab--end crd-mono" aria-hidden="true">{{ DayLabel::of($last->captured_on) }}</span>
                    </div>
                </div>
                @if ($historySince)
                    <p class="crd-since">History since <span class="crd-mono">{{ DayLabel::of($historySince) }}</span></p>
                @endif
            @else
                <p class="crd-no-hist">No price history in this range.</p>
            @endif
        </div>
    </section>
@endif
