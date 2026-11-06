@use('App\Modules\Catalog\Support\Rarity')
@use('App\Support\Money')

@php
    // The owned value leads with its first currency (USD first); any other is a note, never added in.
    $headCurrency = array_key_first(array_filter($ownedTotals));
    $otherTotals = collect(array_filter($ownedTotals))->except($headCurrency);
@endphp

<div class="col-page set-page">
    <nav class="set-crumbs" aria-label="Breadcrumb">
        <ol>
            <li><a href="{{ route('gallery.sets', ['username' => $targetUser->username]) }}">Sets</a></li>
            @if ($set->series)
                <li>{{ $set->series }}</li>
            @endif
            <li aria-current="page">{{ $set->name }}</li>
        </ol>
    </nav>

    <section class="set-head" aria-labelledby="set-name">
        <div class="set-name-row">
            <h1 id="set-name" class="set-name">{{ $set->name }}</h1>
            @if ($set->safe_logo_url)
                <img class="set-logo" src="{{ $set->safe_logo_url }}" alt="" decoding="async" data-optional>
            @endif
            @if ($canEditSet)
                <a class="set-edit" href="{{ route('admin.sets.show', ['setTcgdexId' => $set->tcgdex_id]) }}">Edit in my collection</a>
            @endif
        </div>

        {{-- The collection's summary line, led by the set's code (abbreviation, else the tcgdex id) in small mono. --}}
        <p class="col-summary"><span class="set-code">{{ $setCode }}</span> <span class="col-sep" aria-hidden="true">·</span> <span class="set-chunk"><span class="col-mono">{{ $ownedCount }}</span> of <span class="col-mono">{{ $totalCount }}</span> {{ Str::plural('card', $totalCount) }}</span>@if ($headCurrency) <span class="col-sep" aria-hidden="true">·</span> <span class="set-chunk"><span class="col-mono">{{ Money::format($ownedTotals[$headCurrency], $headCurrency) }}</span></span>@endif @if ($set->released_on) <span class="col-sep" aria-hidden="true">·</span> <span class="set-chunk">released <span class="col-mono">{{ $set->released_on->format('M Y') }}</span></span>@endif</p>
        @if ($otherTotals->isNotEmpty())
            <p class="col-note">@foreach ($otherTotals as $currency => $minor)Also {{ Money::format($minor, $currency) }} priced in {{ $currency }}, not added to the total. @endforeach</p>
        @endif

        {{-- The set as a printed strip: owned filled, imported-but-missing hollow and numbered, not-yet-imported grey and unnumbered. --}}
        <div class="set-strip">
            <div class="set-strip-bar" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $totalCount }}" aria-valuenow="{{ $ownedCount }}" aria-label="{{ $percent }}% of the set collected">
                @foreach ($strip as $slot)
                    @if ($slot['state'] === 'owned')
                        <span></span>
                    @elseif ($slot['state'] === 'missing')
                        <span class="set-strip-miss" title="{{ '#'.$slot['number'].' missing' }}"></span>
                    @else
                        <span class="set-strip-off" title="not in the catalogue yet"></span>
                    @endif
                @endforeach
            </div>
            <div class="set-strip-scale" aria-hidden="true">
                @foreach ($ticks as $tick)
                    <b style="left: {{ $tick['left'] }}%">{{ $tick['label'] }}</b>
                @endforeach
            </div>
            <span class="set-strip-pct" aria-hidden="true">{{ $percent }}%</span>
        </div>
    </section>

    <section class="set-binder" aria-label="Cards">
        <div class="set-sheet">
            <span class="set-holes" aria-hidden="true"><i></i><i></i><i></i></span>
            <div class="col-tools">
                <div class="col-count-row">
                    <p class="col-count" role="status">Showing <b>{{ $entries->count() }}</b> {{ Str::plural('card', $entries->count()) }}</p>
                    @if ($isFiltered)
                        <button type="button" wire:click="clearFilters" class="col-clear">Clear</button>
                    @endif
                </div>

                <div class="col-controls">
                    <label class="sr-only" for="set-search">Search this set</label>
                    <span class="col-search">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"></circle><path d="M16 16l4.5 4.5"></path></svg>
                        <input id="set-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search this set…" autocomplete="off" spellcheck="false">
                        <span wire:loading wire:target="search" class="col-search-busy" aria-hidden="true"></span>
                    </span>

                    @if ($rarities->count() > 1)
                        <label class="sr-only" for="set-rarity">Filter by rarity</label>
                        <span class="col-select">
                            <select id="set-rarity" wire:model.live="rarityFilter">
                                <option value="">All rarities</option>
                                @foreach ($rarities as $rarity)
                                    {{-- title-case the label only; the value must match the stored string --}}
                                    <option value="{{ $rarity }}">{{ Rarity::label($rarity) }}</option>
                                @endforeach
                            </select>
                        </span>
                    @endif

                    <div class="col-seg" role="group" aria-label="Sort">
                        <span class="col-seg-lbl" aria-hidden="true">Sort</span>
                        <button type="button" wire:click="sortBy('number')" aria-pressed="{{ $sort === 'number' ? 'true' : 'false' }}">Number</button>
                        <button type="button" wire:click="sortBy('value')" aria-pressed="{{ $sort === 'value' ? 'true' : 'false' }}">Value</button>
                        <button type="button" wire:click="sortBy('name')" aria-pressed="{{ $sort === 'name' ? 'true' : 'false' }}">Name</button>
                        <button type="button" wire:click="sortBy('rarity')" aria-pressed="{{ $sort === 'rarity' ? 'true' : 'false' }}">Rarity</button>
                    </div>

                    {{-- Missing cards stay opt-in; the label says how many the switch reveals. --}}
                    @if ($missingCount > 0)
                        <button type="button" class="set-miss-toggle" wire:click="toggleMissing" aria-pressed="{{ $showMissing ? 'true' : 'false' }}"><span class="set-mt-box" aria-hidden="true"></span>Show the {{ $missingCount }} missing</button>
                    @endif
                </div>
            </div>

            @if ($entries->isEmpty())
                <div class="col-empty">
                    <div class="col-pockets" aria-hidden="true"><i></i><i></i><i></i></div>
                    <h2 class="col-empty-t">No cards match</h2>
                    <p>Nothing in {{ $set->name }} matches that search.</p>
                </div>
            @else
                <ol class="col-grid" wire:key="grid-{{ $sort }}-{{ md5($search.$rarityFilter) }}">
                    @foreach ($entries as $entry)
                        @php
                            $href = route('gallery.card', ['username' => $targetUser->username, 'setTcgdexId' => $set->tcgdex_id, 'localId' => $entry['card']->local_id]);
                        @endphp
                        @if ($entry['owned'])
                            <x-revamp.card-tile
                                :card="$entry['card']"
                                :snapshot="$entry['snapshot']"
                                :week-delta="$entry['weekDelta']"
                                :items="$entry['items']"
                                :index="min($loop->index, 24)"
                                :eager="$loop->index < 8"
                                :priority="$loop->index === 0"
                                :lead="$loop->index < $leadCount"
                                :in-set="true"
                                :href="$href"
                            />
                        @else
                            <x-revamp.missing-pocket :card="$entry['card']" :snapshot="$entry['snapshot']" :index="min($loop->index, 24)" :href="$href" />
                        @endif
                    @endforeach
                </ol>
            @endif
        </div>
    </section>
</div>

@push('head')
    @vite(['resources/css/collection.css', 'resources/css/set.css'])
@endpush

@push('scripts')
    @vite('resources/js/collection.js')
@endpush
