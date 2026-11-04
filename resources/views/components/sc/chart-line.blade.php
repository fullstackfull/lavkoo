{{-- One line pattern: a current series and an optional comparison. A chart must answer a stated
     question; no donut unless the parts sum to a whole the seller acts on (handoff 04 §29). --}}
@props(['series' => [], 'compare' => [], 'labels' => [], 'height' => 150, 'width' => 640])
@php
    $all = array_merge($series, $compare);
    $max = $all === [] ? 1 : max(max($all), 1);
    $plotTop = 8;
    $plotHeight = $height - 26;
    $points = function (array $values) use ($max, $width, $plotTop, $plotHeight) {
        $count = count($values);
        if ($count < 2) { return ''; }
        $step = $width / ($count - 1);
        return implode(' ', array_map(
            fn ($value, $index) => round($index * $step, 1) . ',' . round($plotTop + $plotHeight - ($value / $max) * $plotHeight, 1),
            $values, array_keys($values),
        ));
    };
    $line = $points($series);
    $comparison = $points($compare);
    $lastX = $width;
    $lastY = $series === [] ? 0 : round($plotTop + $plotHeight - (end($series) / $max) * $plotHeight, 1);
    $gradientId = 'sc-fill-' . substr(md5($line . $comparison), 0, 8);
@endphp
<svg class="sc-chart" viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" style="height:{{ $height }}px" role="img">
    <defs>
        <linearGradient id="{{ $gradientId }}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="var(--color-accent)" stop-opacity=".22" />
            <stop offset="100%" stop-color="var(--color-accent)" stop-opacity="0" />
        </linearGradient>
    </defs>
    @for ($grid = 1; $grid <= 3; $grid++)
        <line class="sc-chart__grid" x1="0" x2="{{ $width }}" y1="{{ $plotTop + ($plotHeight / 4) * $grid }}" y2="{{ $plotTop + ($plotHeight / 4) * $grid }}" />
    @endfor
    <line class="sc-chart__base" x1="0" x2="{{ $width }}" y1="{{ $plotTop + $plotHeight }}" y2="{{ $plotTop + $plotHeight }}" />
    @if ($comparison !== '')<polyline class="sc-chart__compare" points="{{ $comparison }}" />@endif
    @if ($line !== '')
        <polygon fill="url(#{{ $gradientId }})" points="0,{{ $plotTop + $plotHeight }} {{ $line }} {{ $lastX }},{{ $plotTop + $plotHeight }}" />
        <polyline class="sc-chart__line" points="{{ $line }}" />
        <circle class="sc-chart__end" cx="{{ $lastX }}" cy="{{ $lastY }}" r="3.5" />
    @endif
</svg>
@if (!empty($labels))
    <div class="sc-row" style="justify-content:space-between;margin-top:4px">
        @foreach ($labels as $label)<span class="sc-muted" style="font-size:10.5px">{{ $label }}</span>@endforeach
    </div>
@endif
