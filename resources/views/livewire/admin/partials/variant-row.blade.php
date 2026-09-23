@php
    // Placeholder until Task 4 fills in the real fields — exists now
    // only so this task's tests (which check for the card name and row
    // count, not field markup) pass without a missing-view error.
@endphp
<div class="p-3" style="border-bottom: 1px solid var(--hair)">{{ $row['variant'] ?? '— unspecified' }} · {{ $row['condition'] }} × {{ $row['quantity'] }}</div>
