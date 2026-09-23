@use('App\Modules\Catalog\Support\Rarity')
@use('App\Support\Money')

<div class="nw-wrap">
    <section class="nw-masthead">
        <div class="nw-eyebrow"><span>{{ $targetUser->username }} · collection history</span></div>
        <h1 class="nw-display nw-h1 nw-h1--md">Activity</h1>
    </section>

    <div class="nw-panel mt-6 sm:mt-8">
        <div class="head">
            <div>
                <div class="k">Collection value</div>
                <div class="v">
                    <x-value-totals :totals="$totals" empty="No prices yet" />
                    @if ($series->count() >= 2)
                        @php $first = $series->first()['minor']; $last = $series->last()['minor']; @endphp
                        <small class="{{ $last > $first ? 'up' : '' }}">{{ Money::signed($last - $first, $primaryCurrency) }} · {{ $series->count() }} days</small>
                    @endif
                </div>
            </div>
            @if ($series->count() >= 2)
                <div class="k pb-1">{{ $series->first()['date']->format('j M') }} – {{ $series->last()['date']->format('j M') }}</div>
            @endif
        </div>
        <div class="nw-chart">
            @if ($series->count() >= 2)
                <x-sparkline :points="$series->pluck('minor')->all()" :up="$last > $first" :height="150"
                    :dates="$series->pluck('date')->all()" :currency="$primaryCurrency"
                    :label="'Collection value over '.$series->count().' days, from '.Money::format($first, $primaryCurrency).' to '.Money::format($last, $primaryCurrency)" />
            @else
                <div class="h-16 flex items-center px-2 text-xs" style="color: var(--muted)">A value chart appears once two daily price snapshots exist.</div>
            @endif
        </div>
    </div>

    <div class="nw-section-head">
        <span>Latest · {{ $feedCount }} {{ Str::plural('update', $feedCount) }}</span>
    </div>

    @if ($feed->isEmpty())
        <div class="nw-empty">
            <div class="t">Nothing yet</div>
            <p>Additions and price movements show up here as they happen.</p>
        </div>
    @else
        <ul class="nw-feed mb-12">
            @foreach ($feed as $entry)
                @php
                    $card = $entry['card'];
                    $href = $card && $card->set ? route('gallery.card', ['username' => $targetUser->username, 'setTcgdexId' => $card->set->tcgdex_id, 'localId' => $card->local_id]) : null;
                @endphp
                <li>
                    @if ($entry['kind'] === 'move')
                        @php $up = $entry['delta']->isUp(); @endphp
                        <div class="ico {{ $up ? 'up' : 'down' }}" aria-hidden="true">{!! $up ? '&#9650;' : '&#9660;' !!}</div>
                        <div class="fbody">
                            <div class="title">
                                @if ($href)<a href="{{ $href }}" wire:navigate>{{ $card->name }} <span class="mono" style="font-weight: 600">#{{ $card->local_id }}</span></a>@else {{ $card->name }} @endif
                                {{ $up ? 'went up' : 'went down' }}
                            </div>
                            <div class="sub">{{ Rarity::label($card->rarity) ?: 'Card' }} · {{ $card->set?->name }} · {{ $entry['delta']->latest->source === 'tcgplayer' ? 'TCGplayer' : 'Cardmarket' }}</div>
                        </div>
                        <div class="fright">
                            <div class="famt {{ $up ? 'up' : 'down' }}">{{ Money::signed($entry['delta']->deltaMinor, $entry['delta']->latest->currency) }}</div>
                            <div class="fwhen">{{ $entry['at']->format('j M') }}</div>
                        </div>
                    @else
                        <div class="ico">
                            @if ($card?->official_image_url)
                                <img src="{{ $card->official_image_url }}" alt="" loading="lazy" decoding="async" data-optional>
                            @endif
                            <span class="fallback" aria-hidden="true">+</span>
                        </div>
                        <div class="fbody">
                            <div class="title">
                                Added
                                @if ($href)<a href="{{ $href }}" wire:navigate>{{ $card->name }}</a>@else {{ $card?->name ?? 'a card' }} @endif
                                @if ($entry['item']->quantity > 1) <span class="mono" style="font-weight: 600">×{{ $entry['item']->quantity }}</span> @endif
                            </div>
                            <div class="sub">
                                {{ $card?->set?->name }}
                                · {{ $entry['item']->grade_company ? $entry['item']->grade_company.' '.$entry['item']->grade_value : $entry['item']->condition }}
                                @if ($entry['item']->variant) · {{ Str::headline($entry['item']->variant) }} @endif
                            </div>
                        </div>
                        <div class="fright">
                            @if ($entry['snapshot'] && $entry['snapshot']->market_minor !== null)
                                <div class="famt">{{ Money::format($entry['snapshot']->market_minor, $entry['snapshot']->currency) }}</div>
                            @endif
                            <div class="fwhen" title="{{ $entry['at']->toDayDateTimeString() }}">{{ $entry['at']->diffForHumans(short: true) }}</div>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
