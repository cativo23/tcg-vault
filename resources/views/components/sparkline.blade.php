@props([
    'points',          // array<int, int> — minor units, oldest first
    'label',           // accessible description
    'up' => null,      // bool|null — colors the line green only when the series ended higher than it started
    'height' => 120,
])

@php
    $points = array_values(array_map('intval', $points));
    $n = count($points);
    $w = 600;
    $h = (int) $height;
    $pad = 6;
    $min = min($points);
    $max = max($points);
    $range = max($max - $min, 1);
    $coords = [];
    foreach ($points as $i => $p) {
        $x = $n > 1 ? round($i / ($n - 1) * $w, 1) : $w;
        $y = round($pad + (1 - ($p - $min) / $range) * ($h - $pad * 2), 1);
        $coords[] = [$x, $y];
    }
    $line = implode(' ', array_map(fn ($c, $i) => ($i === 0 ? 'M' : 'L').$c[0].','.$c[1], $coords, array_keys($coords)));
    $area = $line.' L'.$w.','.$h.' L0,'.$h.' Z';
    $stroke = $up === true ? 'var(--signal-deep)' : 'var(--ink)';
    $fill = $up === true ? 'rgba(55,209,127,.22)' : 'rgba(20,20,18,.08)';
    $last = end($coords);
    $gradientId = 'spark-'.substr(md5($label.$n.$min.$max), 0, 8);
@endphp

<svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" role="img" aria-label="{{ $label }}" {{ $attributes }}>
    <defs>
        <linearGradient id="{{ $gradientId }}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="{{ $fill }}"/>
            <stop offset="100%" stop-color="rgba(255,255,255,0)"/>
        </linearGradient>
    </defs>
    <path d="{{ $area }}" fill="url(#{{ $gradientId }})"/>
    <path d="{{ $line }}" fill="none" stroke="{{ $stroke }}" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
    <circle cx="{{ $last[0] }}" cy="{{ $last[1] }}" r="4.5" fill="{{ $stroke }}"/>
</svg>
