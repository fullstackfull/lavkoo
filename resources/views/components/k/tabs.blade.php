@props(['items' => []])
{{-- items: [['label'=>, 'href'=>, 'count'=>null, 'active'=>false], ...] --}}
<nav {{ $attributes->merge(['class' => 'k-tabs']) }} role="tablist">
    @foreach ($items as $item)
        <a class="k-tab" href="{{ $item['href'] ?? '#' }}" role="tab"
           aria-selected="{{ !empty($item['active']) ? 'true' : 'false' }}">
            {{ $item['label'] }}
            @if (isset($item['count']))<span class="k-tab__count k-num">{{ $item['count'] }}</span>@endif
        </a>
    @endforeach
    {{ $slot }}
</nav>
