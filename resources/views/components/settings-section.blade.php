@props(['title', 'description' => null])

{{-- A single named settings group: heading + description + its own control(s),
     as one nw-card. Adding a future setting is just another
     <x-settings-section> block in the page, not a layout rewrite. --}}
<section class="nw-card p-5">
    <h2 class="text-sm font-semibold" style="color: var(--ink)">{{ $title }}</h2>
    @if ($description)
        <p class="text-sm mt-1" style="color: var(--muted)">{{ $description }}</p>
    @endif

    <div class="mt-4">
        {{ $slot }}
    </div>
</section>
