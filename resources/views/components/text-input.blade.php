@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'nw-input w-full']) }}>
