@php $conditionNames = ['NM' => 'Near Mint', 'LP' => 'Lightly Played', 'MP' => 'Moderately Played', 'HP' => 'Heavily Played', 'DMG' => 'Damaged']; @endphp

            {{-- Copies --}}
            @if ($owned)
                <div class="nw-section-head mt-8">
                    <span>In the collection · {{ $quantity }} {{ Str::plural('copy', $quantity) }}</span>
                    @if ($ownedTotal)
                        <span class="mono" style="color: var(--ink); letter-spacing: -.03em"><x-value-totals :totals="$ownedTotal" /> total</span>
                    @endif
                </div>
                <div style="border-top: 1px solid var(--hair)">
                    @if ($switcher && $items->isEmpty())
                        <p class="text-sm py-2" style="color: var(--muted)">No {{ $selected['label'] }} copies — {{ $targetUser->username }} holds other variants of this card.</p>
                    @endif
                    @foreach ($items as $item)
                        <div class="nw-copy">
                            <div class="qty">×{{ $item->quantity }}</div>
                            <div class="min-w-0">
                                <div class="desc">
                                    @if ($item->grade_company && $item->grade_value)
                                        {{ $item->grade_company }} {{ $item->grade_value }} <span style="color: var(--muted)">· graded</span>
                                    @else
                                        {{ $conditionNames[$item->condition] ?? $item->condition }} <span class="mono text-xs" style="color: var(--muted)">{{ $item->condition }}</span>
                                    @endif
                                </div>
                                <div class="meta">
                                    @if ($item->variant){{ \App\Modules\Catalog\Support\CardVariants::label($item->variant) }} · @endif
                                    Added {{ $item->created_at->format('j M Y') }}
                                    @if ($item->photo_path) · own photo @endif
                                </div>
                                @if ($item->notes)
                                    <div class="notes">{{ $item->notes }}</div>
                                @endif
                            </div>
                            <div></div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="nw-section-head mt-8"><span>Collection</span></div>
                <p class="text-sm py-2" style="color: var(--muted)">This card is in <a href="{{ $setUrl }}" class="underline underline-offset-[3px]" style="color: var(--ink)">{{ $card->set->name }}</a>, but not in {{ $targetUser->username }}'s collection yet.</p>
            @endif

            {{-- Card facts --}}
            @if ($facts !== [])
                <div class="nw-section-head mt-8"><span>Card</span></div>
                <dl class="nw-kv">
                    @foreach ($facts as $k => $v)
                        <div><dt class="k">{{ $k }}</dt><dd class="v">{{ $v }}</dd></div>
                    @endforeach
                    <div><dt class="k">Catalog ID</dt><dd class="v mono text-xs">{{ $card->tcgdex_id }}</dd></div>
                </dl>
            @endif
