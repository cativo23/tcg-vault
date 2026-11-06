@use('App\Modules\Catalog\Support\CardVariants')

{{-- The collector's copies as ticket stubs (quantity on the stub, the rest on
     the ticket), or one sentence when the card is not theirs; beside them the
     card's facts as a catalogue label. --}}
@php
    $conditionNames = ['NM' => 'Near Mint', 'LP' => 'Lightly Played', 'MP' => 'Moderately Played', 'HP' => 'Heavily Played', 'DMG' => 'Damaged'];
    $numeric = ['HP', 'Pokédex'];
@endphp
<div class="crd-lower">
    <section class="crd-copies" aria-labelledby="crd-cp-h">
        <div class="crd-sec-head">
            @if ($owned)
                <h2 id="crd-cp-h" class="crd-sec-t">In the collection · {{ $quantity }} {{ Str::plural('copy', $quantity) }}</h2>
                @if ($ownedTotal)
                    <span class="crd-sec-r"><span class="crd-mono crd-total"><x-value-totals :totals="$ownedTotal" /></span> total</span>
                @endif
            @else
                <h2 id="crd-cp-h" class="crd-sec-t">Collection</h2>
            @endif
        </div>

        @if (! $owned)
            <p class="crd-note-text">This card is in <a href="{{ $setUrl }}">{{ $card->set->name }}</a>, but not in {{ $targetUser->username }}'s collection yet.</p>
        @elseif ($switcher && $items->isEmpty())
            <p class="crd-note-text crd-note-text--muted">No {{ $selected['label'] }} copies. {{ $targetUser->username }} holds other variants of this card.</p>
        @else
            <ol class="crd-stubs">
                @foreach ($items as $item)
                    <li class="crd-stub" wire:key="copy-{{ $item->id }}">
                        <div class="crd-stub-q crd-mono" aria-label="{{ $item->quantity }} {{ Str::plural('copy', $item->quantity) }}">×{{ $item->quantity }}</div>
                        <div class="crd-stub-b">
                            <div class="crd-stub-desc">
                                @if ($item->grade_company && $item->grade_value)
                                    {{ $item->grade_company }} {{ $item->grade_value }} <span class="crd-stub-aside">graded</span>
                                @else
                                    {{ $conditionNames[$item->condition] ?? $item->condition }} <span class="crd-stub-aside crd-mono">{{ $item->condition }}</span>
                                @endif
                            </div>
                            <div class="crd-stub-meta">
                                @if ($item->variant){{ CardVariants::label($item->variant) }} · @endif
                                Added <span class="crd-mono">{{ $item->created_at->format('j M Y') }}</span>
                                @if ($item->photo_path) · own photo @endif
                            </div>
                            @if ($item->notes)
                                <p class="crd-stub-notes">{{ $item->notes }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    @if ($facts !== [])
        <section class="crd-facts" aria-labelledby="crd-ft-h">
            <div class="crd-sec-head"><h2 id="crd-ft-h" class="crd-sec-t">Card</h2></div>
            <dl class="crd-kv">
                @foreach ($facts as $k => $v)
                    <div><dt>{{ $k }}</dt><dd{!! in_array($k, $numeric, true) ? ' class="crd-mono"' : '' !!}>{{ $v }}</dd></div>
                @endforeach
                <div><dt>Catalog ID</dt><dd class="crd-mono">{{ $card->tcgdex_id }}</dd></div>
            </dl>
        </section>
    @endif
</div>
