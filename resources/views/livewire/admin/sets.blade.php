<div class="nw-wrap py-10">
    <section class="nw-masthead">
        <div class="nw-eyebrow">
            <a href="{{ route('admin.collection.index') }}" wire:navigate>Collection</a>
            <span aria-hidden="true">/</span>
            <span>by official set</span>
        </div>
        <div class="flex flex-wrap items-end justify-between gap-x-8 gap-y-4">
            <h1 class="nw-display nw-h1 nw-h1--md">My sets</h1>
        </div>
    </section>

    @if ($sets->isEmpty())
        <div class="nw-empty">
            <div class="t">No sets yet</div>
            <p>
                Sets appear here once you own a card from them.
                <a href="{{ route('admin.collection.add') }}" wire:navigate style="color: var(--ink); text-decoration: underline">Add a card</a> to start one.
            </p>
        </div>
    @else
        <div class="nw-setgrid">
            @foreach ($sets as $set)
                <x-set-card
                    :set="$set"
                    :owned="$set->owned_card_count"
                    :total="$set->card_count ?? $set->real_card_count"
                    :href="url('/admin/sets/'.$set->tcgdex_id)"
                    :index="$loop->index"
                />
            @endforeach
        </div>
    @endif
</div>
