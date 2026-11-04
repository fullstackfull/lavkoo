<form action="{{ route('admin.customer.add') }}" method="post" id="add-new-customer-form" class="pos-ajax-form-add-customer">
    @csrf
    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasAddNewCustomer" aria-labelledby="offcanvasAddNewCustomerLabel">
        <div class="offcanvas-header bg-body">
            <h3 class="mb-0">{{ translate('Add_new_Customer') }}</h3>
            <button type="button" class="btn btn-circle bg-white text-dark fs-10" style="--size: 1.5rem;" data-bs-dismiss="offcanvas" aria-label="Close">
                <i class="fi fi-rr-cross"></i>
            </button>
        </div>
        <div class="offcanvas-body">
            <div class="p-12 p-sm-20 bg-section rounded overflow-wrap-anywhere">

                <div class="form-group mb-3">
                    <label class="form-label mb-1">{{ translate('first_name') }} <span class="text-danger">*</span></label>
                    <input type="text" name="f_name" class="form-control" value="{{ old('f_name') }}" placeholder="{{ translate('first_name') }}">
                    <div class="invalid-feedback"></div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label mb-1">{{ translate('last_name') }} <span class="text-danger">*</span></label>
                    <input type="text" name="l_name" class="form-control" value="{{ old('l_name') }}" placeholder="{{ translate('last_name') }}">
                    <div class="invalid-feedback"></div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label mb-1">{{ translate('email') }} <span class="text-danger">*</span></label>
                    <input type="text" name="email" class="form-control" value="{{ old('email') }}" placeholder="{{ translate('ex').': ex@example.com' }}">
                    <div class="invalid-feedback"></div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label mb-1">{{ translate('phone') }} <span class="text-danger">*</span></label>
                    <input class="form-control" type="tel" id="exampleInputPhone" value="{{ old('phone') }}" name="phone" placeholder="{{ translate('enter_phone_number') }}">
                    <div class="invalid-feedback"></div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label mb-1">{{ translate('country') }}</label>
                    <select name="country" class="custom-select" data-live-search="true">
                        @foreach($countries as $country)
                            <option value="{{ $country['name'] }}">{{ $country['name'] }}</option>
                        @endforeach
                    </select>
                    <div class="invalid-feedback"></div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label mb-1">{{ translate('city') }}</label>
                    <input type="text" name="city" class="form-control" value="{{ old('city') }}" placeholder="{{ translate('city') }}">
                    <div class="invalid-feedback"></div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label mb-1">{{ translate('zip_code') }}</label>
                    @if($zipCodes)
                        <select name="zip" class="form-control js-select2-custom" data-live-search="true">
                            @foreach($zipCodes as $code)
                                <option value="{{ $code->zipcode }}">{{ $code->zipcode }}</option>
                            @endforeach
                        </select>
                        <div class="invalid-feedback"></div>
                    @else
                        <input type="text" name="zip_code" class="form-control" value="{{ old('zip_code') }}" placeholder="{{ translate('zip_code') }}">
                        <div class="invalid-feedback"></div>
                    @endif
                </div>

                <div class="form-group mb-0">
                    <label class="form-label mb-1">{{ translate('address') }}</label>
                    <textarea name="address" class="form-control" placeholder="{{ translate('address') }}" cols="2">{{ old('address') }}</textarea>
                    <div class="invalid-feedback"></div>
                </div>

            </div>
        </div>
        <div class="offcanvas-footer shadow-popup">
            <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
                <button type="reset" class="btn btn-secondary flex-grow-1">{{ translate('Reset') }}</button>
                <button type="submit" class="btn btn-primary flex-grow-1" id="submit_new_customer">{{ translate('Submit') }}</button>
            </div>
        </div>
    </div>
</form>
