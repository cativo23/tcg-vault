@props([
    'points',          // array<int, int> — minor units, oldest first
    'label',           // accessible description
    'up' => null,      // bool|null — colors the line green only when the series ended higher than it started
    'height' => 120,
    'dates' => null,      // array<int, \Carbon\Carbon>|null — same length as points, for the tooltip/axis
    'currency' => null,   // string|null — ISO currency, for formatting the tooltip/axis values
])

@php
    use App\Support\Money;

    $points = array_values(array_map('intval', $points));
    $n = count($points);
    $w = 600;
    $h = (int) $height;
    $axisPad = $dates ? 22 : 0;
    $chartH = $h - $axisPad;
    $pad = $dates ? 16 : 6;
    $min = min($points);
    $max = max($points);
    $range = max($max - $min, 1);
    $coords = [];
    foreach ($points as $i => $p) {
        $x = $n > 1 ? round($i / ($n - 1) * $w, 1) : $w;
        $y = round($pad + (1 - ($p - $min) / $range) * ($chartH - $pad * 2), 1);
        $coords[] = [$x, $y];
    }
    $line = implode(' ', array_map(fn ($c, $i) => ($i === 0 ? 'M' : 'L').$c[0].','.$c[1], $coords, array_keys($coords)));
    $area = $line.' L'.$w.','.$chartH.' L0,'.$chartH.' Z';
    $stroke = $up === true ? 'var(--signal-deep)' : 'var(--ink)';
    $fill = $up === true ? 'rgba(55,209,127,.22)' : 'rgba(20,20,18,.08)';
    $last = end($coords);
    $gradientId = 'spark-'.substr(md5($label.$n.$min.$max), 0, 8);

    $gridLines = [];
    if ($dates) {
        foreach ([0, 0.5, 1] as $frac) {
            $y = round($pad + $frac * ($chartH - $pad * 2), 1);
            $value = $max - $frac * $range;
            $gridLines[] = ['y' => $y, 'label' => $currency ? Money::format((int) round($value), $currency) : (string) round($value)];
        }
    }

    $hits = [];
    foreach ($coords as $i => $c) {
        $hits[] = [
            'x' => $c[0],
            'y' => $c[1],
            'value' => $currency ? Money::format($points[$i], $currency) : (string) $points[$i],
            'date' => $dates[$i]?->format('j M') ?? '',
        ];
    }
@endphp

<div class="nw-chart-interactive" data-sparkline='@json($hits)' style="position: relative">
    <svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" role="img" aria-label="{{ $label }}" {{ $attributes }}>
        <defs>
            <linearGradient id="{{ $gradientId }}" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="{{ $fill }}"/>
                <stop offset="100%" stop-color="rgba(255,255,255,0)"/>
            </linearGradient>
        </defs>

        @if ($dates)
            @foreach ($gridLines as $g)
                <line x1="0" y1="{{ $g['y'] }}" x2="{{ $w }}" y2="{{ $g['y'] }}" stroke="var(--hair)" stroke-width="1" vector-effect="non-scaling-stroke"/>
                <text x="4" y="{{ $g['y'] - 4 }}" class="nw-chart-axis-label">{{ $g['label'] }}</text>
            @endforeach
        @endif

        <path d="{{ $area }}" fill="url(#{{ $gradientId }})"/>
        <path d="{{ $line }}" fill="none" stroke="{{ $stroke }}" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>

        <line data-guide class="nw-chart-guide" x1="0" y1="0" x2="0" y2="{{ $chartH }}" stroke="var(--ink)" stroke-width="1" stroke-dasharray="3,3" opacity="0" vector-effect="non-scaling-stroke"/>
        <circle data-hover-dot r="5.5" fill="var(--paper)" stroke="{{ $stroke }}" stroke-width="2.5" opacity="0" vector-effect="non-scaling-stroke"/>
        <circle cx="{{ $last[0] }}" cy="{{ $last[1] }}" r="4.5" fill="{{ $stroke }}" data-end-dot/>

        @if ($dates)
            <text x="0" y="{{ $h - 5 }}" class="nw-chart-axis-label" text-anchor="start">{{ $dates[0]->format('j M') }}</text>
            <text x="{{ $w }}" y="{{ $h - 5 }}" class="nw-chart-axis-label" text-anchor="end">{{ $dates[$n - 1]->format('j M') }}</text>
        @endif
    </svg>
    <div class="nw-chart-tip" data-tip hidden>
        <div class="d" data-tip-date></div>
        <div data-tip-value></div>
    </div>
</div>
