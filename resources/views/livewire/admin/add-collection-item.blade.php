<div class="max-w-2xl mx-auto py-10 px-4">
    <div class="nw-card p-6">
        <h1 class="text-xl font-semibold mb-4" style="color: var(--ink)">Add a card</h1>

        <div class="mb-4">
            <label class="block text-sm font-medium mb-1">Search tcgdex by name</label>
            <input type="text" wire:model.live.debounce.400ms="search" wire:keyup="runSearch"
                   class="w-full border rounded px-3 py-2" placeholder="e.g. Mega Darkrai ex">
        </div>

        @if (count($results) > 0)
            <div class="grid grid-cols-3 gap-3 mb-6">
                @foreach ($results as $result)
                    <button type="button" wire:click="selectCard('{{ $result->tcgdexId }}')"
                            class="border rounded p-2 text-left text-sm {{ $selectedTcgdexId === $result->tcgdexId ? 'ring-2' : '' }}"
                            style="{{ $selectedTcgdexId === $result->tcgdexId ? 'box-shadow: 0 0 0 2px var(--ink)' : '' }}">
                        @if ($result->imageUrl)
                            <img src="{{ $result->imageUrl }}" alt="{{ $result->name }}" class="w-full rounded mb-1">
                        @endif
                        <div class="font-medium">{{ $result->name }}</div>
                        <div class="mono text-xs" style="color: var(--muted)">{{ $result->tcgdexId }}</div>
                    </button>
                @endforeach
            </div>
        @endif

        @error('selectedTcgdexId') <p class="text-red-600 text-sm mb-3">{{ $message }}</p> @enderror

        @if ($selectedTcgdexId)
            <div class="mb-4 p-3 rounded" style="background: var(--bone-2)">
                Selected: <strong>{{ $selectedName }}</strong> ({{ $selectedTcgdexId }})
            </div>
        @endif

        <div class="grid grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-medium mb-1">Condition</label>
                <select wire:model="condition" class="w-full border rounded px-3 py-2">
                    <option value="NM">Near Mint</option>
                    <option value="LP">Lightly Played</option>
                    <option value="MP">Moderately Played</option>
                    <option value="HP">Heavily Played</option>
                    <option value="DMG">Damaged</option>
                </select>
                @error('condition') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Quantity</label>
                <input type="number" min="1" wire:model="quantity" class="w-full border rounded px-3 py-2">
                @error('quantity') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Grading company (optional)</label>
                <input type="text" wire:model="gradeCompany" class="w-full border rounded px-3 py-2" placeholder="PSA, BGS...">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Grade (optional)</label>
                <input type="text" wire:model="gradeValue" class="w-full border rounded px-3 py-2" placeholder="9, 10...">
            </div>
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium mb-1">Notes (optional)</label>
            <textarea wire:model="notes" rows="3" class="w-full border rounded px-3 py-2"></textarea>
        </div>

        <div class="mb-6">
            <label class="block text-sm font-medium mb-1">Your own photo (optional — falls back to tcgdex's official image)</label>
            <input type="file" wire:model="photo" accept="image/*">
            @if ($photo) <img src="{{ $photo->temporaryUrl() }}" class="mt-2 w-32 rounded"> @endif
        </div>

        <button type="button" wire:click="save" class="nw-btn-primary">Save to collection</button>
    </div>
</div>
