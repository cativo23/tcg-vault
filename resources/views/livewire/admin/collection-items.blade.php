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
                        <td class="p-3 font-medium">{{ $item->card->name }}</td>
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
                            <button wire:click="delete({{ $item->id }})" wire:confirm="Remove this card from your collection?" class="text-red-600 text-xs">Delete</button>
                        </td>
                    </tr>
                    @if ($editingFullItemId === $item->id)
                        <tr class="border-t" style="border-color: var(--hair)">
                            <td colspan="6" class="p-0">
                                <div class="nw-card m-3 p-4">
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
                                            @error('editingCondition') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium mb-1" style="color: var(--muted)">Quantity</label>
                                            <input type="number" min="1" wire:model="editingQuantity" class="w-full border rounded px-2 py-1">
                                            @error('editingQuantity') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium mb-1" style="color: var(--muted)">Variant</label>
                                            <input type="text" wire:model="editingVariant" class="w-full border rounded px-2 py-1">
                                            @error('editingVariant') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium mb-1" style="color: var(--muted)">Grading company</label>
                                            <input type="text" wire:model="editingGradeCompany" class="w-full border rounded px-2 py-1" placeholder="PSA, BGS...">
                                            @error('editingGradeCompany') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium mb-1" style="color: var(--muted)">Grade</label>
                                            <input type="text" wire:model="editingGradeValue" class="w-full border rounded px-2 py-1">
                                            @error('editingGradeValue') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                                        </div>
                                    </div>
                                    <div class="mt-3 flex gap-2">
                                        <button wire:click="saveItem" class="nw-btn-primary text-xs px-3 py-1.5">Save</button>
                                        <button wire:click="cancelEditingItem" class="text-xs px-3 py-1.5" style="color: var(--muted)">Cancel</button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="6" class="p-6 text-center" style="color: var(--muted)">No cards yet — add your first one.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
