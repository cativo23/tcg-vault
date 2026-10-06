{{-- The "other print" picker (PicksCustomPrint) for row $index. --}}
<details class="text-xs" style="color: var(--muted)">
    <summary class="cursor-pointer" style="text-decoration: underline; text-decoration-style: dashed;">Other print — one tcgdex doesn't list</summary>
    <div class="mt-2" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 8px; align-items: end;">
        <div>
            <label class="nw-label block mb-1" for="custom-base-{{ $index }}">Print</label>
            <select id="custom-base-{{ $index }}" wire:model="customBase" class="nw-input w-full">
                <option value="normal">Normal</option>
                <option value="holofoil">Holofoil</option>
                <option value="reverse-holofoil">Reverse Holofoil</option>
            </select>
        </div>
        <div>
            <label class="nw-label block mb-1" for="custom-foil-{{ $index }}">Foil</label>
            <select id="custom-foil-{{ $index }}" wire:model="customFoil" class="nw-input w-full">
                <option value="">— none —</option>
                @foreach (\App\Modules\Catalog\Support\CardVariants::foilOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="nw-label block mb-1" for="custom-stamp-{{ $index }}">Stamp</label>
            <select id="custom-stamp-{{ $index }}" wire:model="customStamp" class="nw-input w-full">
                <option value="">— none —</option>
                @foreach (\App\Modules\Catalog\Support\CardVariants::stampOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="button" wire:click="useCustomVariant({{ $index }})" class="nw-btn-secondary" style="height: 34px;">Use</button>
    </div>
    @error('customFoil') <p class="text-sm mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
</details>
