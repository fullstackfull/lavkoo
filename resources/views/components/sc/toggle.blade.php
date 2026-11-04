{{-- A toggle takes effect immediately and must be reversible; anything needing confirmation is a
     button plus a dialog, not a toggle (handoff 04 §10). --}}
@props(['label' => null, 'checked' => false, 'name' => null, 'disabled' => false])
<label {{ $attributes->merge(['class' => 'sc-toggle']) }}>
    <input type="checkbox" @if ($name) name="{{ $name }}" @endif @checked($checked) @disabled($disabled)>
    <span class="sc-toggle__track"></span>
    @if ($label)<span>{{ $label }}</span>@endif
</label>
