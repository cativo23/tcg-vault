@use('App\Modules\Catalog\Support\Rarity')
@use('App\Support\Money')

@php
    $headCurrency = array_key_first(array_filter($totals));
    $otherTotals = collect(array_filter($totals))->except($headCurrency);
    // The tab that takes the roving tab stop: the selected set, or All when the URL names a set the collector has none of.
    $tabStop = $sets->contains('tcgdex_id', $setFilter) ? $setFilter : '';
    $activeSet = $setFilter === '' ? null : $sets->firstWhere('tcgdex_id', $setFilter);
@endphp

<div class="col-page">
    <section class="col-head" aria-labelledby="col-who">
        <div class="col-who-row">
            <h1 id="col-who" class="col-who">{{ $targetUser->username }}</h1>
            <button type="button" class="col-copy" data-col-copy wire:ignore>
                <svg class="i-link" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07l-1.5 1.5"></path><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1.5-1.5"></path></svg>
                <svg class="i-ok" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg>
                <span class="col-copy__label">Copy link</span>
            </button>
            <span class="sr-only" role="status" data-col-copy-status wire:ignore></span>
        </div>
        @unless ($collectionIsEmpty)
            <p class="col-summary"><span class="col-mono">{{ $collectionTotal }}</span> {{ Str::plural('card', $collectionTotal) }} <span class="col-sep" aria-hidden="true">·</span> <span class="col-mono">{{ $sets->count() }}</span> {{ Str::plural('set', $sets->count()) }}@if ($headCurrency) <span class="col-sep" aria-hidden="true">·</span> <span class="col-mono">{{ Money::format($totals[$headCurrency], $headCurrency) }}</span>@if ($updatedAt) on <span class="col-mono">{{ $updatedAt->format('j M') }}</span>@endif @endif</p>
            @if ($priceOrigins->isNotEmpty() || $otherTotals->isNotEmpty())
                <p class="col-note">@if ($priceOrigins->isNotEmpty())Prices via {{ $priceOrigins->implode(' · ') }}.@endif @foreach ($otherTotals as $currency => $minor)Also {{ Money::format($minor, $currency) }} priced in {{ $currency }}, not added to the total. @endforeach</p>
            @endif
        @endunless
    </section>

    @if ($collectionIsEmpty)
        <section class="col-empty col-empty--page" aria-labelledby="col-empty-t">
            <div class="col-pockets" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>
            @if ($isOwner)
                <h2 class="col-empty-t" id="col-empty-t">Nothing here yet</h2>
                <p>Add a card or make one public from your collection.</p>
                <a href="{{ route('admin.collection.add') }}" class="btn">Add a card</a>
            @else
                <h2 class="col-empty-t" id="col-empty-t">Nothing on display yet</h2>
                <p>This collection has no public cards. Check back soon.</p>
            @endif
        </section>
    @else
        <section class="col-binder" aria-label="Cards">
            <div class="col-tabs-wrap" data-col-tabs-wrap>
                <div class="col-tabs" role="tablist" aria-label="Sets" data-col-tabs>
                    <button type="button" role="tab" id="col-tab-all" class="col-tab" style="z-index: {{ $sets->count() + 1 }}"
                            aria-selected="{{ $setFilter === '' ? 'true' : 'false' }}" tabindex="{{ $tabStop === '' ? 0 : -1 }}"
                            aria-controls="col-sheet" wire:click="selectSet('')" wire:key="tab-all"><span class="col-tab__nm">All</span> <b class="col-tab__n">{{ $collectionTotal }}</b></button>
                    @foreach ($sets as $set)
                        @php $total = $set->card_count ?: $set->real_card_count; @endphp
                        <button type="button" role="tab" id="col-tab-{{ $set->tcgdex_id }}" class="col-tab" style="z-index: {{ $sets->count() - $loop->index }}"
                                aria-selected="{{ $setFilter === $set->tcgdex_id ? 'true' : 'false' }}" tabindex="{{ $tabStop === $set->tcgdex_id ? 0 : -1 }}"
                                aria-controls="col-sheet" aria-label="{{ $set->name }}, {{ $set->owned_card_count }} of {{ $total }} cards"
                                wire:click="selectSet(@js($set->tcgdex_id))" wire:key="tab-{{ $set->tcgdex_id }}"><span class="col-tab__nm">{{ $set->name }}</span> <b class="col-tab__n">{{ $set->owned_card_count }}/{{ $total }}</b></button>
                    @endforeach
                </div>
            </div>

            <div class="col-sheet" id="col-sheet" role="tabpanel" aria-labelledby="col-tab-{{ $tabStop === '' ? 'all' : $tabStop }}">
                <div class="col-tools">
                    <div class="col-count-row">
                        <p class="col-count" role="status">Showing <b>{{ $totalEntries }}</b> of <b>{{ $collectionTotal }}</b></p>
                        @if ($isFiltered)
                            <button type="button" wire:click="clearFilters" class="col-clear">Clear</button>
                        @endif
                        @if ($activeSet)
                            <a class="col-setlink" href="{{ route('gallery.show', ['username' => $targetUser->username, 'setTcgdexId' => $activeSet->tcgdex_id]) }}">Open the {{ $activeSet->name }} page</a>
                        @endif
                    </div>

                    <div class="col-controls">
                        <label class="sr-only" for="gallery-search">Search cards by name</label>
                        <span class="col-search">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"></circle><path d="M16 16l4.5 4.5"></path></svg>
                            <input id="gallery-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search cards…" autocomplete="off" spellcheck="false">
                            <span wire:loading wire:target="search" class="col-search-busy" aria-hidden="true"></span>
                        </span>

                        @if ($rarities->count() > 1)
                            <label class="sr-only" for="gallery-rarity">Filter by rarity</label>
                            <span class="col-select">
                                <select id="gallery-rarity" wire:model.live="rarityFilter">
                                    <option value="">All rarities</option>
                                    @foreach ($rarities as $rarity)
                                        <option value="{{ $rarity }}">{{ Rarity::label($rarity) }}</option>
                                    @endforeach
                                </select>
                            </span>
                        @endif

                        <div class="col-seg" role="group" aria-label="Sort">
                            <span class="col-seg-lbl" aria-hidden="true">Sort</span>
                            <button type="button" wire:click="sortBy('value')" aria-pressed="{{ $sort === 'value' ? 'true' : 'false' }}">Value</button>
                            <button type="button" wire:click="sortBy('newest')" aria-pressed="{{ $sort === 'newest' ? 'true' : 'false' }}">Newest</button>
                            <button type="button" wire:click="sortBy('number')" aria-pressed="{{ $sort === 'number' ? 'true' : 'false' }}">Number</button>
                            <button type="button" wire:click="sortBy('name')" aria-pressed="{{ $sort === 'name' ? 'true' : 'false' }}">Name</button>
                        </div>
                    </div>
                </div>

                @if ($entries->isEmpty())
                    <div class="col-empty">
                        <div class="col-pockets" aria-hidden="true"><i></i><i></i><i></i></div>
                        <h2 class="col-empty-t">No cards match</h2>
                        <p>Try another name, or <button type="button" wire:click="clearFilters" class="col-inline">clear the filters</button>.</p>
                    </div>
                @else
                    <ol class="col-grid" wire:key="grid-{{ $sort }}-{{ md5($search.$setFilter.$rarityFilter) }}">
                        @foreach ($entries as $entry)
                            <x-revamp.card-tile
                                :card="$entry['card']"
                                :snapshot="$entry['snapshot']"
                                :week-delta="$entry['weekDelta']"
                                :items="$entry['items']"
                                :index="$loop->index % $perPage"
                                :eager="$loop->index < 8"
                                :priority="$loop->index === 0"
                                :lead="$loop->index < $leadCount"
                                :href="route('gallery.card', ['username' => $targetUser->username, 'setTcgdexId' => $entry['card']->set->tcgdex_id, 'localId' => $entry['card']->local_id])"
                            />
                        @endforeach
                    </ol>

                    @if ($hasMore)
                        <div class="col-more" wire:key="load-more-{{ $sort }}-{{ $take }}" wire:intersect.margin.900px="loadMore" wire:loading.class="is-loading">
                            <button type="button" wire:click="loadMore" class="col-btn-more">Load more</button>
                        </div>
                    @endif
                @endif
            </div>
        </section>
    @endif
</div>

@push('head')
    @vite('resources/css/collection.css')
@endpush

@push('scripts')
    @vite('resources/js/collection.js')
@endpush
