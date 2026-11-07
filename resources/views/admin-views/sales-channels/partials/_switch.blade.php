{{-- A switch that posts 0 when off and 1 when on. Expects $name, $id, $label, $checked; optional $help and $disabled (shown, not changeable). --}}
<div class="form-check form-switch">
    <input type="hidden" name="{{ $name }}" value="0">
    <input class="form-check-input" type="checkbox" role="switch" id="{{ $id }}" name="{{ $name }}" value="1" @checked($checked) @disabled($disabled ?? false)>
    <label class="form-check-label" for="{{ $id }}">{{ $label }}</label>
    @isset($help)<div class="fs-12 text-muted">{{ $help }}</div>@endisset
</div>
