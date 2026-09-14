@props(['url', 'name'])

<div class="imgwrap {{ $url ? '' : 'empty' }}">
    @if ($url)
        <img src="{{ $url }}" alt="{{ $name }}" loading="lazy">
    @else
        <span>No image<br>{{ $name }}</span>
    @endif
</div>
