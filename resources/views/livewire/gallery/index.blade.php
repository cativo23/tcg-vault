@use('App\Modules\Catalog\Support\Rarity')

<div class="nw-wrap">
    <section class="nw-masthead">
        <div class="nw-eyebrow">
            <span>Pokémon TCG · Collection</span>
        </div>
        <h1 class="nw-display nw-h1">{{ $targetUser->username }}</h1>

        <div class="nw-stats" aria-label="Collection summary">
            <div class="nw-stat accent">
                <div class="k">Collection value</div>
                <div class="v">
                    <x-value-totals :totals="$totals" empty="No prices yet" :countup="true" />
                </div>
            </div>
            <div class="nw-stat">
                <div class="k">Cards</div>
                <div class="v">{{ $totalEntries }}<small>{{ $copies }} {{ Str::plural('copy', $copies) }}</small></div>
            </div>
            <div class="nw-stat">
                <div class="k">Sets</div>
                <div class="v">{{ $sets->count() }}</div>
            </div>
            <div class="nw-stat">
                <div class="k">Prices updated</div>
                <div class="v">
                    @if ($updatedAt)
                        {{ $updatedAt->format('j M') }}<small>tcgdex</small>
                    @else
                        —
                    @endif
                </div>
            </div>
        </div>
    </section>

    @if ($sets->isNotEmpty())
        <nav class="nw-rail" aria-label="Sets in this collection">
            @foreach ($sets as $set)
                @php $total = $set->card_count ?? $set->real_card_count; @endphp
                <a href="{{ route('gallery.show', ['username' => $targetUser->username, 'setTcgdexId' => $set->tcgdex_id]) }}" class="nw-chip" wire:navigate>
                    @if ($set->logo_url)
                        <img src="{{ $set->logo_url }}" alt="" loading="lazy" decoding="async" data-optional>
                    @endif
                    <span>
                        <span class="t block">{{ $set->name }}</span>
                        <span class="n block">{{ $set->owned_card_count }} / {{ $total }}</span>
                    </span>
                </a>
            @endforeach
        </nav>
    @endif

    @if ($totalEntries === 0)
        <div class="nw-empty">
            <div class="t">Nothing on display yet</div>
            <p>This collection has no public cards. Check back soon.</p>
        </div>
    @else
        <div class="nw-toolbar">
            <div class="nw-toolbar-group">
                <div class="nw-count">Showing <b>{{ $entries->count() }}</b> of <b>{{ $totalEntries }}</b></div>
                @if ($isFiltered)
                    <button type="button" wire:click="clearFilters" class="nw-count" style="color: var(--ink); text-decoration: underline; text-underline-offset: 3px">Clear</button>
                @endif
            </div>

            <div class="nw-toolbar-group">
                <label class="sr-only" for="gallery-search">Search cards by name</label>
                <input id="gallery-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search cards…" class="nw-pill-input w-40 sm:w-52" autocomplete="off">

                @if ($sets->count() > 1)
                    <label class="sr-only" for="gallery-set">Filter by set</label>
                    <select id="gallery-set" wire:model.live="setFilter" class="nw-pill-select">
                        <option value="">All sets</option>
                        @foreach ($sets as $set)
                            <option value="{{ $set->tcgdex_id }}">{{ $set->name }}</option>
                        @endforeach
                    </select>
                @endif

                @if ($rarities->count() > 1)
                    <label class="sr-only" for="gallery-rarity">Filter by rarity</label>
                    <select id="gallery-rarity" wire:model.live="rarityFilter" class="nw-pill-select">
                        <option value="">All rarities</option>
                        @foreach ($rarities as $rarity)
                            <option value="{{ $rarity }}">{{ Rarity::label($rarity) }}</option>
                        @endforeach
                    </select>
                @endif

                <div class="nw-seg" role="group" aria-label="Sort">
                    <span class="lbl">Sort</span>
                    <button type="button" wire:click="sortBy('value')" aria-pressed="{{ $sort === 'value' ? 'true' : 'false' }}">Value</button>
                    <button type="button" wire:click="sortBy('newest')" aria-pressed="{{ $sort === 'newest' ? 'true' : 'false' }}">Newest</button>
                    <button type="button" wire:click="sortBy('number')" aria-pressed="{{ $sort === 'number' ? 'true' : 'false' }}">Number</button>
                    <button type="button" wire:click="sortBy('name')" aria-pressed="{{ $sort === 'name' ? 'true' : 'false' }}">Name</button>
                </div>
            </div>
        </div>

        @if ($entries->isEmpty())
            <div class="nw-empty">
                <div class="t">No cards match</div>
                <p>Try another name, or <button type="button" wire:click="clearFilters" class="underline" style="color: var(--ink)">clear the filters</button>.</p>
            </div>
        @else
            <div class="nw-grid" wire:key="grid-{{ $sort }}-{{ md5($search.$setFilter.$rarityFilter) }}">
                @foreach ($entries as $entry)
                    <x-card-tile
                        wire:key="tile-{{ $entry['card']->id }}"
                        :card="$entry['card']"
                        :snapshot="$entry['snapshot']"
                        :delta="$entry['delta']"
                        :items="$entry['items']"
                        :index="$loop->index"
                        :eager="$loop->index < 6"
                        :href="route('gallery.card', ['username' => $targetUser->username, 'setTcgdexId' => $entry['card']->set->tcgdex_id, 'localId' => $entry['card']->local_id])"
                    />
                @endforeach
            </div>
        @endif
    @endif
</div>
