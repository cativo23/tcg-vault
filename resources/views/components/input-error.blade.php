@props(['messages'])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'text-sm space-y-1']) }} style="color: var(--danger)">
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
