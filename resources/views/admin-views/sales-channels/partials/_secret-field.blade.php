{{-- A write-only secret. The field is always empty: the page says only WHETHER a secret is stored. Blank keeps it; the box clears it.
     Expects $name (the secret's key), $label, $isSet (bool). --}}
<label class="form-label" for="secret-{{ $name }}">{{ $label }}</label>
<input class="form-control" type="password" id="secret-{{ $name }}" name="secrets[{{ $name }}]" value="" autocomplete="new-password" spellcheck="false"
       placeholder="{{ $isSet ? '••••••••' : '' }}" aria-describedby="secret-{{ $name }}-state">
<div class="d-flex flex-wrap align-items-center gap-2 mt-1 fs-12" id="secret-{{ $name }}-state">
    <x-k.badge :tone="$isSet ? 'success' : 'neutral'">{{ $isSet ? translate('secret_is_set') : translate('secret_is_not_set') }}</x-k.badge>
    @if ($isSet)
        <span class="text-muted">{{ translate('leave_blank_to_keep_the_stored_value') }}</span>
        <label class="form-check mb-0">
            <input class="form-check-input" type="checkbox" name="clear_secrets[]" value="{{ $name }}">
            <span class="form-check-label">{{ translate('clear_stored_value') }}</span>
        </label>
    @endif
</div>
@error('secrets.' . $name)<div class="text-danger fs-12">{{ $message }}</div>@enderror
