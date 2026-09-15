<div class="max-w-3xl mx-auto py-10 px-4">
    <div class="nw-card p-6">
        <h1 class="text-xl font-semibold mb-1" style="color: var(--ink)">Importar desde TCGplayer</h1>
        <p class="text-sm mb-4" style="color: var(--muted)">
            Pega el export de la app de TCGplayer (una carta por línea) y revisa el preview antes de confirmar.
        </p>
        <p class="text-sm mb-4" style="color: var(--muted)">
            Las cartas se agregan en condición NM y sin variante; si ya tenés esa carta con otra condición o
            variante, esta entra como un renglón aparte en vez de sumarse al que ya existe.
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
            <span wire:loading wire:target="preview" class="text-sm" style="color: var(--muted)">Leyendo…</span>
        </div>

        @if ($matched !== [] || $unmatched !== [])
            <div class="mb-6">
                @if ($matched !== [])
                    <h2 class="text-sm font-medium mb-2" style="color: var(--ink)">{{ count($matched) }} cartas reconocidas</h2>
                    <div class="nw-card overflow-hidden mb-4">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="nw-topbar text-left">
                                    <th class="p-3">Cant.</th>
                                    <th class="p-3">Carta</th>
                                    <th class="p-3">ID tcgdex</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($matched as $line)
                                    <tr class="border-t" style="border-color: var(--hair)">
                                        <td class="p-3 mono">{{ $line->qty }}</td>
                                        <td class="p-3 font-medium">{{ $line->name }}</td>
                                        <td class="p-3 mono text-xs" style="color: var(--muted)">{{ $line->tcgdexId }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($unmatched !== [])
                    <div class="mb-4">
                        <h2 class="text-sm font-medium mb-2" style="color: var(--danger)">{{ count($unmatched) }} no reconocidas</h2>
                        <ul class="text-sm space-y-1">
                            @foreach ($unmatched as $line)
                                @php
                                    $reasonLabel = match ($line->reason) {
                                        'unparsed' => 'no coincide con el formato del export',
                                        'unknown_set' => 'set desconocido — agregá el código a config/tcgvault.php → tcgplayer_set_map',
                                        'card_not_found' => 'tcgdex no reconoce esta carta',
                                        'lookup_failed' => 'falló la consulta al catálogo, volvé a intentar',
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
                        <span wire:loading wire:target="confirm" class="text-sm" style="color: var(--muted)">Importando…</span>
                    </div>
                @endif
            </div>
        @elseif ($hasPreviewed)
            <p class="text-sm mb-6" style="color: var(--muted)">0 líneas reconocidas.</p>
        @endif
    </div>
</div>
