{{--
    The structured Saudi address fields: region, city, district and National Address.

    Parameters (all optional):
      $address          the saved address (object or array) to preselect, e.g. when editing
      $ui               'bs4' (storefront, vendor) or 'bs5' (admin): only decides the form classes
      $idPrefix         prefix for element ids when the page has more than one block
      $namePrefix       prefix for the field names (e.g. 'billing_')
      $countrySelector  CSS selector of the country control of the same form; Saudi Arabia shows this block, any other country hides it
      $required         whether the fields are required (default true)
      $notice           a line shown above the fields (for Saudi addresses only), e.g. why an old address has to be completed

    The block needs resources/views/partials/address/_unified-address-script.blade.php on the same page; the legacy city and ZIP rows of
    the form are marked data-ua-other so they show only for other countries.
--}}
@php
    $address = $address ?? null;
    $ui = $ui ?? 'bs4';
    $idPrefix = $idPrefix ?? 'ua';
    $namePrefix = $namePrefix ?? '';
    $countrySelector = $countrySelector ?? '';
    $required = $required ?? true;
    $notice = $notice ?? null;

    $groupClass = $ui === 'bs5' ? 'mb-3' : 'form-group';
    $labelClass = $ui === 'bs5' ? 'form-label' : '';

    $catalog = app(\App\Services\Address\AddressCatalog::class);
    $regions = $catalog->regions();
    $selectedRegionId = (int) data_get($address, 'region_id', 0);
    $selectedCity = ($cityId = (int) data_get($address, 'city_id', 0)) > 0 ? $catalog->city($cityId) : null;
    $locale = app()->getLocale();
@endphp
<div class="unified-address" data-unified-address
     data-locale="{{ $locale }}"
     data-cities-url="{{ route('address.cities') }}"
     data-saudi-values="{{ json_encode(['SA', 'KSA', \App\Services\Address\AddressCountry::LEGACY_NAME, 'السعودية', 'المملكة العربية السعودية']) }}"
     data-ua-country-selector="{{ $countrySelector }}"
     data-text-empty="{{ translate('address_no_cities_found') }}"
     data-text-loading="{{ translate('address_loading') }}"
     data-text-failed="{{ translate('address_city_load_failed') }}"
     data-text-region-first="{{ translate('address_choose_region_first') }}"
     data-text-region-empty="{{ translate('address_no_regions_found') }}"
     data-text-results="{{ translate('address_results_available') }}"
     data-text-select="{{ translate('address_select_from_list') }}"
     data-text-national="{{ translate('address_national_address_format_hint') }}">
    @if($notice)
        <div class="alert alert-warning ua-notice py-2 px-3 fs-12" role="alert" data-ua-saudi>{{ $notice }}</div>
    @endif
    <div class="{{ $groupClass }}" data-ua-saudi>
        <label class="{{ $labelClass }}" for="{{ $idPrefix }}-region-input">{{ translate('address_region') }}@if($required) <span class="text-danger" aria-hidden="true">*</span>@endif</label>
        <div data-ua-combobox>
            <input type="text" class="form-control" id="{{ $idPrefix }}-region-input" data-ua-region-input data-ua-always hidden
                   placeholder="{{ translate('address_choose_region') }}" value="{{ optional($regions->firstWhere('id', $selectedRegionId))->name() }}">
            <ul class="ua-list" data-ua-region-list hidden></ul>
        </div>
        <select class="form-control" id="{{ $idPrefix }}-region" name="{{ $namePrefix }}region_id" data-ua-region @if($required) required @endif>
            <option value="">{{ translate('address_choose_region') }}</option>
            @foreach($regions as $region)
                <option value="{{ $region->id }}" data-name-ar="{{ $region->name_ar }}" data-name-en="{{ $region->name_en }}" @selected($region->id === $selectedRegionId)>{{ $region->name() }}</option>
            @endforeach
        </select>
    </div>

    <div class="{{ $groupClass }}" data-ua-saudi>
        <label class="{{ $labelClass }}" for="{{ $idPrefix }}-city-input">{{ translate('address_city') }}@if($required) <span class="text-danger" aria-hidden="true">*</span>@endif</label>
        <div data-ua-combobox>
            <input type="text" class="form-control" id="{{ $idPrefix }}-city-input" data-ua-input data-ua-always @if($required) data-ua-required aria-required="true" @endif
                   placeholder="{{ translate('address_choose_city') }}" value="{{ $selectedCity?->name() }}"
                   data-name-ar="{{ $selectedCity?->name_ar }}" data-name-en="{{ $selectedCity?->name_en }}" disabled>
            <input type="hidden" name="{{ $namePrefix }}city_id" data-ua-city-id value="{{ $selectedCity?->id }}">
            <ul class="ua-list" data-ua-list hidden></ul>
        </div>
    </div>

    <div class="{{ $groupClass }}" data-ua-saudi>
        <label class="{{ $labelClass }}" for="{{ $idPrefix }}-district">{{ translate('address_district') }}</label>
        <input type="text" class="form-control" id="{{ $idPrefix }}-district" name="{{ $namePrefix }}district" data-ua-district maxlength="191"
               value="{{ data_get($address, 'district') }}">
    </div>

    <div class="{{ $groupClass }}" data-ua-saudi>
        <label class="{{ $labelClass }}" for="{{ $idPrefix }}-national-address">{{ translate('address_national_address') }}@if($required) <span class="text-danger" aria-hidden="true">*</span>@endif</label>
        <input type="text" class="form-control" id="{{ $idPrefix }}-national-address" name="{{ $namePrefix }}national_address" data-ua-national
               maxlength="14" dir="ltr" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="RAGI2929"
               pattern="{{ \App\Services\Address\NationalAddress::inputPattern() }}" aria-describedby="{{ $idPrefix }}-national-address-help" @if($required) required @endif
               value="{{ data_get($address, 'national_address') }}">
        <small class="form-text" id="{{ $idPrefix }}-national-address-help">{{ translate('address_national_address_help') }}</small>
    </div>
</div>
