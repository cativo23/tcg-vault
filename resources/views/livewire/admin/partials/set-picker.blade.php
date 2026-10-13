@if ($pickerOpen)
    <div class="nw-drawer-backdrop" wire:click.self="closePicker" wire:keydown.escape.window="closePicker">
        <div
            class="nw-card nw-drawer nw-pick"
            role="dialog"
            aria-modal="true"
            aria-labelledby="set-picker-title"
            x-data="focusTrap"
            x-init="$nextTick(() => $refs.search?.focus())"
            x-on:keydown.tab.window.prevent="$event.shiftKey || nextFocusable().focus()"
            x-on:keydown.shift.tab.window.prevent="prevFocusable().focus()"
        >
            <div class="flex justify-between items-start p-5" style="border-bottom: 1px solid var(--hair)">
                <div>
                    <div id="set-picker-title" class="text-lg font-bold">Add a set</div>
                    <p class="text-sm" style="color: var(--muted)">Pick a set to open its grid. Its cards load in the background.</p>
                </div>
                <button type="button" wire:click="closePicker" class="nw-row-btn nw-dialog-close" aria-label="Close">✕</button>
            </div>

            <div class="p-4">
                <label class="sr-only" for="set-picker-search">Filter sets by name or id</label>
                <span class="nw-search-wrap w-full">
                    <input id="set-picker-search" x-ref="search" type="search" autocomplete="off"
                           wire:model.live.debounce.250ms="pickerSearch"
                           class="nw-pill-input w-full" placeholder="Search by name or id" maxlength="100">
                    <span wire:loading wire:target="pickerSearch" class="nw-search-loading" aria-hidden="true"></span>
                </span>
                @if ($pickerError)
                    <p class="text-sm mt-3" style="color: var(--danger)" role="alert">{{ $pickerError }}</p>
                @endif
                <p id="set-picker-adding" class="text-sm mt-3" style="color: var(--muted)" role="status">
                    <span wire:loading wire:target="chooseSet">Adding the set…</span>
                </p>
                @unless ($picker['failed'])
                    <p class="text-sm mt-3" style="color: var(--muted)" role="status">{{ count($picker['sets']) }} of {{ $picker['total'] }} sets</p>
                @endunless
            </div>

            @if ($picker['failed'])
                <div class="nw-empty" style="margin: 0 16px 16px" role="alert">
                    <div class="t">Couldn't load the set list</div>
                    <p>tcgdex did not answer. Nothing was changed.@if ($picker['backoff']) Try again in a minute.@endif</p>
                    <button type="button" class="nw-btn-secondary mt-4" wire:click="$refresh">Retry</button>
                </div>
            @elseif ($picker['sets'] === [])
                <p class="px-5 pb-6 text-sm" style="color: var(--muted)">No sets match “{{ $pickerSearch }}”.</p>
            @else
                <ul class="nw-pick-list">
                    @foreach ($picker['sets'] as $candidate)
                        <li wire:key="pick-{{ $candidate->tcgdexId }}">
                            <button type="button" class="nw-pick-row" wire:click="chooseSet(@js($candidate->tcgdexId))" wire:loading.class="is-busy" wire:target="chooseSet">
                                <span class="logo">
                                    @if ($candidate->logoUrl)
                                        <img src="{{ $candidate->logoUrl }}" alt="" loading="lazy" decoding="async" data-optional>
                                    @endif
                                    <span class="placeholder">{{ Str::upper($candidate->tcgdexId) }}</span>
                                </span>
                                <span class="meta">
                                    <span class="name">{{ $candidate->name }}</span>
                                    <span class="sub">{{ Str::upper($candidate->tcgdexId) }} · <span class="mono">{{ $candidate->cardCount ?? '?' }}</span> cards</span>
                                </span>
                                @if (in_array($candidate->tcgdexId, $ownedIds, true))
                                    <span class="tag">In My sets</span>
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endif
