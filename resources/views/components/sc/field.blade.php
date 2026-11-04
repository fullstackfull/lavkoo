@props(['label' => null, 'for' => null, 'required' => false, 'help' => null, 'error' => null])
<div {{ $attributes->merge(['class' => 'sc-field']) }}>
    @if ($label)
        <label class="sc-label" @if ($for) for="{{ $for }}" @endif>
            {{ $label }}@if ($required)<span class="sc-label__req"> *</span>@endif
        </label>
    @endif
    {{ $slot }}
    @if ($error)<div class="sc-error">{{ $error }}</div>@elseif ($help)<div class="sc-help">{{ $help }}</div>@endif
</div>
