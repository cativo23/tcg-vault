{{-- More from the set: a divider tab over a stock sheet with up to eight of
     the collector's other cards in it, each the collection's own tile (own-print
     price, 7-day move, stamps, slab, copies). `.col-page` supplies the custom
     properties the tile's rules read. Plain links: every card page is a full
     load. --}}
@if ($related->isNotEmpty())
    <section class="crd-related col-page" aria-labelledby="crd-rl-h">
        <div class="crd-rel-head">
            <h2 id="crd-rl-h" class="crd-rel-tab">More from {{ $card->set->name }}</h2>
            <a class="crd-setlink" href="{{ $setUrl }}">View the set</a>
        </div>
        <div class="crd-sheet crd-rel-sheet">
            <ol class="col-grid crd-strip">
                @foreach ($related as $entry)
                    <x-revamp.card-tile
                        :card="$entry['card']"
                        :snapshot="$entry['snapshot']"
                        :week-delta="$entry['weekDelta']"
                        :items="$entry['items']"
                        :href="route('gallery.card', ['username' => $targetUser->username, 'setTcgdexId' => $card->set->tcgdex_id, 'localId' => $entry['card']->local_id])"
                        :index="$loop->index" />
                @endforeach
            </ol>
        </div>
    </section>
@endif
