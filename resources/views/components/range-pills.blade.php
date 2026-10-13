@props([
    'active',  // App\Modules\Catalog\Support\ChartRange
    'label',   // accessible group name, e.g. "Price history range"
])

@use('App\Modules\Catalog\Support\ChartRange')

<div class="nw-range-pills" role="group" aria-label="{{ $label }}">
    @foreach (ChartRange::cases() as $option)
        @php $isOn = $option === $active; @endphp
        <button type="button" wire:key="range-{{ $option->value }}"
            class="nw-variant-pill {{ $isOn ? 'on' : '' }}" aria-pressed="{{ $isOn ? 'true' : 'false' }}"
            wire:click="selectRange('{{ $option->value }}')">{{ $option->button() }}</button>
    @endforeach
</div>
