    @if ($related->isNotEmpty())
        <div class="nw-section-head" style="border-top: 1px solid var(--hair); padding-top: 26px">
            <span>More from {{ $card->set->name }}</span>
            <a href="{{ $setUrl }}">View the set</a>
        </div>
        <div class="nw-strip">
            @foreach ($related as $entry)
                <x-card-tile
                    :card="$entry['card']"
                    :snapshot="$entry['snapshot']"
                    :delta="$entry['delta']"
                    :items="$entry['items']"
                    :index="$loop->index"
                    :show-set="false"
                    :href="route('gallery.card', ['username' => $targetUser->username, 'setTcgdexId' => $card->set->tcgdex_id, 'localId' => $entry['card']->local_id])"
                />
            @endforeach
        </div>
    @endif
