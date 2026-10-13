{{-- The live region is the always-present root, so changes inside it are
     announced; the changing count is aria-hidden and only start, stall and
     finish are read out. --}}
<div role="status" aria-atomic="true">
    @if ($importing)
        <div class="nw-import-banner {{ $stalled || $budgetSpent ? 'stalled' : '' }}" wire:key="import-{{ $budgetSpent ? 'limit' : ($stalled ? 'stalled' : 'loading') }}" wire:poll.{{ $pollSeconds }}s="check">
            @if ($budgetSpent)
                <span>{{ $missingCount }} {{ Str::plural('card', $missingCount) }} missing — not imported {{ \App\Modules\Catalog\Services\ImportBudget::limitNote() }}.</span>
            @elseif ($stalled)
                <span>{{ $missingCount }} {{ Str::plural('card', $missingCount) }} missing</span>
                <button type="button" wire:click="retryImport" wire:loading.attr="disabled" wire:target="retryImport" class="nw-btn-secondary">Retry</button>
            @else
                <span aria-hidden="true">Loading cards… <span class="mono">{{ $localCount }}/{{ $totalCount }}</span></span>
                <span class="sr-only">Loading cards</span>
            @endif
            @if ($notice !== '')
                <span>{{ $notice }}</span>
            @endif
        </div>
    @elseif ($finished)
        <div class="nw-import-banner">All cards loaded</div>
    @endif
</div>
