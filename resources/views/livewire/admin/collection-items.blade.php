<div
    class="nw-wrap py-10"
    x-data
    x-on:card-editor-closed.window="$nextTick(() => document.getElementById($event.detail.triggerId)?.focus())"
>
    {{-- flex-wrap, not a fixed row: .nw-h1's overflow-wrap:anywhere (needed
         elsewhere for long usernames) breaks text letter-by-letter once a
         flex sibling squeezes it below one word's width — happened here
         once the button row grew to 3 items. Wrapping the buttons to their
         own line on narrow screens keeps the heading at its natural size
         instead of fighting it for the same row. --}}
    <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
        <h1 class="nw-display nw-h1 nw-h1--sm">My Collection</h1>
        <div class="flex items-center gap-2 flex-wrap">
            {{-- No separate Save step — same instant-apply pattern as
                 "Needs review" below, since this is reversible any time,
                 not a destructive action that needs a confirm step. Two
                 fixed-label options, like the gallery's Sort control: a
                 single button whose label swapped between states read as
                 both the state and the action at once. --}}
            <div class="nw-seg" role="group" aria-label="Visibility" aria-describedby="visibility-help">
                <span class="lbl">Visibility</span>
                <button type="button" wire:click="makePrivate" aria-pressed="{{ $isPublic ? 'false' : 'true' }}">Private</button>
                <button type="button" wire:click="makePublic" aria-pressed="{{ $isPublic ? 'true' : 'false' }}">Public</button>
            </div>
            <a href="{{ route('admin.collection.import') }}" class="nw-btn-secondary">Import TCGplayer</a>
            <a href="{{ route('admin.collection.export') }}" class="nw-btn-secondary" download>Export CSV</a>
            <a href="{{ route('admin.collection.add') }}" class="nw-btn-primary">+ Add card</a>
        </div>
    </div>

    {{-- Says what the current setting means at the point of deciding. A
         public collection has no page until the user picks a username,
         so that case points to the profile instead of a URL. --}}
    <p id="visibility-help" class="text-xs mb-4 sm:text-right" style="color: var(--muted)" aria-live="polite">
        @if (! $isPublic)
            Only you can see your collection.
        @elseif ($username = auth()->user()->username)
            Anyone can see it at
            <a href="{{ route('gallery.index', ['username' => $username]) }}" style="color: var(--ink); text-decoration: underline">/{{ $username }}</a>.
        @else
            Public, but there’s no page to show it on yet.
            <a href="{{ route('profile') }}" wire:navigate style="color: var(--ink); text-decoration: underline">Set a username</a> to get one.
        @endif
    </p>

    <div class="nw-toolbar mb-4">
        <div class="nw-count" role="status">Showing <b>{{ $totalCards }}</b> {{ Str::plural('card', $totalCards) }} <span style="opacity:.6">· {{ $totalCopies }} {{ Str::plural('copy', $totalCopies) }}</span></div>

        @if ($possiblyTruncated)
            <div class="text-xs" style="color: var(--warning)" title="Your collection has more items than this listing can load at once — some cards, totals, or variant groupings may be incomplete">
                Showing the first 1,000 items — narrow your search to see the rest.
            </div>
        @endif

        <div class="nw-toolbar-group">
            <label class="sr-only" for="collection-search">Search your collection</label>
            <span class="nw-search-wrap">
                <input id="collection-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, set, notes…" class="nw-pill-input w-40 sm:w-52" autocomplete="off">
                <span wire:loading wire:target="search" class="nw-search-loading" aria-hidden="true"></span>
            </span>

            <label class="sr-only" for="collection-condition">Filter by condition</label>
            <select id="collection-condition" wire:model.live="conditionFilter" class="nw-pill-select">
                <option value="">All conditions</option>
                <option value="NM">Near Mint</option>
                <option value="LP">Lightly Played</option>
                <option value="MP">Moderately Played</option>
                <option value="HP">Heavily Played</option>
                <option value="DMG">Damaged</option>
            </select>

            <label class="sr-only" for="collection-variant">Filter by variant</label>
            <select id="collection-variant" wire:model.live="variantFilter" class="nw-pill-select">
                <option value="">All variants</option>
                <option value="normal">Normal</option>
                <option value="holofoil">Holofoil</option>
                <option value="reverse-holofoil">Reverse Holofoil</option>
                @foreach ($specialVariantOptions as $v)
                    <option value="{{ $v }}">{{ \App\Modules\Catalog\Support\CardVariants::label($v) }}</option>
                @endforeach
                <option value="__none__">Not specified</option>
            </select>

            <div class="nw-seg" role="group" aria-label="Needs review">
                <button type="button" wire:click="$set('needsReviewOnly', {{ $needsReviewOnly ? 'false' : 'true' }})" aria-pressed="{{ $needsReviewOnly ? 'true' : 'false' }}">Needs review</button>
            </div>

            <div class="nw-seg" role="group" aria-label="Sort">
                <span class="lbl">Sort</span>
                <button type="button" wire:click="sortBy('value')" aria-pressed="{{ $sort === 'value' ? 'true' : 'false' }}">Value</button>
                <button type="button" wire:click="sortBy('name')" aria-pressed="{{ $sort === 'name' ? 'true' : 'false' }}">Name</button>
                <button type="button" wire:click="sortBy('newest')" aria-pressed="{{ $sort === 'newest' ? 'true' : 'false' }}">Added</button>
            </div>
        </div>
    </div>

    <div class="nw-card overflow-hidden nw-table-responsive">
        <table class="w-full text-sm">
            <thead>
                <tr class="nw-topbar text-left">
                    <th class="p-3">Card</th>
                    <th class="p-3">Set</th>
                    <th class="p-3">Variants owned</th>
                    <th class="p-3">Total value</th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($cardGroups as $group)
                    <tr wire:key="group-{{ $group->card->id }}" wire:click="$dispatch('open-card-editor', { cardId: {{ $group->card->id }} })"
                        class="nw-stagger-item nw-row-hover border-t cursor-pointer" style="border-color: var(--hair); --nw-stagger-index: {{ min($loop->index, 10) }}">
                        <td class="p-3 font-medium nw-tcell-name" data-label="">
                            {{ $group->card->name }}
                            @if ($group->needsReview)
                                <span class="text-xs font-medium ml-2" style="color: var(--warning)" title="One or more variants need review — Assign a Variant in the card editor to clear this">Review</span>
                            @endif
                        </td>
                        <td class="p-3" style="color: var(--muted)" data-label="Set">{{ $group->card->set->name }}</td>
                        <td class="p-3" data-label="Variants owned">
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($group->items as $item)
                                    <span class="nw-variant-chip">
                                        {{ $item->variant ? \App\Modules\Catalog\Support\CardVariants::label($item->variant) : '— unspecified' }} · {{ $item->condition }}
                                        <span class="mono" style="color: var(--muted)">×{{ $item->quantity }}</span>
                                    </span>
                                @endforeach
                            </div>
                        </td>
                        <td class="p-3 mono" data-label="Total value">
                            @if ($group->totalValueMinor !== null)
                                {{ \App\Support\Money::format($group->totalValueMinor, $group->totalValueCurrency) }}
                            @elseif ($group->hasMixedCurrencyPricing)
                                <span title="This card's variants are priced in different currencies — no single total shown">Mixed currencies</span>
                            @else
                                <span title="No price data synced for this card/variant yet">—</span>
                            @endif
                        </td>
                        <td class="p-3 text-right nw-tcell-actions" data-label="" onclick="event.stopPropagation()">
                            <button id="card-editor-trigger-{{ $group->card->id }}" wire:click="$dispatch('open-card-editor', { cardId: {{ $group->card->id }} })" class="nw-row-btn">Edit</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-6 text-center" style="color: var(--muted)">No cards yet — <a href="{{ route('admin.collection.add') }}" wire:navigate style="color: var(--ink); text-decoration: underline">add your first one</a>.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $cardGroups->links() }}
    </div>

    <livewire:admin.card-editor />
</div>
