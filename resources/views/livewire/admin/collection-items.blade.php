<div class="nw-wrap py-10">
    {{-- flex-wrap, not a fixed row: .nw-h1's overflow-wrap:anywhere (needed
         elsewhere for long usernames) breaks text letter-by-letter once a
         flex sibling squeezes it below one word's width — happened here
         once the button row grew to 3 items. Wrapping the buttons to their
         own line on narrow screens keeps the heading at its natural size
         instead of fighting it for the same row. --}}
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h1 class="nw-display nw-h1 nw-h1--sm">My Collection</h1>
        <div class="flex items-center gap-2 flex-wrap">
            {{-- No separate Save step — same instant-toggle pattern as
                 "Needs review" below, since this is reversible any time,
                 not a destructive action that needs a confirm step. --}}
            <div class="nw-seg" role="group" aria-label="Visibility">
                <button type="button" wire:click="toggleVisibility" aria-pressed="{{ $isPublic ? 'true' : 'false' }}">
                    {{ $isPublic ? 'Public' : 'Private' }}
                </button>
            </div>
            <a href="{{ route('admin.collection.import') }}" class="nw-btn-secondary">Import TCGplayer</a>
            <a href="{{ route('admin.collection.add') }}" class="nw-btn-primary">+ Add card</a>
        </div>
    </div>

    <div class="nw-toolbar mb-4">
        <div class="nw-count">Showing <b>{{ $totalCards }}</b> {{ Str::plural('card', $totalCards) }} <span style="opacity:.6">· {{ $totalCopies }} {{ Str::plural('copy', $totalCopies) }}</span></div>

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
                    <tr wire:key="group-{{ $group->card->id }}" wire:click="openCardEditor({{ $group->card->id }})"
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
                                    <span class="nw-chip">
                                        {{ $item->variant ? \Illuminate\Support\Str::headline($item->variant) : '— unspecified' }} · {{ $item->condition }}
                                        <span class="mono" style="color: var(--muted)">×{{ $item->quantity }}</span>
                                    </span>
                                @endforeach
                            </div>
                        </td>
                        <td class="p-3 mono" data-label="Total value">
                            @if ($group->totalValueMinor > 0)
                                {{ \App\Support\Money::format($group->totalValueMinor, 'USD') }}
                            @else
                                <span title="No price data synced for this card/variant yet">—</span>
                            @endif
                        </td>
                        <td class="p-3 text-right nw-tcell-actions" data-label="" onclick="event.stopPropagation()">
                            <button wire:click="openCardEditor({{ $group->card->id }})" class="nw-row-btn">Edit</button>
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

    @if ($editingCardId !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(20,20,18,.55)" wire:click.self="closeCardEditor">
            <div class="nw-card w-full" style="max-width: 640px; max-height: 90vh; overflow-y: auto;">
                <div class="flex justify-between items-start p-5" style="border-bottom: 1px solid var(--hair)">
                    <div>
                        <div class="text-lg font-bold">{{ $editingCardName }}</div>
                    </div>
                    <button wire:click="closeCardEditor" class="nw-row-btn" aria-label="Close">✕</button>
                </div>

                @foreach ($editingRows as $index => $row)
                    @include('livewire.admin.partials.variant-row', [
                        'namePrefix' => "editingRows.$index",
                        'row' => $row,
                        'rowIndex' => $index,
                        'availableVariants' => $editingAvailableVariants,
                        'onRemove' => "confirmRemoveRow($index)",
                        'confirmingRemoveRowIndex' => $confirmingRemoveRowIndex,
                        'onUpdate' => fn (string $field) => "updateRow($index, '$field')",
                    ])
                @endforeach

                <div class="p-4 flex justify-center">
                    <button wire:click="addVariantRow" class="nw-btn-secondary w-full" style="border-style: dashed;">+ Add another variant to this card</button>
                </div>

                <div class="p-5 flex justify-end" style="border-top: 1px solid var(--hair)">
                    <button wire:click="closeCardEditor" class="nw-btn-primary">Done</button>
                </div>
            </div>
        </div>
    @endif
</div>
