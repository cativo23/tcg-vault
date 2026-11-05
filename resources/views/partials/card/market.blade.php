@use('App\Support\Money')

            {{-- Market --}}
            <div class="nw-section-head mt-6">
                <span>Market value{{ $switcher ? ' · '.$selected['label'] : '' }}</span>
                @if ($newestMarketDay)
                    {{-- Martian Mono is for numerals only (design.md): the date, not the words. --}}
                    <span><span class="mono" style="letter-spacing: -.02em">{{ \Carbon\CarbonImmutable::parse($newestMarketDay)->format('j M Y') }}</span> · via {{ $marketOrigins->implode(' · ') }}</span>
                @endif
            </div>

            @if ($switcher && $snapshot?->market_minor === null)
                <p class="text-sm py-3" style="color: var(--muted)">No market price for {{ $selected['label'] }} yet.</p>
            @elseif ($pricedBeforeWindow)
                <p class="text-sm py-3" style="color: var(--muted)">No market price in the last {{ $recentDays }} days.</p>
            @elseif ($marketReads->isEmpty())
                <p class="text-sm py-3" style="color: var(--muted)">No market price recorded for this card yet.</p>
            @else
                <div class="nw-prices">
                    @foreach ($marketReads as $read)
                        @php $isResolved = $snapshot && $read->is($snapshot); @endphp
                        <div class="nw-price" @if ($isResolved) style="box-shadow: 0 0 0 1.5px var(--ink)" @endif
                            title="{{ $read->provenanceLabel() }}, {{ $read->captured_on->format('j M Y') }}">
                            <div class="src">
                                <span>{{ $read->sourceLabel() }}</span>
                                <span>{{ $read->variant === 'default' ? 'Avg' : \App\Modules\Catalog\Support\CardVariants::label($read->variant) }}</span>
                            </div>
                            <div class="amt {{ $isResolved && $read->source !== 'manual' && $delta?->isUp() ? 'up' : '' }}">{{ Money::format($read->market_minor, $read->currency) }}</div>
                            <div class="sub">
                                @php
                                    // A read from a source that stopped updating must not read
                                    // as current. The headline may trail by the resolver's own
                                    // tolerance before it counts as frozen.
                                    $frozenBefore = $isResolved
                                        ? \Carbon\CarbonImmutable::parse($newestMarketDay ?? 'today')->subDays(\App\Modules\Catalog\Services\CardPriceResolver::STALE_AFTER_DAYS)->toDateString()
                                        : $newestMarketDay;
                                @endphp
                                @if ($read->source === 'manual')
                                    {{-- A hand-entered value has no market low or trend. --}}
                                    entered {{ $read->captured_on->format($read->captured_on->isCurrentYear() ? 'j M' : 'j M Y') }}
                                @elseif ($newestMarketDay && $read->capturedOnKey() < $frozenBefore)
                                    {{-- A source that stopped updating (tcgdex dropped it) must not read as current. --}}
                                    as of {{ $read->captured_on->format('j M') }}
                                @elseif ($isResolved && $delta)
                                    {{ Money::signed($delta->deltaMinor, $read->currency) }}@if ($delta->percent() !== null) · {{ $delta->percent() > 0 ? '+' : '' }}{{ $delta->percent() }}%@endif since {{ $delta->previous->captured_on->format('j M') }}
                                @elseif ($read->low_minor !== null)
                                    low {{ Money::format($read->low_minor, $read->currency) }}@if ($read->trend_minor) · trend {{ Money::format($read->trend_minor, $read->currency) }}@endif
                                @else
                                    {{ $read->currency }}
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($canChart)
                    <div class="nw-panel mt-3">
                        <div class="head">
                            <div>
                                <div class="k">Price history · {{ $chartRange->label() }}</div>
                                @if ($history->count() >= 2)
                                    <div class="v">{{ Money::format($history->last()->market_minor, $history->last()->currency) }}
                                        @php $first = $history->first()->market_minor; $lastM = $history->last()->market_minor; @endphp
                                        <small class="{{ $lastM > $first ? 'up' : '' }}">{{ Money::signed($lastM - $first, $history->last()->currency) }} since {{ $history->first()->captured_on->format('j M') }}</small>
                                    </div>
                                @endif
                            </div>
                            <x-range-pills :active="$chartRange" label="Price history range" />
                        </div>
                        <div class="nw-chart">
                            @if ($history->count() >= 2)
                                <x-sparkline :points="$history->pluck('market_minor')->all()" :up="$lastM > $first"
                                    :dates="$history->pluck('captured_on')->all()" :currency="$history->last()->currency"
                                    :label="'Market price of '.$card->name.' over '.$chartRange->label().', from '.Money::format($first, $history->first()->currency).' to '.Money::format($lastM, $history->last()->currency)" />
                                @if ($historySince)
                                    <p class="text-xs px-2" style="color: var(--muted)">History since <span class="mono">{{ $historySince->format('j M') }}</span></p>
                                @endif
                            @else
                                <p class="text-sm px-2 py-3" style="color: var(--muted)">No price history in this range.</p>
                            @endif
                        </div>
                    </div>
                @endif
            @endif
