<div class="max-w-5xl mx-auto py-10 px-4">
    <h1 class="text-xl font-semibold mb-6" style="color: var(--ink)">{{ $targetUser->name }}'s Collection</h1>

    <div class="grid gap-4" style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr))">
        @foreach ($sets as $set)
            @php
                $total = $set->card_count ?? $set->real_card_count;
                $owned = $set->owned_card_count;
                $pct = $total > 0 ? (int) round(($owned / $total) * 100) : 0;
            @endphp
            <a href="{{ route('gallery.show', ['username' => $targetUser->username, 'setTcgdexId' => $set->tcgdex_id]) }}"
               class="nw-card p-4 block">
                @if ($set->logo_url)
                    <img src="{{ $set->logo_url }}" alt="{{ $set->name }}" class="w-full h-16 object-contain mb-3">
                @endif
                <div class="font-medium mb-1" style="color: var(--ink)">{{ $set->name }}</div>
                <div class="text-xs mb-2" style="color: var(--muted)">{{ $set->series }}</div>
                <div class="mono text-xs mb-1" style="color: var(--muted)">{{ $owned }} / {{ $total }}</div>
                <div class="w-full rounded-full h-1.5" style="background: var(--bone-2)">
                    <div class="h-1.5 rounded-full" style="background: var(--signal); width: {{ $pct }}%"></div>
                </div>
            </a>
        @endforeach
    </div>
</div>
