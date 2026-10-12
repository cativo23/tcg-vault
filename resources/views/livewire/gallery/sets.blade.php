<div class="nw-wrap">
    <section class="nw-masthead">
        <div class="nw-eyebrow"><span>{{ $targetUser->username }} · by official set</span></div>
        <h1 class="nw-display nw-h1 nw-h1--md">Sets</h1>
    </section>

    @if ($sets->isEmpty())
        <div class="nw-empty">
            <div class="t">No sets yet</div>
            <p>Sets appear here once at least one card from them is in the public collection.</p>
        </div>
    @else
        <div class="nw-setgrid">
            @foreach ($sets as $set)
                <x-set-card
                    :set="$set"
                    :owned="$set->owned_card_count"
                    :total="$set->card_count ?: $set->real_card_count"
                    :href="route('gallery.show', ['username' => $targetUser->username, 'setTcgdexId' => $set->tcgdex_id])"
                    :index="$loop->index"
                >
                    <x-slot:value><x-value-totals :totals="$setValues[$set->id]" /></x-slot:value>
                </x-set-card>
            @endforeach
        </div>
    @endif
</div>
