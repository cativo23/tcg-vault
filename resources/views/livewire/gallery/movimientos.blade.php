<div class="max-w-5xl mx-auto py-10 px-4">
    <h1 class="text-xl font-semibold mb-6" style="color: var(--ink)">{{ $targetUser->name }}'s Activity</h1>

    <section class="mb-10">
        <h2 class="text-sm font-semibold uppercase tracking-wide mb-3" style="color: var(--muted)">Price changes</h2>
        <div class="nw-card divide-y" style="border-color: var(--hair)">
            @forelse ($deltas as $d)
                @php $up = $d['deltaMinor'] > 0; @endphp
                <div class="flex items-center justify-between p-3">
                    <span style="color: var(--ink)">{{ $d['card']->name }}</span>
                    <span class="mono text-sm" style="color: {{ $up ? 'var(--signal)' : 'var(--flat)' }}">
                        {{ $up ? '+' : '' }}{{ number_format($d['deltaMinor'] / 100, 2) }} {{ $d['latest']->currency }}
                    </span>
                </div>
            @empty
                <div class="p-6 text-center" style="color: var(--muted)">No price changes yet — check back after the next daily refresh.</div>
            @endforelse
        </div>
    </section>

    <section>
        <h2 class="text-sm font-semibold uppercase tracking-wide mb-3" style="color: var(--muted)">Recently added</h2>
        <div class="nw-card divide-y" style="border-color: var(--hair)">
            @forelse ($recentItems as $item)
                <div class="flex items-center gap-3 p-3">
                    <div class="w-10 h-10 flex-none">
                        <x-card-image :url="$item->card->official_image_url" :name="$item->card->name" />
                    </div>
                    <div>
                        <div style="color: var(--ink)">Added {{ $item->card->name }}</div>
                        <div class="text-xs" style="color: var(--muted)">{{ $item->created_at->diffForHumans() }}</div>
                    </div>
                </div>
            @empty
                <div class="p-6 text-center" style="color: var(--muted)">No activity yet.</div>
            @endforelse
        </div>
    </section>
</div>
