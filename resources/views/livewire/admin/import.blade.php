<div class="max-w-3xl mx-auto py-10 px-4">
    <div class="nw-card p-6">
        <h1 class="text-xl font-semibold mb-1" style="color: var(--ink)">Import from TCGplayer</h1>
        <p class="text-sm mb-4" style="color: var(--muted)">
            Paste the export from the TCGplayer app (one card per line) and review the preview before confirming.
        </p>
        <p class="text-sm mb-4" style="color: var(--muted)">
            Cards are added in NM condition with no variant; if you already have that card with a different
            condition or variant, this comes in as a separate row instead of being merged into the existing one.
        </p>

        @if ($summary)
            <div class="mb-4 p-3 rounded" style="background: var(--bone-2)">
                {{ $summary }}
            </div>
        @endif

        <div class="mb-4">
            <textarea wire:model="text" rows="10" class="w-full border rounded px-3 py-2 mono text-sm"
                      placeholder="1 Toucannon - 068/084 [PBL] 068/084"></textarea>
        </div>

        <div class="flex items-center gap-3 mb-6">
            <button type="button" wire:click="preview" wire:loading.attr="disabled" wire:target="preview"
                    class="nw-btn-secondary">Preview</button>
            <span wire:loading wire:target="preview" class="text-sm" style="color: var(--muted)">Reading…</span>
        </div>

        @if ($matched !== [] || $unmatched !== [])
            <div class="mb-6">
                @if ($matched !== [])
                    <h2 class="text-sm font-medium mb-2" style="color: var(--ink)">{{ count($matched) }} recognized</h2>
                    <div class="nw-card overflow-hidden mb-4">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="nw-topbar text-left">
                                    <th class="p-3">Qty.</th>
                                    <th class="p-3">Card</th>
                                    <th class="p-3">tcgdex ID</th>
                                    <th class="p-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($matched as $line)
                                    <tr class="border-t" style="border-color: var(--hair)">
                                        <td class="p-3 mono">{{ $line->qty }}</td>
                                        <td class="p-3 font-medium">{{ $line->name }}</td>
                                        <td class="p-3 mono text-xs" style="color: var(--muted)">{{ $line->tcgdexId }}</td>
                                        <td class="p-3 text-xs">
                                            @if ($line->variantAmbiguous)
                                                {{-- TCGplayer's export never marks which copy is holo/reverse-holo.
                                                     This card has more than one known price variant, so every
                                                     copy imports without one; edit them individually afterward
                                                     in the collection table if needed. --}}
                                                <span style="color: var(--danger)" title="tcgdex knows more than one price variant for this card, but TCGplayer's export doesn't distinguish which copy is which — they're added without a variant. Edit them individually afterward if needed.">
                                                    ⚠ review variant
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($unmatched !== [])
                    <div class="mb-4">
                        <h2 class="text-sm font-medium mb-2" style="color: var(--danger)">{{ count($unmatched) }} not recognized</h2>
                        <ul class="text-sm space-y-1">
                            @foreach ($unmatched as $line)
                                @php
                                    // 'unknown_set' deliberately doesn't mention config/tcgvault.php or
                                    // tcgplayer_set_map — those are implementation details an admin can't
                                    // act on. tcgdex has no TCGplayer-code field to look this set up by, so
                                    // there's no self-service fix here yet; the honest message is just that
                                    // this set isn't supported for import.
                                    $reasonLabel = match ($line->reason) {
                                        'unparsed' => "doesn't match the export format",
                                        'unknown_set' => 'unrecognized set — not yet supported for import',
                                        'card_not_found' => 'tcgdex does not recognize this card',
                                        'lookup_failed' => 'catalog lookup failed, try again',
                                        default => $line->reason,
                                    };
                                @endphp
                                <li>
                                    <span class="mono text-xs">{{ $line->rawLine }}</span>
                                    <span style="color: var(--muted)">({{ $reasonLabel }})</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($matched !== [])
                    <div class="flex items-center gap-3">
                        <button type="button" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm"
                                class="nw-btn-primary">{{ $this->confirmLabel }}</button>
                        <span wire:loading wire:target="confirm" class="text-sm" style="color: var(--muted)">Importing…</span>
                    </div>
                @endif
            </div>
        @elseif ($hasPreviewed)
            <p class="text-sm mb-6" style="color: var(--muted)">0 lines recognized.</p>
        @endif
    </div>
</div>
