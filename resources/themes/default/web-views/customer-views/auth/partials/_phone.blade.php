<div class="form-group m-0">
    <label class="form-label font-semibold">
        {{ translate('phone_number') }}
        <span class="input-required-icon">*</span>
    </label>
    <input class="form-control text-align-direction phone-input-with-country-picker"
           type="tel" value="{{ old('phone') }}" name="phone"
           placeholder="{{ translate('enter_phone_number') }}">
</div>

@push('phone_input_assets')
    @include('layouts.front-end.partials._phone-input-assets')
@endpush
