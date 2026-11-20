@php
    use App\Services\Storefront\LegalEntityRegistry;
    use App\Services\Storefront\ProductCountry;

    $isExistingProduct = isset($product) && !request('product-gallery');
    $chosenCountry = (string) old('storefront_country', isset($product) ? $product->storefront_country : ProductCountry::defaultCountry());
    // The company that sells the product: the one an existing product names, and the one registered for the chosen country for a product that does
    // not exist yet. Nothing is drawn while legal entities are off, before the migration has run, or while that country has no registered company,
    // so a page of the default storefront with an empty registry is the page it is today.
    $sellingCompany = $isExistingProduct
        ? app(LegalEntityRegistry::class)->forProduct(product: $product, country: $chosenCountry)
        : app(LegalEntityRegistry::class)->forCountry(country: $chosenCountry);
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
    @if ($sellingCompany)
        <div class="col-md-6 col-lg-4">
            <div class="form-group">
                <label class="form-label d-flex align-items-center gap-2" for="selling-company">
                    {{ translate('selling_company') }}
                    <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                          aria-label="{{ translate('the_registered_company_that_sells_this_product_it_follows_from_the_country_and_is_not_typed') }}"
                          data-bs-title="{{ translate('the_registered_company_that_sells_this_product_it_follows_from_the_country_and_is_not_typed') }}">
                        <i class="fi fi-sr-info"></i>
                    </span>
                </label>
                <input type="text" id="selling-company" class="form-control"
                       value="{{ $sellingCompany->legal_name }}" disabled>
            </div>
        </div>
    @endif
@endif
