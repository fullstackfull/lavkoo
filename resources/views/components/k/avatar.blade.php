@props(['src' => null, 'name' => null])
@php
    // Initials from the first two words, so "Wade Warren" reads WW and a single
    // name still renders something rather than an empty circle.
    $initials = collect(preg_split('/\s+/', trim((string) $name)))
        ->filter()->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
@endphp
<span {{ $attributes->merge(['class' => 'k-avatar']) }} @if ($name) title="{{ $name }}" @endif>
    @if ($src)<img src="{{ $src }}" alt="{{ $name }}">@else{{ $initials ?: '?' }}@endif
</span>
