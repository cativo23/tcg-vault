<div class="nw-wrap py-10" x-data x-on:set-picker-closed.window="$nextTick(() => document.getElementById('add-set-trigger')?.focus())">
    <section class="nw-masthead">
        <div class="nw-eyebrow">
            <a href="{{ route('admin.collection.index') }}" wire:navigate>Collection</a>
            <span aria-hidden="true">/</span>
            <span>by official set</span>
        </div>
        <div class="flex flex-wrap items-end justify-between gap-x-8 gap-y-4">
            <h1 class="nw-display nw-h1 nw-h1--md">My sets</h1>
            <button type="button" id="add-set-trigger" class="nw-btn-primary" wire:click="openPicker" wire:loading.attr="aria-busy" wire:target="openPicker" aria-haspopup="dialog">
                <span wire:loading.remove wire:target="openPicker">Add a set</span>
                <span wire:loading wire:target="openPicker">Loading…</span>
            </button>
        </div>
    </section>

    @if ($sets->isEmpty())
        <div class="nw-empty">
            <div class="t">No sets yet</div>
            <p>
                Sets appear here once you own a card from them.
                <a href="{{ route('admin.collection.add') }}" wire:navigate class="nw-inline-link">Add a card</a> to start one, or <button type="button" wire:click="openPicker" class="nw-inline-link">start a whole set</button>.
            </p>
        </div>
    @else
        <div class="nw-setgrid">
            @foreach ($sets as $set)
                <x-set-card
                    :set="$set"
                    :owned="$set->owned_card_count"
                    :total="$set->card_count ?: $set->real_card_count"
                    :href="route('admin.sets.show', ['setTcgdexId' => $set->tcgdex_id])"
                    :index="$loop->index"
                />
            @endforeach
        </div>
    @endif

    @include('livewire.admin.partials.set-picker')
</div>
