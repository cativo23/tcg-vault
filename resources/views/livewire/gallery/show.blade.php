@use('App\Modules\Catalog\Support\Rarity')
@use('App\Support\Money')

<div class="nw-wrap">
    <section class="nw-masthead">
        <div class="nw-eyebrow">
            <a href="{{ route('gallery.sets', ['username' => $targetUser->username]) }}" wire:navigate>Sets</a>
            <span aria-hidden="true">/</span>
            <span>{{ $set->series ?: 'Pokémon TCG' }} · {{ Str::upper($set->tcgdex_id) }}@if ($set->released_on) · {{ $set->released_on->format('M Y') }}@endif</span>
        </div>

        <div class="flex flex-wrap items-end justify-between gap-x-8 gap-y-4">
            <h1 class="nw-display nw-h1 nw-h1--md">{{ $set->name }}</h1>
            @if ($set->logo_url)
                <img src="{{ $set->logo_url }}" alt="" class="h-12 sm:h-16 max-w-[220px] object-contain mb-1" decoding="async" data-optional>
            @endif
        </div>

        @php $pct = $totalCount > 0 ? (int) round(($ownedCount / $totalCount) * 100) : 0; @endphp
        <div class="nw-stats" aria-label="Set summary">
            <div class="nw-stat accent">
                <div class="k">Owned value</div>
                <div class="v"><x-value-totals :totals="$ownedTotals" empty="No prices yet" /></div>
            </div>
            <div class="nw-stat">
                <div class="k">Collected</div>
                <div class="v">{{ $ownedCount }}<small>of {{ $totalCount }} · {{ $pct }}%</small></div>
            </div>
            <div class="nw-stat">
                <div class="k">Most valuable owned</div>
                @if ($mostValuable)
                    <div class="v text" title="{{ $mostValuable['card']->name }}">{{ $mostValuable['card']->name }}</div>
                    <div class="mono text-xs mt-1" style="color: var(--muted)">{{ Money::format($mostValuable['snapshot']->market_minor, $mostValuable['snapshot']->currency) }}</div>
                @else
                    <div class="v">—</div>
                @endif
            </div>
            <div class="nw-stat">
                <div class="k">Released</div>
                <div class="v">{{ $set->released_on?->format('M Y') ?? '—' }}</div>
            </div>
        </div>
        <div class="nw-bar mt-3" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $totalCount }}" aria-valuenow="{{ $ownedCount }}" aria-label="{{ $pct }}% of the set collected"><i style="width: {{ $pct }}%"></i></div>
    </section>

    <div class="nw-toolbar">
        <div class="nw-count">Showing <b>{{ $entries->count() }}</b> {{ Str::plural('card', $entries->count()) }}</div>

        <div class="nw-toolbar-group">
            <label class="sr-only" for="set-search">Search this set</label>
            <input id="set-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search this set…" class="nw-pill-input w-40 sm:w-52" autocomplete="off">

            @if ($rarities->count() > 1)
                <label class="sr-only" for="set-rarity">Filter by rarity</label>
                <select id="set-rarity" wire:model.live="rarityFilter" class="nw-pill-select">
                    <option value="">All rarities</option>
                    @foreach ($rarities as $rarity)
                        {{-- title-case the label only; the value must match the stored string --}}
                        <option value="{{ $rarity }}">{{ Rarity::label($rarity) }}</option>
                    @endforeach
                </select>
            @endif

            <div class="nw-seg" role="group" aria-label="Sort">
                <span class="lbl">Sort</span>
                <button type="button" wire:click="sortBy('number')" aria-pressed="{{ $sort === 'number' ? 'true' : 'false' }}">Number</button>
                <button type="button" wire:click="sortBy('value')" aria-pressed="{{ $sort === 'value' ? 'true' : 'false' }}">Value</button>
                <button type="button" wire:click="sortBy('name')" aria-pressed="{{ $sort === 'name' ? 'true' : 'false' }}">Name</button>
                <button type="button" wire:click="sortBy('rarity')" aria-pressed="{{ $sort === 'rarity' ? 'true' : 'false' }}">Rarity</button>
            </div>

            <div class="nw-seg" role="group" aria-label="Missing cards">
                <button type="button" wire:click="toggleMissing" aria-pressed="{{ $showMissing ? 'true' : 'false' }}">Show missing</button>
            </div>
        </div>
    </div>

    @if ($entries->isEmpty())
        <div class="nw-empty">
            <div class="t">No cards match</div>
            <p>Nothing in {{ $set->name }} matches that search.</p>
        </div>
    @else
        <div class="nw-grid" wire:key="grid-{{ $sort }}-{{ md5($search.$rarityFilter) }}">
            @foreach ($entries as $entry)
                <x-card-tile
                    wire:key="tile-{{ $entry['card']->id }}"
                    :card="$entry['card']"
                    :snapshot="$entry['snapshot']"
                    :delta="$entry['delta']"
                    :items="$entry['items']"
                    :index="$loop->index"
                    :eager="$loop->index < 6"
                    :show-set="false"
                    :href="route('gallery.card', ['username' => $targetUser->username, 'setTcgdexId' => $set->tcgdex_id, 'localId' => $entry['card']->local_id])"
                />
            @endforeach
        </div>
    @endif
</div>
