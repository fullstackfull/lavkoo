{{-- The fields of a channel account, for creating one and for editing it. Secrets are write-only (see _secret-field).
     Expects $channel (SalesChannel), $creating (bool), $account (?SalesChannelAccount), $settings, $secretFields, $secretsSet (secret => bool), $carrierCodesText, $labels. --}}
@use('App\Enums\SalesChannels\ExecutionPolicy')
@use('App\Enums\SalesChannels\SalesChannel')
@use('App\Http\Controllers\Admin\SalesChannels\Support\AccountForm')
@php
    $isAmazon = $channel === SalesChannel::Amazon;
    $setting = static fn (string $key, mixed $default = null): mixed => old('settings.' . $key, $settings[$key] ?? $default);
@endphp
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label" for="account-name">{{ translate('account_name') }}</label>
        <input class="form-control" id="account-name" name="name" maxlength="120" required value="{{ old('name', $account?->name) }}">
        @error('name')<div class="text-danger fs-12">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        @if ($creating)
            <label class="form-label" for="account-environment">{{ translate('environment') }}</label>
            <select class="form-select" id="account-environment" name="environment" required>
                @foreach (['production', 'sandbox'] as $environment)
                    <option value="{{ $environment }}" @selected(old('environment', 'production') === $environment)>{{ $labels->environment($environment) }}</option>
                @endforeach
            </select>
            @error('environment')<div class="text-danger fs-12">{{ $message }}</div>@enderror
        @else
            <span class="form-label d-block">{{ translate('environment') }}</span>
            <x-k.badge :tone="$account->isSandbox() ? 'warning' : 'info'">{{ $labels->environment($account->environment) }}</x-k.badge>
            <div class="fs-12 text-muted">{{ translate('environment_is_fixed_after_creation') }}</div>
        @endif
    </div>
    <div class="col-md-3">
        @if ($creating)
            <label class="form-label" for="account-seller">{{ translate('seller_id_platform_is_zero') }}</label>
            <input class="form-control" type="number" min="0" id="account-seller" name="seller_id" value="{{ old('seller_id', 0) }}">
            @error('seller_id')<div class="text-danger fs-12">{{ $message }}</div>@enderror
        @else
            <span class="form-label d-block">{{ translate('seller') }}</span>
            {{ $account->seller_id === 0 ? translate('platform') : '#' . $account->seller_id }}
        @endif
    </div>
    <div class="col-md-6">
        <label class="form-label" for="account-external-id">{{ translate($isAmazon ? 'amazon_seller_id' : 'trendyol_seller_id') }}</label>
        <input class="form-control" id="account-external-id" name="external_account_id" maxlength="64" required inputmode="{{ $isAmazon ? 'text' : 'numeric' }}"
               value="{{ old('external_account_id', $account?->external_account_id) }}">
        <div class="fs-12 text-muted">{{ translate($isAmazon ? 'amazon_seller_id_help' : 'trendyol_seller_id_help') }}</div>
        @error('external_account_id')<div class="text-danger fs-12">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label class="form-label" for="account-currency">{{ translate('currency') }}</label>
        <input class="form-control" id="account-currency" name="currency" maxlength="3" placeholder="SAR" value="{{ old('currency', $account?->currency) }}">
        @error('currency')<div class="text-danger fs-12">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label" for="account-policy">{{ translate('when_no_rule_matches') }}</label>
        <select class="form-select" id="account-policy" name="execution_policy" required>
            @foreach (AccountForm::DEFAULT_POLICIES as $policy)
                <option value="{{ $policy->value }}" @selected(old('execution_policy', $account?->execution_policy ?? ExecutionPolicy::ManualReview->value) === $policy->value)>{{ $labels->executionPolicy($policy->value) }}</option>
            @endforeach
        </select>
        <div class="fs-12 text-muted">{{ translate('when_no_rule_matches_help') }}</div>
        @error('execution_policy')<div class="text-danger fs-12">{{ $message }}</div>@enderror
    </div>

    @if ($isAmazon)
        <div class="col-md-3">
            <label class="form-label" for="amazon-region">{{ translate('amazon_region') }}</label>
            <select class="form-select" id="amazon-region" name="settings[region]">
                @foreach (['na', 'eu', 'fe'] as $region)
                    <option value="{{ $region }}" @selected($setting('region') === $region)>{{ $labels->amazonRegion($region) }}</option>
                @endforeach
            </select>
            @error('settings.region')<div class="text-danger fs-12">{{ $message }}</div>@enderror
        </div>
        <div class="col-12">
            @if ($creating)
                <label class="form-label" for="amazon-marketplaces">{{ translate('marketplace_ids') }}</label>
                <input class="form-control" id="amazon-marketplaces" name="settings[marketplace_ids]" required placeholder="A17E79C6D8DWNP"
                       value="{{ old('settings.marketplace_ids', implode(', ', (array) ($settings['marketplace_ids'] ?? []))) }}">
                <div class="fs-12 text-muted">{{ translate('marketplace_ids_help') }}</div>
                @error('settings.marketplace_ids')<div class="text-danger fs-12">{{ $message }}</div>@enderror
                @error('settings.marketplace_ids.*')<div class="text-danger fs-12">{{ $message }}</div>@enderror
            @else
                <span class="form-label d-block">{{ translate('marketplace_ids') }}</span>
                @forelse ((array) $account->marketplace_ids as $marketplaceId)
                    <x-k.badge tone="info" :dot="false">{{ $marketplaceId }}</x-k.badge>
                @empty
                    @include('admin-views.sales-channels.partials._missing', ['label' => translate('not_set')])
                @endforelse
                <div class="fs-12 text-muted">{{ translate('marketplace_ids_are_fixed_after_creation') }}</div>
            @endif
        </div>
        <div class="col-md-6">@include('admin-views.sales-channels.partials._switch', ['name' => 'settings[sync_fba_inventory]', 'id' => 'amazon-sync-fba', 'label' => translate('read_fba_inventory'), 'checked' => (bool) $setting('sync_fba_inventory')])</div>
        <div class="col-md-6">@include('admin-views.sales-channels.partials._switch', ['name' => 'settings[request_buyer_data]', 'id' => 'amazon-buyer-data', 'label' => translate('ask_amazon_for_buyer_data'), 'checked' => (bool) $setting('request_buyer_data'), 'help' => translate('buyer_data_is_never_stored')])</div>
        <div class="col-md-6">@include('admin-views.sales-channels.partials._switch', ['name' => 'settings[auto_link_exact_sku]', 'id' => 'amazon-auto-link', 'label' => translate('link_listings_with_an_exact_sku'), 'checked' => (bool) $setting('auto_link_exact_sku'), 'help' => translate('auto_link_help')])</div>
        <div class="col-md-6">@include('admin-views.sales-channels.partials._switch', ['name' => 'settings[route_cod_orders]', 'id' => 'amazon-route-cod', 'label' => translate('route_cash_on_delivery_orders'), 'checked' => (bool) $setting('route_cod_orders'), 'help' => translate('route_cod_help')])</div>
    @else
        <div class="col-md-3">
            <label class="form-label" for="trendyol-storefront">{{ translate('storefront_code') }}</label>
            <select class="form-select" id="trendyol-storefront" name="settings[storefront_code]">
                @foreach (['SA', 'AE', 'KW', ''] as $storefront)
                    <option value="{{ $storefront }}" @selected((string) $setting('storefront_code') === $storefront)>{{ $labels->trendyolStorefront($storefront) }}</option>
                @endforeach
            </select>
            @error('settings.storefront_code')<div class="text-danger fs-12">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="trendyol-integration">{{ translate('integration_name') }}</label>
            <input class="form-control" id="trendyol-integration" name="settings[integration_name]" maxlength="30" required value="{{ $setting('integration_name') }}">
            <div class="fs-12 text-muted">{{ translate('integration_name_help') }}</div>
            @error('settings.integration_name')<div class="text-danger fs-12">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-2">
            <label class="form-label" for="trendyol-language">{{ translate('response_language') }}</label>
            <select class="form-select" id="trendyol-language" name="settings[accept_language]">
                @foreach (['ar', 'en', 'tr'] as $language)
                    <option value="{{ $language }}" @selected($setting('accept_language') === $language)>{{ $labels->responseLanguage($language) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="trendyol-torod-field">{{ translate('torod_reference_field') }}</label>
            <select class="form-select" id="trendyol-torod-field" name="settings[torod_reference_field]">
                @foreach (['order_number', 'package_id', 'tracking_number'] as $field)
                    <option value="{{ $field }}" @selected($setting('torod_reference_field') === $field)>{{ $labels->torodReference($field) }}</option>
                @endforeach
            </select>
            <div class="fs-12 text-muted">{{ translate('torod_reference_field_help') }}</div>
        </div>
        <div class="col-md-4">@include('admin-views.sales-channels.partials._switch', ['name' => 'settings[auto_link_exact_barcode]', 'id' => 'trendyol-auto-link', 'label' => translate('link_listings_with_an_exact_barcode'), 'checked' => (bool) $setting('auto_link_exact_barcode'), 'help' => translate('auto_link_help')])</div>
        <div class="col-md-4">@include('admin-views.sales-channels.partials._switch', ['name' => 'settings[prices_include_tax]', 'id' => 'trendyol-prices-tax', 'label' => translate('prices_include_tax'), 'checked' => (bool) $setting('prices_include_tax'), 'help' => translate('prices_include_tax_help')])</div>
        <div class="col-md-4">@include('admin-views.sales-channels.partials._switch', ['name' => 'settings[route_cod_orders]', 'id' => 'trendyol-route-cod', 'label' => translate('route_cash_on_delivery_orders'), 'checked' => (bool) $setting('route_cod_orders'), 'help' => translate('route_cod_help')])</div>
        <div class="col-12">
            <label class="form-label" for="trendyol-carrier-codes">{{ translate('carrier_codes') }}</label>
            <textarea class="form-control font-monospace fs-12" id="trendyol-carrier-codes" name="settings[carrier_codes]" rows="3" placeholder="smsa=SMSA">{{ old('settings.carrier_codes', $carrierCodesText) }}</textarea>
            <div class="fs-12 text-muted">{{ translate('carrier_codes_help') }}</div>
            @error('settings.carrier_codes')<div class="text-danger fs-12">{{ $message }}</div>@enderror
            @error('settings.carrier_codes.*')<div class="text-danger fs-12">{{ $message }}</div>@enderror
        </div>
    @endif

    <div class="col-12">
        <hr class="my-1">
        <h6 class="mb-1">{{ translate('credentials') }}</h6>
        <div class="fs-12 text-muted">{{ translate('credentials_are_write_only') }}</div>
        @error('clear_secrets')<div class="text-danger fs-12">{{ $message }}</div>@enderror
    </div>
    @foreach ($secretFields as $secret)
        <div class="col-md-6">@include('admin-views.sales-channels.partials._secret-field', ['name' => $secret, 'label' => $labels->secret($secret), 'isSet' => (bool) ($secretsSet[$secret] ?? false)])</div>
    @endforeach
</div>
