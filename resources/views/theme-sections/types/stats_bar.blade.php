{{-- Store stats. Every number is a real row count unless the merchant typed a
     custom one, and they count up once when the bar scrolls into view. --}}

@php
    $stats = $__resolver->storeStats();
    $statBlocks = $rawBlocks;
    $statsDark = ($s['style'] ?? 'boxed') === 'dark';
    $countUp = (bool) ($s['animate'] ?? true);
@endphp
@if (count($statBlocks))
    @if (!empty($s['title']) || !empty($s['eyebrow']))
        <div class="ml-sec-head ml-sec-head--center ml-reveal">
            @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
            @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
            <div class="ml-rule"></div>
        </div>
    @endif
    <div class="ml-grid {{ $statsDark ? 'ml-usp-dark' : '' }}">
        @foreach ($statBlocks as $statBlock)
            @php
                $stat = $statBlock['settings'] ?? [];
                $statSource = $stat['source'] ?? 'products';
                $statValue = $statSource === 'custom'
                    ? ($stat['value'] ?? '')
                    : ($stats[$statSource] ?? 0);
            @endphp
            <div class="ml-stat ml-reveal" data-delay="{{ $loop->index % 6 }}">
                <span class="ml-stat__icon">
                    @include('theme-sections.partials.usp-icon', ['icon' => $stat['icon'] ?? 'shipping'])
                </span>
                <b @if ($countUp && is_numeric($statValue)) data-ml-count="{{ (int) $statValue }}" @endif>
                    {{ is_numeric($statValue) ? number_format((int) $statValue) : $statValue }}{{ $stat['suffix'] ?? '' }}
                </b>
                <span class="ml-stat__label">{{ $stat['label'] ?? '' }}</span>
            </div>
        @endforeach
    </div>
@endif
