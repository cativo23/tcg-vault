@props(['active'])

@php
$classes = ($active ?? false)
            ? 'nw-link is-active nw-row-hover block w-full ps-3 pe-4 py-2 border-l-4 text-start text-base font-medium focus:outline-none transition duration-150 ease-in-out'
            : 'nw-link nw-row-hover block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium focus:outline-none transition duration-150 ease-in-out';
$style = ($active ?? false) ? 'border-color: var(--signal); background: var(--bone-2)' : '';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }} style="{{ $style }}">
    {{ $slot }}
</a>
