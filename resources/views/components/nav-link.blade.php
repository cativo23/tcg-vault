@props(['active'])

@php
$classes = ($active ?? false)
            ? 'nw-link is-active inline-flex items-center px-1 pt-1 text-sm font-medium leading-5 focus:outline-none transition duration-150 ease-in-out'
            : 'nw-link inline-flex items-center px-1 pt-1 text-sm font-medium leading-5 focus:outline-none transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
