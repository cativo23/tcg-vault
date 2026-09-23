@use('App\Modules\Catalog\Support\Rarity')
@use('App\Support\Money')

@php
    $conditionNames = ['NM' => 'Near Mint', 'LP' => 'Lightly Played', 'MP' => 'Moderately Played', 'HP' => 'Heavily Played', 'DMG' => 'Damaged'];
    $setUrl = route('gallery.show', ['username' => $targetUser->username, 'setTcgdexId' => $card->set->tcgdex_id]);
@endphp

<div class="nw-wrap">
    <section class="nw-masthead">
        <div class="nw-eyebrow">
            <a href="{{ route('gallery.index', ['username' => $targetUser->username]) }}" wire:navigate>Collection</a>
            <span aria-hidden="true">/</span>
            <a href="{{ $setUrl }}" wire:navigate>{{ $card->set->name }}</a>
            <span aria-hidden="true">/</span>
            <span class="mono">#{{ $card->local_id }}</span>
        </div>
    </section>

    <div class="nw-detail" x-data="{ current: 0 }">
        {{-- Hero image: the collector's photo first, official art as the fallback/alternate. --}}
        <div class="nw-hero">
            @if ($images === [])
                <x-card-image :url="null" :name="$card->name" />
            @else
                @foreach ($images as $i => $image)
                    <div x-show="current === {{ $i }}" x-cloak.if="{{ $i !== 0 ? 'true' : 'false' }}" @if ($i !== 0) style="display: none" @endif>
                        <x-card-image :url="$image['url']" :name="$card->name.' #'.$card->local_id.' — '.$image['label']" :eager="$i === 0" :class="$image['kind'] === 'photo' ? 'photo' : ''" />
                    </div>
                @endforeach

                @if (count($images) > 1)
                    <div class="nw-thumbs" role="tablist" aria-label="Views of this card">
                        @foreach ($images as $i => $image)
                            <button type="button" role="tab" @click="current = {{ $i }}" :aria-pressed="current === {{ $i }} ? 'true' : 'false'" :aria-selected="current === {{ $i }}">
                                <x-card-image :url="$image['url']" :name="$image['label']" />
                                <span class="lbl">{{ $image['label'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2 mb-3">
                @if ($owned)
                    <span class="nw-badge"><span class="dot-in" aria-hidden="true"></span>In the collection</span>
                    @foreach ($items->filter(fn ($i) => $i->grade_company && $i->grade_value)->unique(fn ($i) => $i->grade_company.$i->grade_value) as $graded)
                        <span class="nw-badge slab">{{ $graded->grade_company }} {{ $graded->grade_value }}</span>
                    @endforeach
                @else
                    <span class="nw-badge soft">Not in the collection</span>
                @endif
                @if (Rarity::label($card->rarity) !== '')
                    <span class="nw-badge soft">{{ Rarity::label($card->rarity) }}</span>
                @endif
            </div>

            <h1 class="nw-display nw-h1 nw-h1--sm">{{ $card->name }}</h1>
            <p class="mt-3 text-sm" style="color: var(--muted)">
                <a href="{{ $setUrl }}" class="underline decoration-[var(--hair)] hover:decoration-[var(--signal)] underline-offset-[3px]" style="color: var(--ink)" wire:navigate>{{ $card->set->name }}</a>
                · <span class="mono">#{{ $card->local_id }}@if ($card->set->card_count)/{{ $card->set->card_count }}@endif</span>
                @if ($card->set->series) · {{ $card->set->series }}@endif
            </p>

            {{-- Market --}}
            <div class="nw-section-head mt-6">
                <span>Market value</span>
                @if ($priceUpdatedAt)
                    <span class="mono" style="letter-spacing: -.02em">{{ \Carbon\CarbonImmutable::parse($priceUpdatedAt)->format('j M Y') }} · tcgdex</span>
                @endif
            </div>

            @if ($marketReads->isEmpty())
                <p class="text-sm py-3" style="color: var(--muted)">No market price recorded for this card yet.</p>
            @else
                <div class="nw-prices">
                    @foreach ($marketReads as $read)
                        @php $isResolved = $snapshot && $read->is($snapshot); @endphp
                        <div class="nw-price" @if ($isResolved) style="box-shadow: 0 0 0 1.5px var(--ink)" @endif>
                            <div class="src">
                                <span>{{ $read->source === 'tcgplayer' ? 'TCGplayer' : 'Cardmarket' }}</span>
                                <span>{{ Str::headline($read->variant === 'default' ? 'avg' : $read->variant) }}</span>
                            </div>
                            <div class="amt {{ $isResolved && $delta?->isUp() ? 'up' : '' }}">{{ Money::format($read->market_minor, $read->currency) }}</div>
                            <div class="sub">
                                @if ($isResolved && $delta)
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

                @if ($history->count() >= 2)
                    <div class="nw-panel mt-3">
                        <div class="head">
                            <div>
                                <div class="k">Price history · {{ $history->count() }} days</div>
                                <div class="v">{{ Money::format($history->last()->market_minor, $history->last()->currency) }}
                                    @php $first = $history->first()->market_minor; $lastM = $history->last()->market_minor; @endphp
                                    <small class="{{ $lastM > $first ? 'up' : '' }}">{{ Money::signed($lastM - $first, $history->last()->currency) }} since {{ $history->first()->captured_on->format('j M') }}</small>
                                </div>
                            </div>
                        </div>
                        <div class="nw-chart">
                            <x-sparkline :points="$history->pluck('market_minor')->all()" :up="$lastM > $first"
                                :dates="$history->pluck('captured_on')->all()" :currency="$history->last()->currency"
                                :label="'Market price of '.$card->name.' over '.$history->count().' days, from '.Money::format($first, $history->first()->currency).' to '.Money::format($lastM, $history->last()->currency)" />
                        </div>
                    </div>
                @endif
            @endif

            {{-- Copies --}}
            @if ($owned)
                <div class="nw-section-head mt-8">
                    <span>In the collection · {{ $quantity }} {{ Str::plural('copy', $quantity) }}</span>
                    @if ($ownedTotal)
                        <span class="mono" style="color: var(--ink); letter-spacing: -.03em"><x-value-totals :totals="$ownedTotal" /> total</span>
                    @endif
                </div>
                <div style="border-top: 1px solid var(--hair)">
                    @foreach ($items as $item)
                        <div class="nw-copy">
                            <div class="qty">×{{ $item->quantity }}</div>
                            <div class="min-w-0">
                                <div class="desc">
                                    @if ($item->grade_company && $item->grade_value)
                                        {{ $item->grade_company }} {{ $item->grade_value }} <span style="color: var(--muted)">· graded</span>
                                    @else
                                        {{ $conditionNames[$item->condition] ?? $item->condition }} <span class="mono text-xs" style="color: var(--muted)">{{ $item->condition }}</span>
                                    @endif
                                </div>
                                <div class="meta">
                                    @if ($item->variant){{ Str::headline($item->variant) }} · @endif
                                    Added {{ $item->created_at->format('j M Y') }}
                                    @if ($item->photo_path) · own photo @endif
                                </div>
                                @if ($item->notes)
                                    <div class="notes">{{ $item->notes }}</div>
                                @endif
                            </div>
                            <div></div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="nw-section-head mt-8"><span>Collection</span></div>
                <p class="text-sm py-2" style="color: var(--muted)">This card is in <a href="{{ $setUrl }}" class="underline underline-offset-[3px]" style="color: var(--ink)" wire:navigate>{{ $card->set->name }}</a>, but not in {{ $targetUser->username }}'s collection yet.</p>
            @endif

            {{-- Card facts --}}
            @if ($facts !== [])
                <div class="nw-section-head mt-8"><span>Card</span></div>
                <dl class="nw-kv">
                    @foreach ($facts as $k => $v)
                        <div><dt class="k">{{ $k }}</dt><dd class="v">{{ $v }}</dd></div>
                    @endforeach
                    <div><dt class="k">Catalog ID</dt><dd class="v mono text-xs">{{ $card->tcgdex_id }}</dd></div>
                </dl>
            @endif
        </div>
    </div>

    @if ($related->isNotEmpty())
        <div class="nw-section-head" style="border-top: 1px solid var(--hair); padding-top: 26px">
            <span>More from {{ $card->set->name }}</span>
            <a href="{{ $setUrl }}" wire:navigate>View the set</a>
        </div>
        <div class="nw-strip">
            @foreach ($related as $entry)
                <x-card-tile
                    :card="$entry['card']"
                    :snapshot="$entry['snapshot']"
                    :delta="$entry['delta']"
                    :items="$entry['items']"
                    :index="$loop->index"
                    :show-set="false"
                    :href="route('gallery.card', ['username' => $targetUser->username, 'setTcgdexId' => $card->set->tcgdex_id, 'localId' => $entry['card']->local_id])"
                />
            @endforeach
        </div>
    @endif
</div>
