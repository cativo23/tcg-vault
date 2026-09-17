@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm']) }} style="color: var(--signal-deep)">
        {{ $status }}
    </div>
@endif
