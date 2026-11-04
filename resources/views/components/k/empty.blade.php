@props(['icon' => 'search', 'title', 'text' => null])
{{-- Every empty state names the situation and offers the one action that resolves
     it. An empty screen with no way forward is a dead end. --}}
<div {{ $attributes->merge(['class' => 'k-empty']) }}>
    <span class="k-empty__art"><x-k.icon :name="$icon" :size="24" /></span>
    <p class="k-empty__title">{{ $title }}</p>
    @if ($text)<p class="k-empty__text">{{ $text }}</p>@endif
    @isset($action)<div class="k-row">{{ $action }}</div>@endisset
</div>
