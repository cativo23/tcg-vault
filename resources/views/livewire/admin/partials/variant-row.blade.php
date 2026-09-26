@php
    // $namePrefix e.g. "editingRows.0" (Editar) or "rows.0" (Crear, Task 8) —
    // lets the same markup back both wire:model targets. $onUpdate/$onRemove
    // are the exact wire:click/wire:blur action strings each caller wants;
    // Crear passes null for $onUpdate (Task 8 saves the whole batch once,
    // not per-field) and a different remove action. $onUpdate is the same
    // action string for every field in this row — updateRow() re-validates
    // and re-saves the whole row regardless of which field changed, so
    // there's nothing per-field for it to select.
@endphp
<div class="p-4" style="border-bottom: 1px solid var(--hair); display: grid; grid-template-columns: 1fr 1fr 70px auto; gap: 10px; align-items: end; position: relative;" wire:key="{{ $namePrefix }}">
    <div wire:loading.class="opacity-50" wire:target="{{ $onUpdate ?: $namePrefix }}">
        <label class="nw-label block mb-1">Variant</label>
        <select wire:model="{{ $namePrefix }}.variant" @if($onUpdate) wire:change="{{ $onUpdate }}" @endif class="nw-input w-full">
            <option value="">— not specified —</option>
            @foreach ($availableVariants as $v)
                <option value="{{ $v }}">{{ \Illuminate\Support\Str::headline($v) }}</option>
            @endforeach
        </select>
        @error("{$namePrefix}.variant") <p class="text-sm mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="nw-label block mb-1">Condition</label>
        <select wire:model="{{ $namePrefix }}.condition" @if($onUpdate) wire:change="{{ $onUpdate }}" @endif class="nw-input w-full">
            <option value="NM">Near Mint</option>
            <option value="LP">Lightly Played</option>
            <option value="MP">Moderately Played</option>
            <option value="HP">Heavily Played</option>
            <option value="DMG">Damaged</option>
        </select>
        @error("{$namePrefix}.condition") <p class="text-sm mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="nw-label block mb-1">Qty</label>
        <input type="number" min="1" wire:model="{{ $namePrefix }}.quantity" @if($onUpdate) wire:blur="{{ $onUpdate }}" @endif class="nw-input w-full">
        @error("{$namePrefix}.quantity") <p class="text-sm mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
    </div>
    @if (($confirmingRemoveRowIndex ?? null) === $rowIndex)
        <div style="display:flex; gap:4px;">
            <button type="button" wire:click="removeVariantRow({{ $rowIndex }})" class="nw-row-btn nw-row-btn--danger" style="height: 34px;">Confirm</button>
            <button type="button" wire:click="cancelRemoveRow" class="nw-row-btn" aria-label="Cancel removing this variant" style="height: 34px;">✕</button>
        </div>
    @elseif ($onRemove)
        <button type="button" wire:click="{{ $onRemove }}" class="nw-row-btn nw-row-btn--danger" title="Remove this variant" aria-label="Remove this variant" style="height: 34px;">✕</button>
    @endif

    <div style="grid-column: 1 / -1;">
        <button type="button" wire:click="toggleRowDetails({{ $rowIndex }})" class="text-xs" style="color: var(--muted); text-decoration: underline; text-decoration-style: dashed;">
            {{ $row['showDetails'] ? 'Hide' : 'Add' }} grading, notes, or a photo
        </button>
    </div>

    {{-- Auto-expand on a hidden-field error too — a failed validation on
         grade_company/grade_value/notes/photo must be visible even when
         the user never opened this collapsible section. --}}
    @if ($row['showDetails'] || $errors->hasAny(["{$namePrefix}.grade_company", "{$namePrefix}.grade_value", "{$namePrefix}.notes", "{$namePrefix}.photo"]))
        <div style="grid-column: 1 / -1; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 8px;">
            <div>
                <label class="nw-label block mb-1">Grading company</label>
                <input type="text" wire:model="{{ $namePrefix }}.grade_company" @if($onUpdate) wire:blur="{{ $onUpdate }}" @endif class="nw-input w-full" placeholder="PSA, BGS...">
                @error("{$namePrefix}.grade_company") <p class="text-sm mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="nw-label block mb-1">Grade</label>
                <input type="text" wire:model="{{ $namePrefix }}.grade_value" @if($onUpdate) wire:blur="{{ $onUpdate }}" @endif class="nw-input w-full" placeholder="9, 10...">
                @error("{$namePrefix}.grade_value") <p class="text-sm mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
            </div>
            <div style="grid-column: 1 / -1;">
                <label class="nw-label block mb-1">Notes</label>
                <textarea wire:model="{{ $namePrefix }}.notes" @if($onUpdate) wire:blur="{{ $onUpdate }}" @endif rows="2" class="nw-input w-full"></textarea>
                @error("{$namePrefix}.notes") <p class="text-sm mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
            </div>
            <div style="grid-column: 1 / -1;">
                <label class="nw-label block mb-1">Your own photo (optional — falls back to tcgdex's official image)</label>
                <input type="file" wire:model="{{ $namePrefix }}.photo" accept="image/*">
                @error("{$namePrefix}.photo") <p class="text-sm mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
                @if ($row['photo'] ?? null)
                    <img src="{{ $row['photo']->temporaryUrl() }}" class="mt-2 w-32 rounded" alt="">
                @elseif ($row['photo_path'] ?? null)
                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('collection-photos')->url($row['photo_path']) }}" class="mt-2 w-32 rounded" alt="">
                @endif
            </div>
        </div>
    @endif

    <span data-autosave-tag class="autosave-tag" style="display:none;">Saved</span>
</div>
