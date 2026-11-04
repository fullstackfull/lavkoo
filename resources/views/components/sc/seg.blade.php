@props(['options' => [], 'current' => null, 'size' => 'sm'])
<div {{ $attributes->merge(['class' => 'sc-seg' . ($size === 'md' ? ' sc-seg--md' : '')]) }} role="group">
    @foreach ($options as $option)
        <a href="{{ $option['href'] }}" class="sc-seg__opt{{ ($option['key'] ?? null) === $current ? ' is-active' : '' }}">{{ $option['label'] }}</a>
    @endforeach
</div>
