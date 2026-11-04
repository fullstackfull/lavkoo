{{-- "Fixed automatically": a completed fact, with its effect and its reversal path (handoff 06 §7). --}}
@props(['time' => null])
<div {{ $attributes->merge(['class' => 'sc-auto-row']) }}>
    <span class="sc-auto-row__glyph"><x-sc.icon name="check-circle" :size="14" /></span>
    <span style="flex:1 1 auto;min-width:0">{{ $slot }}</span>
    @if ($time)<span class="sc-muted" style="font-size:11px">{{ $time }}</span>@endif
    @isset($action){{ $action }}@endisset
</div>
