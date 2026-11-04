@props(['label' => null, 'checked' => false, 'value' => 1])
<label {{ $attributes->merge(['class' => 'sc-check']) }}>
    <input type="checkbox" value="{{ $value }}" @checked($checked) {{ $attributes->only(['name', 'id', 'disabled', 'data-sc-row-select']) }}>
    @if ($label)<span>{{ $label }}</span>@endif
    {{ $slot }}
</label>
