@php
    use App\Services\Storefront\ProductCountry;
@endphp
@if (ProductCountry::isTracked())
    <div class="col-md-3">
        <label class="form-label" for="provider-storefront-country">{{ translate('storefront_country_of_this_connection') }}</label>
        <select class="form-select" id="provider-storefront-country" name="storefront_country">
            <option value="">{{ translate('the_default_storefronts_country') }}</option>
            @foreach (ProductCountry::options() as $connectionCountry)
                <option value="{{ $connectionCountry }}" @selected($config->string('storefront_country') === $connectionCountry)>{{ ProductCountry::label(country: $connectionCountry) }}</option>
            @endforeach
        </select>
    </div>
@endif
