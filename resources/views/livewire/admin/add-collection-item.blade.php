<div class="max-w-2xl mx-auto py-10 px-4">
    <div class="nw-card p-6">
        <h1 class="text-xl font-semibold mb-4" style="color: var(--ink)">Add a card</h1>

        <div class="mb-4">
            <x-input-label value="Set" />
            <select wire:model.live="setFilter" class="nw-input w-full">
                <option value="">All sets</option>
                @foreach ($availableSets as $tcgdexId => $name)
                    <option value="{{ $tcgdexId }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="mb-4">
            <x-input-label value="Search tcgdex by name" />
            <span class="nw-search-wrap w-full">
                <input type="text" wire:model.live.debounce.400ms="search" wire:keyup="runSearch"
                       class="nw-input w-full" placeholder="e.g. Mega Darkrai ex">
                <span wire:loading wire:target="search,runSearch" class="nw-search-loading" aria-hidden="true"></span>
            </span>
            @error('search') <p class="text-sm mt-1" style="color: var(--danger)">{{ $message }}</p> @enderror
        </div>

        @if (count($results) > 0)
            @if ($hasMoreResults && $setFilter === null)
                <p class="text-xs mb-2" style="color: var(--muted)">
                    Showing the first {{ count($results) }} matches across every set — pick a set above to narrow this down.
                </p>
            @endif

            {{-- A visible spinner next to the input isn't enough on its own —
                 the tiles below it still show the PREVIOUS query's results
                 unchanged while the new one is in flight, which reads as
                 "nothing happened yet" or worse, as the actual answer.
                 Dimming them ties the stale content to the same loading
                 state instead of leaving it looking current. --}}
            <div wire:loading.class="opacity-40" wire:target="search,runSearch" class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-3">
                @foreach ($results as $result)
                    <button type="button" wire:click="selectCard(@js($result->tcgdexId))"
                            class="nw-stagger-item border rounded p-2 text-left text-sm {{ $selectedTcgdexId === $result->tcgdexId ? 'ring-2' : '' }}"
                            style="--nw-stagger-index: {{ min($loop->index, 10) }}; {{ $selectedTcgdexId === $result->tcgdexId ? 'box-shadow: 0 0 0 2px var(--ink)' : '' }}">
                        @if ($result->imageUrl)
                            <img src="{{ $result->imageUrl }}" alt="{{ $result->name }}" class="w-full rounded mb-1">
                        @endif
                        <div class="font-medium">{{ $result->name }}</div>
                        <div class="text-xs" style="color: var(--muted)">{{ $resultSetNames[$result->setTcgdexId] ?? $result->setTcgdexId }}</div>
                        <div class="mono text-xs" style="color: var(--muted)">{{ $result->tcgdexId }}</div>
                    </button>
                @endforeach
            </div>

            @if ($hasMoreResults)
                <div wire:key="load-more-{{ $search }}-{{ $setFilter }}-{{ $searchPage }}" wire:intersect="loadMoreResults" wire:loading.class="opacity-50" class="flex justify-center mb-6">
                    <button type="button" wire:click="loadMoreResults" class="nw-btn-secondary">Load more</button>
                </div>
            @else
                <div class="mb-6"></div>
            @endif
        @endif

        @error('selectedTcgdexId') <p class="text-sm mb-3" style="color: var(--danger)">{{ $message }}</p> @enderror

        @if ($selectedTcgdexId)
            <div class="mb-4 p-3 rounded" style="background: var(--bone-2)">
                Selected: <strong>{{ $selectedName }}</strong> ({{ $selectedTcgdexId }})
            </div>
        @endif

        <div class="grid grid-cols-2 gap-4 mb-4">
            <div>
                <x-input-label value="Condition" />
                <select wire:model="condition" class="nw-input w-full">
                    <option value="NM">Near Mint</option>
                    <option value="LP">Lightly Played</option>
                    <option value="MP">Moderately Played</option>
                    <option value="HP">Heavily Played</option>
                    <option value="DMG">Damaged</option>
                </select>
                @error('condition') <p class="text-sm" style="color: var(--danger)">{{ $message }}</p> @enderror
            </div>
            <div>
                <x-input-label value="Quantity" />
                <input type="number" min="1" wire:model="quantity" class="nw-input w-full">
                @error('quantity') <p class="text-sm" style="color: var(--danger)">{{ $message }}</p> @enderror
            </div>
            <div>
                <x-input-label value="Variant" />
                <select wire:model="variant" class="nw-input w-full">
                    <option value="">— not specified —</option>
                    @foreach ($availableVariants as $v)
                        <option value="{{ $v }}">{{ \Illuminate\Support\Str::headline($v) }}</option>
                    @endforeach
                </select>
                @error('variant') <p class="text-sm" style="color: var(--danger)">{{ $message }}</p> @enderror
            </div>
            <div>
                <x-input-label value="Grading company (optional)" />
                <input type="text" wire:model="gradeCompany" class="nw-input w-full" placeholder="PSA, BGS...">
            </div>
            <div>
                <x-input-label value="Grade (optional)" />
                <input type="text" wire:model="gradeValue" class="nw-input w-full" placeholder="9, 10...">
            </div>
        </div>

        <div class="mb-4">
            <x-input-label value="Notes (optional)" />
            <textarea wire:model="notes" rows="3" class="nw-input w-full"></textarea>
        </div>

        <div class="mb-6">
            <x-input-label value="Your own photo (optional — falls back to tcgdex's official image)" />
            <input type="file" wire:model="photo" accept="image/*">
            @if ($photo) <img src="{{ $photo->temporaryUrl() }}" class="mt-2 w-32 rounded"> @endif
        </div>

        <button type="button" wire:click="save" class="nw-btn-primary">Save to collection</button>
    </div>
</div>
