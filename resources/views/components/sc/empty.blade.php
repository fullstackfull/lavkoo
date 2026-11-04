{{-- Name the situation, then what to do. Never "No data" (handoff 11 §3). --}}
@props(['title', 'text' => null, 'glyph' => 'info', 'tone' => null])
<div {{ $attributes->merge(['class' => 'sc-empty']) }}>
    <span class="sc-empty__glyph{{ $tone === 'good' ? ' sc-empty__glyph--good' : '' }}"><x-sc.icon :name="$glyph" :size="32" /></span>
    <h5 class="sc-empty__title">{{ $title }}</h5>
    @if ($text)<p class="sc-empty__text">{{ $text }}</p>@endif
    @isset($actions)<div class="sc-empty__actions">{{ $actions }}</div>@endisset
</div>
