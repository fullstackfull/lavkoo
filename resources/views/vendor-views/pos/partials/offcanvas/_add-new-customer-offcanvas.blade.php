<form action="{{ route('vendor.customer.add') }}" method="post" id="product_form" class="pos-ajax-form-add-customer">
    @csrf
    <div class="offcanvas-sidebar offcanvasAddNewCustomer" id="offcanvasAddNewCustomer">
        <div class="offcanvas-overlay" data-dismiss="offcanvas"></div>

        <div class="offcanvas-content bg-white shadow d-flex flex-column">
            <div class="offcanvas-header bg-light d-flex justify-content-between align-items-center p-3">
                <h3 class="text-capitalize m-0">{{ translate('Add_New_Customer') }}</h3>
                <button type="button" class="close" data-dismiss="offcanvas" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="offcanvas-body p-3 overflow-auto flex-grow-1">
            <div class="p-12 p-sm-20 bg-section rounded overflow-wrap-anywhere">
                <div class="form-group mb-3">
                    <label class="form-label mb-2 d-flex gap-1">{{ translate('first_name') }} <span
                            class="input-label-secondary text-danger m-0">*</span></label>
                    <input type="text" name="f_name" class="form-control" value="{{ old('f_name') }}"
                           placeholder="{{ translate('first_name') }}" >
                    <div class="invalid-feedback"></div>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label mb-2 d-flex gap-1">{{ translate('last_name') }}
                        <span class="input-label-secondary text-danger m-0">*</span></label>
                    <input type="text" name="l_name" class="form-control" value="{{ old('l_name') }}"
                           placeholder="{{ translate('last_name') }}" >
                    <div class="invalid-feedback"></div>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label mb-2 d-flex gap-1">{{ translate('email') }} <span
                            class="input-label-secondary text-danger m-0">*</span></label>
                    <input type="email" name="email" class="form-control" value="{{ old('email') }}"
                           placeholder="{{ translate('ex').': ex@example.com' }}" >
                    <div class="invalid-feedback"></div>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label mb-2 d-flex gap-1">{{ translate('phone') }} <span
                            class="input-label-secondary text-danger m-0">*</span></label>
                    <input class="form-control form-control-user phone-input-with-country-picker"
                        type="tel" id="exampleInputPhone" value="{{old('phone')}}"
                        name="phone"
                        placeholder="{{ translate('enter_phone_number') }}" >
                    <div class="invalid-feedback"></div>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label mb-2 d-flex gap-1">{{ translate('country') }}</label>
                   <select name="country" class="form-control js-select2-custom" data-live-search="true">
                        @foreach($countries as $country)
                            <option value="{{ $country['name'] }}">{{ $country['name'] }}</option>
                        @endforeach
                    </select>
                    <div class="invalid-feedback"></div>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label mb-2 d-flex gap-1">{{ translate('city') }}</label>
                    <input type="text" name="city" class="form-control" value="{{ old('city') }}"
                            placeholder="{{ translate('city') }}">
                    <div class="invalid-feedback"></div>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label mb-2 d-flex gap-1">{{ translate('zip_code') }}</label>
                    @if($zipCodes)
                        <select name="zip" class="form-control js-select2-custom" data-live-search="true">
                            @foreach($zipCodes as $code)
                                <option
                                    value="{{ $code->zipcode }}">{{ $code->zipcode }}</option>
                            @endforeach
                        </select>
                    @else
                    <input type="text" name="zip_code" class="form-control"
                            value="{{ old('zip_code') }}" placeholder="{{ translate('zip_code') }}">
                        <div class="invalid-feedback"></div>
                    @endif
                </div>
                <div class="form-group mb-0">
                    <label class="form-label mb-2 d-flex gap-1">{{ translate('address') }}</label>
                    <textarea name="address" class="form-control" value="{{ old('address') }}"
                            placeholder="{{ translate('address') }}" cols="2"></textarea>
                    <div class="invalid-feedback"></div>
                </div>
            </div>
        </div>
            <div class="offcanvas-footer offcanvas-footer-sticky shadow-popup">
                <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 px-sm-4 py-3">
                    <button type="reset" class="btn btn-secondary flex-grow-1">{{ translate('Reset') }}</button>
                    <button type="submit" class="btn btn--primary flex-grow-1"  id="submit_new_customer">{{ translate('Submit') }}</button>
                </div>
            </div>
        </div>
    </div>
</form>
