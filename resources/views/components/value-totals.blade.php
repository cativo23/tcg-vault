@use('App\Support\Money')

@props([
    'totals',          // array<string currency, int minor>, primary first
    'empty' => '—',
    'countup' => false, // animate the headline figure (digits only; the rest of the markup is untouched)
])

{{-- A collection total is a small map of currency → amount, never one sum:
     the first entry is the headline, the rest are shown as a footnote so a
     EUR-priced card is never silently added to a USD number. --}}
@php $totals = array_filter($totals); @endphp
@if ($totals === [])
    <span>{{ $empty }}</span>
@else
    @foreach ($totals as $currency => $minor)
        @if ($loop->first)
            <span @if ($countup) data-countup="{{ number_format($minor / 100, 2, '.', '') }}" @endif>{{ Money::format($minor, $currency) }}</span>
        @else
            {{-- "+" visually reads as "add this to the total above" no matter
                 what the tooltip says — a tooltip isn't visible at a glance
                 and isn't reachable at all on touch. "·" is a plain
                 separator with no arithmetic meaning of its own. --}}
            <small title="Priced in {{ $currency }} — not added to the {{ array_key_first($totals) }} total">· {{ Money::format($minor, $currency) }}</small>
        @endif
    @endforeach
@endif
