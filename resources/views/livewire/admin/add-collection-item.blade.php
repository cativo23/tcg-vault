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

        @if ($selectedTcgdexId)
            @foreach ($rows as $index => $row)
                @include('livewire.admin.partials.variant-row', [
                    'namePrefix' => "rows.$index",
                    'row' => $row,
                    'rowIndex' => $index,
                    'availableVariants' => $availableVariants,
                    'onRemove' => count($rows) > 1 ? "removeRow($index)" : null,
                    'onUpdate' => null,
                ])
            @endforeach

            <div class="mb-4">
                <button type="button" wire:click="addRow" class="nw-btn-secondary w-full" style="border-style: dashed;">+ Add another variant of this same card</button>
            </div>

            <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="nw-btn-primary w-full">Save {{ count($rows) }} {{ \Illuminate\Support\Str::plural('variant', count($rows)) }} to collection</button>
        @endif
    </div>
</div>
