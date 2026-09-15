<div class="max-w-3xl mx-auto py-10 px-4">
    <div class="nw-card p-6">
        <h1 class="text-xl font-semibold mb-1" style="color: var(--ink)">Importar desde TCGplayer</h1>
        <p class="text-sm mb-4" style="color: var(--muted)">
            Pega el export de la app de TCGplayer (una carta por línea) y revisa el preview antes de confirmar.
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

        <button type="button" wire:click="preview" class="nw-btn-secondary mb-6">Preview</button>

        @if ($matched !== [] || $unmatched !== [])
            <div class="mb-6">
                <h2 class="text-sm font-medium mb-2" style="color: var(--ink)">{{ count($matched) }} cartas reconocidas</h2>
                @if ($matched !== [])
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
                                <li>
                                    <span class="mono text-xs">{{ $line->rawLine }}</span>
                                    <span style="color: var(--muted)">({{ $line->reason }})</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($matched !== [])
                    <button type="button" wire:click="confirm" class="nw-btn-primary">Confirmar import</button>
                @endif
            </div>
        @endif
    </div>
</div>
