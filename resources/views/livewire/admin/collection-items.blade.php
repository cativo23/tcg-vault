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
                                <span class="text-xs font-medium ml-2" style="color: var(--warning)" title="One or more variants need review">Review</span>
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
                            {{ $group->totalValueMinor > 0 ? \App\Support\Money::format($group->totalValueMinor, 'USD') : '—' }}
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

    @if ($editingFullItemId !== null)
        <div class="fixed inset-0 z-40 flex items-center justify-center p-4"
             style="background: rgba(20,20,18,.5)"
             wire:click.self="cancelEditingItem"
             wire:keydown.escape.window="cancelEditingItem">
            <div class="nw-card modal-in w-full max-w-md p-5">
                <h2 class="text-lg font-semibold mb-4" style="color: var(--ink)">Edit item</h2>
                <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(140px, 1fr))">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--muted)">Condition</label>
                        <select wire:model="editingCondition" class="nw-input w-full">
                            <option value="NM">Near Mint</option>
                            <option value="LP">Lightly Played</option>
                            <option value="MP">Moderately Played</option>
                            <option value="HP">Heavily Played</option>
                            <option value="DMG">Damaged</option>
                        </select>
                        @error('editingCondition') <p class="text-xs mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--muted)">Quantity</label>
                        <input type="number" min="1" wire:model="editingQuantity" class="nw-input w-full">
                        @error('editingQuantity') <p class="text-xs mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--muted)">Variant</label>
                        <select wire:model="editingVariant" class="nw-input w-full">
                            <option value="">— not specified —</option>
                            @foreach ($editingAvailableVariants as $v)
                                <option value="{{ $v }}">{{ \Illuminate\Support\Str::headline($v) }}</option>
                            @endforeach
                        </select>
                        @error('editingVariant') <p class="text-xs mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--muted)">Grading company</label>
                        <input type="text" wire:model="editingGradeCompany" class="nw-input w-full" placeholder="PSA, BGS...">
                        @error('editingGradeCompany') <p class="text-xs mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--muted)">Grade</label>
                        <input type="text" wire:model="editingGradeValue" class="nw-input w-full">
                        @error('editingGradeValue') <p class="text-xs mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-3">
                    <label class="block text-xs font-medium mb-1" style="color: var(--muted)">Notes</label>
                    <textarea wire:model="editingNotes" rows="2" class="nw-input w-full"></textarea>
                    @error('editingNotes') <p class="text-xs mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
                </div>

                <div class="mt-3">
                    <label class="block text-xs font-medium mb-1" style="color: var(--muted)">
                        {{ $this->editingItemPhotoPath ? 'Replace photo' : 'Add a photo' }}
                    </label>
                    @if ($editingPhoto)
                        <img src="{{ $editingPhoto->temporaryUrl() }}" class="w-20 h-20 object-cover rounded mb-2">
                    @elseif ($this->editingItemPhotoPath)
                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('collection-photos')->url($this->editingItemPhotoPath) }}" class="w-20 h-20 object-cover rounded mb-2">
                    @endif
                    <input type="file" wire:model="editingPhoto" accept="image/*" class="text-sm">
                    @error('editingPhoto') <p class="text-xs mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
                </div>

                <div class="mt-4 flex gap-2">
                    <button wire:click="saveItem" class="nw-btn-primary text-sm px-4 py-2">Save</button>
                    <button wire:click="cancelEditingItem" class="text-sm px-4 py-2" style="color: var(--muted)">Cancel</button>
                </div>
            </div>
        </div>
    @endif

    @if ($confirmingDeleteItemId !== null)
        <div class="fixed inset-0 z-40 flex items-center justify-center p-4"
             style="background: rgba(20,20,18,.5)"
             wire:click.self="cancelDelete"
             wire:keydown.escape.window="cancelDelete">
            <div class="nw-card modal-in w-full max-w-sm p-5">
                <h2 class="text-lg font-semibold mb-2" style="color: var(--ink)">Remove this card?</h2>
                @if ($deletingSummary !== [])
                    <p class="text-sm mb-4" style="color: var(--muted)">
                        {{ $deletingSummary['name'] }}
                        @if ($deletingSummary['variant']) &middot; {{ \Illuminate\Support\Str::headline($deletingSummary['variant']) }} @endif
                        &middot; qty {{ $deletingSummary['quantity'] }}
                    </p>
                @endif
                <div class="flex gap-2">
                    <button wire:click="delete({{ $confirmingDeleteItemId }})" class="nw-btn-danger text-sm px-4 py-2">Delete</button>
                    <button wire:click="cancelDelete" class="text-sm px-4 py-2" style="color: var(--muted)">Cancel</button>
                </div>
            </div>
        </div>
    @endif
</div>
