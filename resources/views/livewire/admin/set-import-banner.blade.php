<div>
    @if ($importing)
        <div class="nw-import-banner {{ $stalled ? 'stalled' : '' }}" role="status" @if (! $stalled) wire:poll.5s="check" @endif>
            @if ($stalled)
                <span>{{ $missingCount }} {{ Str::plural('card', $missingCount) }} missing</span>
                <button type="button" wire:click="retryImport" wire:loading.attr="disabled" wire:target="retryImport" class="nw-btn-secondary">Retry</button>
            @else
                <span class="mono">Loading cards… {{ $localCount }}/{{ $totalCount }}</span>
            @endif
            @if ($notice !== '')
                <span>{{ $notice }}</span>
            @endif
        </div>
    @endif
</div>
