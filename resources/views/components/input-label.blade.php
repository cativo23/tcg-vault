@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm mb-1']) }} style="color: var(--muted)">
    {{ $value ?? $slot }}
</label>
