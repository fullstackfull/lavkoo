{{--
    The storefront a new theme is made for (GCC F-01), shown only while themes are kept by storefront. It is chosen once, when the theme is created: the theme is
    activated, published and served for that storefront alone. Every configured storefront is offered, open or not, because a theme is prepared before its storefront opens.
--}}
@use('App\Services\Storefront\ProductCountry')
@use('App\Services\Theme\StorefrontThemeResolver')
@if (app(StorefrontThemeResolver::class)->keepsThemesByStorefront())
    <div class="form-group">
        <label class="form-label" for="theme-storefront">{{ translate('theme_storefront') }}</label>
        <select class="form-select" name="storefront_country" id="theme-storefront">
            @foreach (ProductCountry::options() as $country)
                <option value="{{ $country }}" {{ old('storefront_country', ProductCountry::defaultCountry()) === $country ? 'selected' : '' }}>
                    {{ ProductCountry::label(country: $country) }}
                </option>
            @endforeach
        </select>
        <small class="text-muted">{{ translate('the_storefront_a_theme_is_made_for_it_cannot_be_changed_after_the_theme_is_created') }}</small>
    </div>
@endif
