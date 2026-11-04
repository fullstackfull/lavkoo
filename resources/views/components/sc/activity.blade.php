{{-- Automatic system actions read as facts, in the past tense, naming what changed (handoff 04 §35). --}}
@props(['glyph' => 'info', 'tone' => 'info', 'meta' => null])
<div {{ $attributes->merge(['class' => 'sc-activity']) }}>
    <span class="sc-activity__glyph" style="color:var(--st-{{ $tone }})"><x-sc.icon :name="$glyph" :size="14" /></span>
    <div style="min-width:0">
        <div class="sc-activity__text">{{ $slot }}</div>
        @if ($meta)<div class="sc-activity__meta">{{ $meta }}</div>@endif
    </div>
</div>
