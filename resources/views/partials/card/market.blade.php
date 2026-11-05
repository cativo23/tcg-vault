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

            @endif
