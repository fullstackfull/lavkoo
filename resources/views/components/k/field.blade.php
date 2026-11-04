@props([
    'label' => null,
    'name' => null,
    'hint' => null,
    'error' => null,
    'required' => false,
    'for' => null,
])
@php $id = $for ?? $name; @endphp
<div {{ $attributes->merge(['class' => 'k-field' . ($error ? ' k-field--invalid' : '')]) }}>
    @if ($label)
        <label class="k-field__label" @if ($id) for="{{ $id }}" @endif>
            {{ $label }}@if ($required)<span class="k-field__required" aria-hidden="true">*</span>@endif
        </label>
    @endif
    {{ $slot }}
    {{-- Hint before error: the hint explains the field, the error explains the fix. --}}
    @if ($hint && !$error)<span class="k-field__hint">{{ $hint }}</span>@endif
    @if ($error)<span class="k-field__error" role="alert">{{ $error }}</span>@endif
</div>
