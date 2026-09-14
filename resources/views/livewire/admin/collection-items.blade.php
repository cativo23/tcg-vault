<div class="max-w-4xl mx-auto py-10 px-4">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-semibold" style="color: var(--ink)">My Collection</h1>
        <a href="{{ route('admin.collection.add') }}" class="nw-btn-primary">+ Add card</a>
    </div>

    <div class="nw-card overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="nw-topbar text-left">
                    <th class="p-3">Card</th>
                    <th class="p-3">Set</th>
                    <th class="p-3">Condition</th>
                    <th class="p-3">Qty</th>
                    <th class="p-3">Notes</th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr class="border-t" style="border-color: var(--hair)">
                        <td class="p-3 font-medium">
                            @if ($item->photo_path)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('collection-photos')->url($item->photo_path) }}"
                                     alt="" class="w-10 h-10 object-cover rounded inline-block mr-2 align-middle">
                            @endif
                            {{ $item->card->name }}
                        </td>
                        <td class="p-3" style="color: var(--muted)">{{ $item->card->set->name }}</td>
                        <td class="p-3 mono">{{ $item->condition }}</td>
                        <td class="p-3 mono">{{ $item->quantity }}</td>
                        <td class="p-3">
                            @if ($editingItemId === $item->id)
                                <input type="text" wire:model="editingNotes" wire:keydown.enter="saveNotes" class="border rounded px-2 py-1 w-full">
                            @else
                                <span wire:click="startEditingNotes({{ $item->id }})" class="cursor-pointer">{{ $item->notes ?: '—' }}</span>
                            @endif
                        </td>
                        <td class="p-3 text-right">
                            <button wire:click="startEditingItem({{ $item->id }})" class="text-xs mr-2" style="color: var(--ink)">Edit</button>
                            <button wire:click="delete({{ $item->id }})" wire:confirm="Remove this card from your collection?" class="text-xs" style="color: var(--danger)">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-6 text-center" style="color: var(--muted)">No cards yet — add your first one.</td></tr>
                @endforelse
            </tbody>
        </table>
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
</div>
