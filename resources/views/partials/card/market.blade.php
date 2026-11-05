@use('App\Modules\Catalog\Services\CardPriceResolver')
@use('App\Modules\Catalog\Support\CardVariants')
@use('App\Support\DayLabel')
@use('App\Support\Money')
@use('Carbon\CarbonImmutable')

{{-- Market value: one hang tag per market read, the headline read first with
     an ink edge. USD and EUR are never added together. The note lines keep every
     honest caveat: a manual price says when it was entered, a source that
     stopped updating says "as of", the headline shows how it moved since its
     previous price day and since a week ago, the others give low and trend.
     Whether there is anything to show is $marketEmpty, decided once in
     CardShow and shared with the price history. --}}
@php
    // Built here, not with @if inside the <h2>, so the heading is one string.
    $mvSuffix = $switcher ? ' <span class="crd-sec-v">· '.e($selected['label']).'</span>' : '';
@endphp
<section class="crd-market" aria-labelledby="crd-mv-h">
    <div class="crd-sec-head">
        <h2 id="crd-mv-h" class="crd-sec-t">Market value{!! $mvSuffix !!}</h2>
        @if ($newestMarketDay)
            {{-- Martian Mono is for numerals only (design.md): the date, not the words. --}}
            <span class="crd-sec-r"><span class="crd-mono">{{ CarbonImmutable::parse($newestMarketDay)->format('j M Y') }}</span> · via {{ $marketOrigins->implode(' · ') }}</span>
        @endif
    </div>

    @if ($marketEmpty !== null)
        <p class="crd-mv-empty">{{ $marketEmpty }}</p>
    @else
        <div class="crd-rail">
            @foreach ($marketReads as $read)
                @php
                    $isResolved = $snapshot !== null && $read->is($snapshot);
                    // A read from a source that stopped updating must not read as
                    // current. The headline may trail by the resolver's own
                    // tolerance before it counts as frozen.
                    $frozenBefore = $isResolved
                        ? CarbonImmutable::parse($newestMarketDay ?? 'today')->subDays(CardPriceResolver::STALE_AFTER_DAYS)->toDateString()
                        : $newestMarketDay;
                    // Lines of segments: [text, is a rise, tooltip]. A move is green only when it is a rise.
                    $lines = [];
                    if ($read->source === 'manual') {
                        // A hand-entered value has no market low, trend or move.
                        $lines[] = [['entered '.DayLabel::of($read->captured_on), false, 'Price entered by hand']];
                    } elseif ($newestMarketDay && $read->capturedOnKey() < $frozenBefore) {
                        $lines[] = [['as of '.DayLabel::of($read->captured_on), false, 'This source has not updated since']];
                    } elseif ($isResolved && ($delta || $weekDelta)) {
                        $moves = [];
                        if ($delta) {
                            $gap = (int) abs($delta->latest->captured_on->diffInDays($delta->previous->captured_on));
                            $moves[] = [Money::move($delta->deltaMinor, $read->currency).' · '.$gap.'d', $delta->isUp(), 'Since '.DayLabel::of($delta->previous->captured_on)];
                        }
                        if ($weekDelta) {
                            $moves[] = [Money::move($weekDelta->deltaMinor, $read->currency).' · 7d', $weekDelta->isUp(), 'Since '.DayLabel::of($weekDelta->previous->captured_on)];
                        }
                        $lines[] = $moves;
                        if ($read->low_minor !== null) {
                            $lines[] = [['low '.Money::format($read->low_minor, $read->currency), false, 'Lowest current listing']];
                        }
                    } elseif ($read->low_minor !== null) {
                        $lines[] = [['low '.Money::format($read->low_minor, $read->currency), false, 'Lowest current listing']];
                        if ($read->trend_minor) {
                            $lines[] = [['trend '.Money::format($read->trend_minor, $read->currency), false, 'Price trend']];
                        }
                    } else {
                        $lines[] = [[$read->currency, false, 'Currency']];
                    }
                @endphp
                <div class="crd-htag{{ $isResolved ? ' crd-htag--lead' : '' }}" style="--i: {{ $loop->index }}" wire:key="read-{{ $read->source }}-{{ $read->variant }}" title="{{ $read->provenanceLabel() }}, {{ $read->captured_on->format('j M Y') }}">
                    <div class="crd-htag-in">
                        <div class="crd-htag-face">
                            <span class="crd-htag-hole" aria-hidden="true"></span>
                            <span class="crd-src"><b>{{ $read->sourceLabel() }}</b><span>{{ $read->variant === 'default' ? 'Avg' : CardVariants::label($read->variant) }}</span></span>
                            <span class="crd-amt">{{ Money::format($read->market_minor, $read->currency) }}</span>
                            <span class="crd-tsub">
                                @foreach ($lines as $segments)
                                    <span class="crd-tline">
                                        @foreach ($segments as [$text, $up, $tip])
                                            <span @class(['crd-seg', 'crd-move--up' => $up]) title="{{ $tip }}">{{ $text }}</span>
                                        @endforeach
                                    </span>
                                @endforeach
                            </span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</section>
