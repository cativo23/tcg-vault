<div>
    @if ($editingCardId !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(20,20,18,.55)" wire:click.self="closeCardEditor" wire:keydown.escape.window="closeCardEditor">
            <div
                class="nw-card modal-in w-full"
                style="max-width: 640px; max-height: 90vh; overflow-y: auto;"
                role="dialog"
                aria-modal="true"
                aria-labelledby="card-editor-title"
                x-data="{
                    // Same focus-trap shape as the modal component in
                    // resources/views/components/modal.blade.php —
                    // duplicated rather than shared, since this modal is
                    // toggled by a Livewire property re-rendering the
                    // whole subtree, not that component's show/x-show state.
                    focusables() {
                        let selector = 'a, button, input:not([type=\'hidden\']), textarea, select, [tabindex]:not([tabindex=\'-1\'])'
                        return [...$el.querySelectorAll(selector)].filter(el => ! el.hasAttribute('disabled'))
                    },
                    firstFocusable() { return this.focusables()[0] },
                    lastFocusable() { return this.focusables().slice(-1)[0] },
                    nextFocusable() { return this.focusables()[this.nextFocusableIndex()] || this.firstFocusable() },
                    prevFocusable() { return this.focusables()[this.prevFocusableIndex()] || this.lastFocusable() },
                    nextFocusableIndex() { return (this.focusables().indexOf(document.activeElement) + 1) % (this.focusables().length + 1) },
                    prevFocusableIndex() { return Math.max(0, this.focusables().indexOf(document.activeElement)) - 1 },
                }"
                x-init="$nextTick(() => firstFocusable()?.focus())"
                x-on:keydown.tab.window.prevent="$event.shiftKey || nextFocusable().focus()"
                x-on:keydown.shift.tab.window.prevent="prevFocusable().focus()"
            >
                <div class="flex justify-between items-start p-5" style="border-bottom: 1px solid var(--hair)">
                    <div>
                        <div id="card-editor-title" class="text-lg font-bold">{{ $editingCardName }}</div>
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
                        'onUpdate' => "updateRow($index)",
                    ])
                    <div class="px-4 pb-4" style="border-bottom: 1px solid var(--hair);">
                        @include('livewire.admin.partials.custom-print-picker', ['index' => $index])

                        @can('manage-catalog-prices')
                            <div class="mt-3" style="display: grid; grid-template-columns: 1fr auto; gap: 8px; align-items: end;">
                                <div>
                                    <label class="nw-label block mb-1" for="manual-price-{{ $index }}">Manual market price (USD)</label>
                                    <input id="manual-price-{{ $index }}" type="number" step="0.01" min="0.01" wire:model="editingRows.{{ $index }}.manual_price" class="nw-input w-full" placeholder="For a print tcgdex doesn't price">
                                </div>
                                <button type="button" wire:click="saveManualPrice({{ $index }})" class="nw-btn-secondary" style="height: 34px;" aria-label="Save manual price for variant {{ $index + 1 }}">Save price</button>
                            </div>
                            @error("editingRows.$index.manual_price") <p class="text-sm mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
                        @endcan
                    </div>
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
