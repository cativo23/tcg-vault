<div class="nw-wrap py-10">
    <div class="flex items-center justify-between mb-4">
        <h1 class="nw-display nw-h1 nw-h1--sm">My Collection</h1>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.collection.import') }}" class="nw-btn-secondary">Import TCGplayer</a>
            <a href="{{ route('admin.collection.add') }}" class="nw-btn-primary">+ Add card</a>
        </div>
    </div>

    <div class="nw-toolbar mb-4">
        <div class="nw-count">Showing <b>{{ $items->total() }}</b> {{ Str::plural('card', $items->total()) }}</div>

        <div class="nw-toolbar-group">
            <label class="sr-only" for="collection-search">Search your collection</label>
            <input id="collection-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, set, notes…" class="nw-pill-input w-40 sm:w-52" autocomplete="off">

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

    <div class="nw-card overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="nw-topbar text-left">
                    <th class="p-3">Card</th>
                    <th class="p-3">Set</th>
                    <th class="p-3">Variant</th>
                    <th class="p-3">Condition</th>
                    <th class="p-3">Grading</th>
                    <th class="p-3">Qty</th>
                    <th class="p-3">Value</th>
                    <th class="p-3">Notes</th>
                    <th class="p-3">Status</th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    @php
                        // resolveForVariant, not resolve(): this row IS a
                        // specific variant — the card-level chain would
                        // price every row for a card identically, regardless
                        // of which variant each copy is.
                        $snapshot = $resolver->resolveForVariant($item->card, $item->variant);
                        $valueLabel = $snapshot?->market_minor !== null
                            ? \App\Support\Money::format($snapshot->market_minor * $item->quantity, $snapshot->currency)
                            : '—';
                        $gradingLabel = $item->grade_company && $item->grade_value
                            ? "{$item->grade_company} {$item->grade_value}"
                            : '—';
                    @endphp
                    <tr wire:key="item-{{ $item->id }}" class="nw-stagger-item nw-row-hover border-t" style="border-color: var(--hair); --nw-stagger-index: {{ min($loop->index, 10) }}">
                        <td class="p-3 font-medium">
                            @if ($item->photo_path)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('collection-photos')->url($item->photo_path) }}"
                                     alt="" class="w-10 h-10 object-cover rounded inline-block mr-2 align-middle">
                            @endif
                            {{ $item->card->name }}
                        </td>
                        <td class="p-3" style="color: var(--muted)">{{ $item->card->set->name }}</td>
                        <td class="p-3">{{ $item->variant ? \Illuminate\Support\Str::headline($item->variant) : '—' }}</td>
                        <td class="p-3 mono">{{ $item->condition }}</td>
                        <td class="p-3">{{ $gradingLabel }}</td>
                        <td class="p-3 mono">
                            @if ($editingQtyItemId === $item->id)
                                <input type="number" min="1" wire:model="editingQtyValue" wire:keydown.enter="saveQty" wire:blur="saveQty" class="border rounded px-2 py-1 w-16">
                                @error('editingQtyValue') <p class="text-xs mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
                            @else
                                <span wire:click="startEditingQty({{ $item->id }})" class="cursor-pointer">{{ $item->quantity }}</span>
                            @endif
                        </td>
                        <td class="p-3 mono">{{ $valueLabel }}</td>
                        <td class="p-3">
                            @if ($editingItemId === $item->id)
                                <input type="text" wire:model="editingNotes" wire:keydown.enter="saveNotes" class="border rounded px-2 py-1 w-full">
                            @else
                                <span wire:click="startEditingNotes({{ $item->id }})" class="cursor-pointer">{{ $item->notes ?: '—' }}</span>
                            @endif
                        </td>
                        <td class="p-3">
                            @if ($item->needs_variant_review)
                                <span class="text-xs font-medium" style="color: var(--danger)">Review</span>
                            @endif
                        </td>
                        <td class="p-3 text-right">
                            <button wire:click="startEditingItem({{ $item->id }})" class="text-xs mr-2" style="color: var(--ink)">Edit</button>
                            <button wire:click="confirmDelete({{ $item->id }})" class="text-xs" style="color: var(--danger)">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="p-6 text-center" style="color: var(--muted)">No cards yet — add your first one.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $items->links() }}
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
                        <select wire:model="editingCondition" class="w-full border rounded px-2 py-1">
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
                        <input type="number" min="1" wire:model="editingQuantity" class="w-full border rounded px-2 py-1">
                        @error('editingQuantity') <p class="text-xs mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--muted)">Variant</label>
                        <select wire:model="editingVariant" class="w-full border rounded px-2 py-1">
                            <option value="">— not specified —</option>
                            @foreach ($editingAvailableVariants as $v)
                                <option value="{{ $v }}">{{ \Illuminate\Support\Str::headline($v) }}</option>
                            @endforeach
                        </select>
                        @error('editingVariant') <p class="text-xs mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--muted)">Grading company</label>
                        <input type="text" wire:model="editingGradeCompany" class="w-full border rounded px-2 py-1" placeholder="PSA, BGS...">
                        @error('editingGradeCompany') <p class="text-xs mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--muted)">Grade</label>
                        <input type="text" wire:model="editingGradeValue" class="w-full border rounded px-2 py-1">
                        @error('editingGradeValue') <p class="text-xs mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
                    </div>
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
