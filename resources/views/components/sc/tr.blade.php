{{-- Whole row opens the entity; the selection and action cells stop propagation (handoff 05 A3). --}}
@props(['href' => null, 'id' => null, 'selected' => false, 'busy' => false, 'flash' => false])
<tr {{ $attributes->merge([
        'class' => ($href ? 'is-clickable' : '') . ($selected ? ' is-selected' : '') . ($busy ? ' is-busy' : ''),
    ]) }}
    @if ($href) data-sc-row-href="{{ $href }}" tabindex="0" role="link" @endif
    @if ($id !== null) data-sc-row-id="{{ $id }}" @endif>
    {{ $slot }}
</tr>
