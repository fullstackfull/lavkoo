@php
    use App\Services\Storefront\ProductCountry;

    $isExistingProduct = isset($product) && !request('product-gallery');
@endphp
@if (ProductCountry::isTracked())
    <div class="col-md-6 col-lg-4">
        <div class="form-group">
            <label class="form-label d-flex align-items-center gap-2" for="storefront-country">
                {{ translate('product_country') }}
                <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                      aria-label="{{ translate('the_country_this_product_is_sold_in_it_cannot_be_changed_after_the_product_is_added') }}"
                      data-bs-title="{{ translate('the_country_this_product_is_sold_in_it_cannot_be_changed_after_the_product_is_added') }}">
                    <i class="fi fi-sr-info"></i>
                </span>
            </label>
            @if ($isExistingProduct)
                <input type="text" id="storefront-country" class="form-control direction-ltr"
                       value="{{ ProductCountry::label(country: (string) $product->storefront_country) }}" disabled>
            @else
                <div class="select-wrapper">
                    <select class="form-select" name="storefront_country" id="storefront-country">
                        @foreach (ProductCountry::options() as $country)
                            <option value="{{ $country }}"
                                {{ old('storefront_country', isset($product) ? $product->storefront_country : ProductCountry::defaultCountry()) === $country ? 'selected' : '' }}>
                                {{ ProductCountry::label(country: $country) }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>
    </div>
@endif
