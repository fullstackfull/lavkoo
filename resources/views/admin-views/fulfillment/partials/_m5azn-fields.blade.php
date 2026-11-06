{{-- M5AZN specific settings. Variables from provider.blade.php: $config, $secrets, $secretField, $importCategories. --}}
<div class="col-md-6">
    <label class="form-label" for="email">{{ translate('m5azn_seller_email') }}</label>
    <input class="form-control" type="text" id="email" name="email" autocomplete="off" placeholder="{{ $secretField('email') }}">
    @if ($secrets['email'] ?? false)<label class="form-check fs-12 mt-1"><input class="form-check-input" type="checkbox" name="clear_secrets[]" value="email"> <span class="form-check-label">{{ translate('clear_stored_value') }}</span></label>@endif
</div>
<div class="col-md-6">
    <label class="form-label" for="password">{{ translate('m5azn_seller_password') }}</label>
    <input class="form-control" type="password" id="password" name="password" autocomplete="new-password" placeholder="{{ $secretField('password') }}">
    @if ($secrets['password'] ?? false)<label class="form-check fs-12 mt-1"><input class="form-check-input" type="checkbox" name="clear_secrets[]" value="password"> <span class="form-check-label">{{ translate('clear_stored_value') }}</span></label>@endif
</div>
<div class="col-md-4"><label class="form-label" for="platform_name">{{ translate('platform_name_sent_to_m5azn') }}</label>
    <input class="form-control" id="platform_name" name="platform_name" value="{{ $config->string('platform_name') }}"></div>
<div class="col-md-2"><label class="form-label" for="country_code">{{ translate('country_code') }}</label>
    <input class="form-control" id="country_code" name="country_code" value="{{ $config->string('country_code', 'SA') }}" maxlength="2"></div>
<div class="col-md-3"><label class="form-label" for="country_name">{{ translate('country_name') }}</label>
    <input class="form-control" id="country_name" name="country_name" value="{{ $config->string('country_name', 'Saudi Arabia') }}"></div>
<div class="col-md-3"><label class="form-label" for="language">{{ translate('api_language') }}</label>
    <select class="form-select" id="language" name="language">@foreach (['en', 'ar'] as $lang)<option value="{{ $lang }}" @selected($config->string('language', 'en') === $lang)>{{ $lang }}</option>@endforeach</select></div>
<div class="col-md-3"><label class="form-label" for="device">X-Device</label><input class="form-control" id="device" name="device" value="{{ $config->string('device', 'Android') }}"></div>
<div class="col-md-3"><label class="form-label" for="api_version">X-Api</label><input class="form-control" id="api_version" name="api_version" value="{{ $config->string('api_version', '1.0') }}"></div>
<div class="col-md-3"><label class="form-label" for="app_version">X-Version</label><input class="form-control" id="app_version" name="app_version" value="{{ $config->string('app_version', '1.0') }}"></div>

<div class="col-12"><hr><h6>{{ translate('catalog_and_import') }}</h6></div>
<div class="col-md-4"><label class="form-label" for="catalog_product_type">{{ translate('catalog_product_type') }}</label>
    <input class="form-control" id="catalog_product_type" name="catalog_product_type" value="{{ $config->string('catalog_product_type', 'local') }}">
    <div class="fs-12 text-muted">{{ translate('catalog_product_type_help') }}</div></div>
<div class="col-md-4"><label class="form-label" for="catalog_category_id">{{ translate('catalog_category_filter') }}</label>
    <input class="form-control" id="catalog_category_id" name="catalog_category_id" value="{{ $config->string('catalog_category_id') }}"></div>
<div class="col-md-4"><label class="form-label" for="default_category_id">{{ translate('store_category_for_imports') }}</label>
    <select class="form-select" id="default_category_id" name="default_category_id">
        <option value="">{{ translate('select_category') }}</option>
        @foreach ($importCategories as $category)
            <option value="{{ $category->id }}" @selected((int) $config->get('default_category_id') === (int) $category->id)>{{ $category->name }}</option>
        @endforeach
    </select></div>
<div class="col-md-4"><div class="form-check form-switch">
    <input type="hidden" name="product_sync_enabled" value="0"><input class="form-check-input" type="checkbox" role="switch" id="product_sync_enabled" name="product_sync_enabled" value="1" @checked($config->bool('product_sync_enabled'))>
    <label class="form-check-label" for="product_sync_enabled">{{ translate('pull_selected_products') }}</label></div></div>
<div class="col-md-4"><label class="form-label" for="product_sync_interval_minutes">{{ translate('product_sync_interval_minutes') }}</label>
    <input class="form-control" type="number" min="5" max="1440" id="product_sync_interval_minutes" name="product_sync_interval_minutes" value="{{ $config->int('product_sync_interval_minutes', 30) }}">
    <div class="fs-12 text-muted">{{ translate('sync_interval_help') }}</div></div>
<div class="col-md-4"><div class="form-check form-switch">
    <input type="hidden" name="import_enabled" value="0"><input class="form-check-input" type="checkbox" role="switch" id="import_enabled" name="import_enabled" value="1" @checked($config->bool('import_enabled'))>
    <label class="form-check-label" for="import_enabled">{{ translate('import_into_store_automatically') }}</label>
    <div class="fs-12 text-muted">{{ translate('import_needs_verified_map') }}</div></div></div>
<div class="col-md-4"><div class="form-check form-switch">
    <input type="hidden" name="publish_on_import" value="0"><input class="form-check-input" type="checkbox" role="switch" id="publish_on_import" name="publish_on_import" value="1" @checked($config->bool('publish_on_import'))>
    <label class="form-check-label" for="publish_on_import">{{ translate('publish_on_import') }}</label>
    <div class="fs-12 text-muted">{{ translate('publish_on_import_help') }}</div></div></div>
<div class="col-md-4"><div class="form-check form-switch">
    <input type="hidden" name="inventory_sync_enabled" value="0"><input class="form-check-input" type="checkbox" role="switch" id="inventory_sync_enabled" name="inventory_sync_enabled" value="1" @checked($config->bool('inventory_sync_enabled'))>
    <label class="form-check-label" for="inventory_sync_enabled">{{ translate('follow_m5azn_stock') }}</label></div></div>
<div class="col-md-4"><label class="form-label" for="inventory_sync_interval_minutes">{{ translate('inventory_sync_interval_minutes') }}</label>
    <input class="form-control" type="number" min="5" max="1440" id="inventory_sync_interval_minutes" name="inventory_sync_interval_minutes" value="{{ $config->int('inventory_sync_interval_minutes', 30) }}"></div>
<div class="col-md-4"><div class="form-check form-switch">
    <input type="hidden" name="update_content_on_sync" value="0"><input class="form-check-input" type="checkbox" role="switch" id="update_content_on_sync" name="update_content_on_sync" value="1" @checked($config->bool('update_content_on_sync'))>
    <label class="form-check-label" for="update_content_on_sync">{{ translate('update_content_on_sync') }}</label></div></div>

<div class="col-12"><hr><h6>{{ translate('pricing') }}</h6>
    <div class="alert alert-info fs-12 py-2">{{ translate('m5azn_margin_limitation') }}</div></div>
<div class="col-md-6"><label class="form-label" for="price_policy">{{ translate('price_policy') }}</label>
    <select class="form-select" id="price_policy" name="price_policy">
        <option value="store_controlled" @selected($config->string('price_policy', 'store_controlled') === 'store_controlled')>{{ translate('price_policy_store_controlled') }}</option>
        <option value="supplier_retail" @selected($config->string('price_policy') === 'supplier_retail')>{{ translate('price_policy_supplier_retail') }}</option>
    </select><div class="fs-12 text-muted">{{ translate('price_policy_help') }}</div></div>
<div class="col-md-6"><label class="form-label" for="min_margin_percent">{{ translate('minimum_margin_percent') }}</label>
    <input class="form-control" type="number" min="0" id="min_margin_percent" name="min_margin_percent" value="{{ $config->get('min_margin_percent', 0) }}"></div>

<div class="col-12"><hr><h6>{{ translate('order_responses') }}</h6></div>
<div class="col-md-6"><label class="form-label" for="order_response_reference_path">{{ translate('order_response_reference_path') }}</label>
    <input class="form-control" id="order_response_reference_path" name="order_response_reference_path" value="{{ $config->string('order_response_reference_path') }}" placeholder="data.id">
    <div class="fs-12 text-muted">{{ translate('order_response_reference_path_help') }}</div></div>
<div class="col-md-6"><label class="form-label" for="status_map">{{ translate('status_map') }} (JSON)</label>
    <textarea class="form-control font-monospace fs-12" rows="3" id="status_map" name="status_map" placeholder='{"shipped":"in_transit","delivered":"delivered"}'>{{ json_encode($config->get('status_map', []) ?: new stdClass, JSON_UNESCAPED_UNICODE) }}</textarea>
    <div class="fs-12 text-muted">{{ translate('status_map_help') }}</div></div>
