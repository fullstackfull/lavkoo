{{--
    The storefront a coupon is typed for (GCC-05, slice 5), shown only while coupons are kept by storefront. A new coupon chooses it: the amounts the form takes are in
    that storefront's currency, and the marks beside them follow the choice. An existing coupon shows its own and cannot change it, because its amounts were typed in
    one currency.
--}}
@use('App\Services\Storefront\CouponCountry')
@use('App\Services\Storefront\ProductCountry')
@if (CouponCountry::isTracked())
    <div class="col-md-6 col-lg-4">
        <div class="form-group">
            <label class="form-label d-flex align-items-center gap-2" for="storefront-country">
                {{ translate('coupon_storefront') }}
                <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                      aria-label="{{ translate('the_amounts_of_a_coupon_are_in_the_currency_of_its_storefront_it_cannot_be_changed_after_the_coupon_is_added') }}"
                      data-bs-title="{{ translate('the_amounts_of_a_coupon_are_in_the_currency_of_its_storefront_it_cannot_be_changed_after_the_coupon_is_added') }}">
                    <i class="fi fi-sr-info"></i>
                </span>
            </label>
            @isset($coupon)
                <input type="text" id="storefront-country" class="form-control direction-ltr"
                       value="{{ ProductCountry::label(country: CouponCountry::countryOf($coupon['id']) ?? ProductCountry::defaultCountry()) }}" disabled>
            @else
                <div class="select-wrapper">
                    <select class="form-select" name="storefront_country" id="storefront-country">
                        @foreach (CouponCountry::options() as $country)
                            <option value="{{ $country }}" data-currency-symbol="{{ CouponCountry::currencySymbolOf(country: $country) }}"
                                {{ old('storefront_country', ProductCountry::defaultCountry()) === $country ? 'selected' : '' }}>
                                {{ ProductCountry::label(country: $country) }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endisset
        </div>
    </div>
    @unless (isset($coupon))
        @push('script')
            <script>
                'use strict';
                $('#storefront-country').on('change', function () {
                    $('.coupon-currency').text($(this).find('option:selected').data('currency-symbol'));
                });
            </script>
        @endpush
    @endunless
@endif
