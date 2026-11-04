@props(['options' => [], 'value' => null, 'size' => 'md', 'placeholder' => null, 'invalid' => false])
<select {{ $attributes->merge(['class' => 'sc-select' . ($size === 'lg' ? ' sc-select--lg' : '') . ($invalid ? ' is-invalid' : '')]) }}>
    @if ($placeholder)<option value="">{{ $placeholder }}</option>@endif
    @foreach ($options as $option)
        <option value="{{ $option['value'] }}" @selected((string) $option['value'] === (string) $value)>{{ $option['label'] }}</option>
    @endforeach
    {{ $slot }}
</select>
