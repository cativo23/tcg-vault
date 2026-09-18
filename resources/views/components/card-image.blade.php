@props(['url', 'name', 'eager' => false, 'class' => ''])

{{-- Every card must render with no image at all — the data is not optional,
     the picture is. design.md's empty state: hatch + icon + label. --}}
<div {{ $attributes->merge(['class' => 'imgwrap '.($url ? '' : 'empty ').$class]) }} @if (! $url) role="img" aria-label="No image available for {{ $name }}" @endif>
    @if ($url)
        {{-- onerror also flips is-loaded — a broken URL must stop the
             shimmer too, not pulse forever over what's effectively a
             blank frame. --}}
        <img src="{{ $url }}" alt="{{ $name }}" loading="{{ $eager ? 'eager' : 'lazy' }}" decoding="async" onload="this.classList.add('is-loaded')" onerror="this.classList.add('is-loaded')" @if ($eager) fetchpriority="high" @endif>
    @else
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="3.5" y="2.5" width="17" height="19" rx="2.5"></rect>
            <path d="M3.5 16l4.5-4.5 3.5 3.5"></path>
            <circle cx="15" cy="8" r="1.6"></circle>
            <path d="M2 22L22 2"></path>
        </svg>
        <span>No image</span>
    @endif
</div>
