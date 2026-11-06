{{-- Torod Point specific settings. Variables from provider.blade.php: $config, $secrets, $secretField, $defaultCompanyId. --}}
<div class="col-md-4">
    <label class="form-label" for="company_id">{{ translate('company_id') }}</label>
    <input class="form-control" id="company_id" name="company_id" inputmode="numeric" value="{{ old('company_id', $config->string('company_id')) }}" placeholder="{{ $defaultCompanyId }}">
    <div class="fs-12 text-muted">{{ translate('company_id_help') }} <strong>{{ $defaultCompanyId }}</strong></div>
</div>
<div class="col-md-8">
    <label class="form-label" for="lastmile_base_url">{{ translate('lastmile_api_base_url') }}</label>
    <input class="form-control" type="url" id="lastmile_base_url" name="lastmile_base_url" value="{{ old('lastmile_base_url', $config->string('lastmile_base_url')) }}">
</div>

<div class="col-12"><hr><h6>{{ translate('fulfillment_api_credentials') }}</h6><div class="fs-12 text-muted">{{ translate('fulfillment_api_credentials_help') }}</div></div>
<div class="col-md-6"><label class="form-label" for="fulfillment_username">{{ translate('username') }}</label>
    <input class="form-control" id="fulfillment_username" name="fulfillment_username" autocomplete="off" placeholder="{{ $secretField('fulfillment_username') }}">
    @if ($secrets['fulfillment_username'] ?? false)<label class="form-check fs-12 mt-1"><input class="form-check-input" type="checkbox" name="clear_secrets[]" value="fulfillment_username"> <span class="form-check-label">{{ translate('clear_stored_value') }}</span></label>@endif</div>
<div class="col-md-6"><label class="form-label" for="fulfillment_password">{{ translate('password') }}</label>
    <input class="form-control" type="password" id="fulfillment_password" name="fulfillment_password" autocomplete="new-password" placeholder="{{ $secretField('fulfillment_password') }}">
    @if ($secrets['fulfillment_password'] ?? false)<label class="form-check fs-12 mt-1"><input class="form-check-input" type="checkbox" name="clear_secrets[]" value="fulfillment_password"> <span class="form-check-label">{{ translate('clear_stored_value') }}</span></label>@endif</div>

<div class="col-12"><hr><h6>{{ translate('lastmile_api_credentials') }}</h6><div class="fs-12 text-muted">{{ translate('lastmile_api_credentials_help') }}</div></div>
<div class="col-md-6"><label class="form-label" for="lastmile_email">{{ translate('email') }}</label>
    <input class="form-control" id="lastmile_email" name="lastmile_email" autocomplete="off" placeholder="{{ $secretField('lastmile_email') }}">
    @if ($secrets['lastmile_email'] ?? false)<label class="form-check fs-12 mt-1"><input class="form-check-input" type="checkbox" name="clear_secrets[]" value="lastmile_email"> <span class="form-check-label">{{ translate('clear_stored_value') }}</span></label>@endif</div>
<div class="col-md-6"><label class="form-label" for="lastmile_password">{{ translate('password') }}</label>
    <input class="form-control" type="password" id="lastmile_password" name="lastmile_password" autocomplete="new-password" placeholder="{{ $secretField('lastmile_password') }}">
    @if ($secrets['lastmile_password'] ?? false)<label class="form-check fs-12 mt-1"><input class="form-check-input" type="checkbox" name="clear_secrets[]" value="lastmile_password"> <span class="form-check-label">{{ translate('clear_stored_value') }}</span></label>@endif</div>

<div class="col-12"><hr><h6>{{ translate('warehouse_orders') }}</h6></div>
<div class="col-md-4"><label class="form-label" for="phone_format">{{ translate('receiver_phone_format') }}</label>
    <select class="form-select" id="phone_format" name="phone_format">
        <option value="international_00" @selected($config->string('phone_format', 'international_00') === 'international_00')>00966XXXXXXXXX</option>
        <option value="local" @selected($config->string('phone_format') === 'local')>05XXXXXXXX</option>
    </select></div>
<div class="col-md-4"><label class="form-label" for="default_weight_kg">{{ translate('default_parcel_weight_kg') }}</label>
    <input class="form-control" type="number" step="0.1" min="0" id="default_weight_kg" name="default_weight_kg" value="{{ $config->get('default_weight_kg', 1) }}"></div>
<div class="col-md-4"><label class="form-label" for="cost_source">{{ translate('cost_field_carries') }}</label>
    <select class="form-select" id="cost_source" name="cost_source">
        @foreach (['omit', 'shipping_fee', 'items_total'] as $source)<option value="{{ $source }}" @selected($config->string('cost_source', 'omit') === $source)>{{ translate('cost_source_' . $source) }}</option>@endforeach
    </select><div class="fs-12 text-muted">{{ translate('cost_field_help') }}</div></div>
<div class="col-md-6"><label class="form-label" for="cod_collection_method">{{ translate('cod_collection_method_value') }}</label>
    <input class="form-control" id="cod_collection_method" name="cod_collection_method" value="{{ $config->string('cod_collection_method') }}">
    <div class="fs-12 text-muted">{{ translate('cod_collection_method_help') }}</div></div>
<div class="col-md-6"><label class="form-label" for="order_response_reference_path">{{ translate('order_response_reference_path') }}</label>
    <input class="form-control" id="order_response_reference_path" name="order_response_reference_path" value="{{ $config->string('order_response_reference_path') }}" placeholder="barcode">
    <div class="fs-12 text-muted">{{ translate('order_response_reference_path_help') }}</div></div>

<div class="col-12"><hr><h6>{{ translate('products_and_stock') }}</h6></div>
<div class="col-md-4"><div class="form-check form-switch">
    <input type="hidden" name="product_sync_enabled" value="0"><input class="form-check-input" type="checkbox" role="switch" id="product_sync_enabled" name="product_sync_enabled" value="1" @checked($config->bool('product_sync_enabled'))>
    <label class="form-check-label" for="product_sync_enabled">{{ translate('read_warehouse_products') }}</label></div></div>
<div class="col-md-4"><div class="form-check form-switch">
    <input type="hidden" name="push_products_enabled" value="0"><input class="form-check-input" type="checkbox" role="switch" id="push_products_enabled" name="push_products_enabled" value="1" @checked($config->bool('push_products_enabled'))>
    <label class="form-check-label" for="push_products_enabled">{{ translate('allow_pushing_products_to_warehouse') }}</label></div></div>
<div class="col-md-4"><div class="form-check form-switch">
    <input type="hidden" name="inventory_sync_enabled" value="0"><input class="form-check-input" type="checkbox" role="switch" id="inventory_sync_enabled" name="inventory_sync_enabled" value="1" @checked($config->bool('inventory_sync_enabled'))>
    <label class="form-check-label" for="inventory_sync_enabled">{{ translate('follow_warehouse_stock') }}</label></div></div>
<div class="col-md-4"><label class="form-label" for="product_sync_interval_minutes">{{ translate('product_sync_interval_minutes') }}</label>
    <input class="form-control" type="number" min="5" max="1440" id="product_sync_interval_minutes" name="product_sync_interval_minutes" value="{{ $config->int('product_sync_interval_minutes', 30) }}">
    <div class="fs-12 text-muted">{{ translate('sync_interval_help') }}</div></div>
<div class="col-md-4"><label class="form-label" for="inventory_sync_interval_minutes">{{ translate('inventory_sync_interval_minutes') }}</label>
    <input class="form-control" type="number" min="5" max="1440" id="inventory_sync_interval_minutes" name="inventory_sync_interval_minutes" value="{{ $config->int('inventory_sync_interval_minutes', 30) }}"></div>
<div class="col-12"><div class="alert alert-warning fs-12 py-2 mb-0">
    @if ($stockField['path'])
        <input type="hidden" name="stock_field_path" value="{{ $stockField['path'] }}">
        <input type="hidden" name="loaded_stock_field_confirmed" value="{{ $stockField['stated'] ? 1 : 0 }}">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="stock_field_confirmed" name="stock_field_confirmed" value="1" @checked($stockField['stated'])>
            <label class="form-check-label" for="stock_field_confirmed"><strong>{{ translate('i_confirm_the_stock_field_is_available_to_sell') }}</strong> <code>{{ $stockField['path'] }}</code></label>
        </div>
    @else
        <div><strong>{{ translate('stock_field_path_not_set') }}</strong></div>
    @endif
    <div class="mt-1">{{ translate('torod_stock_confirmation_help') }}</div>
    @if ($stockField['stated'])<div class="mt-1">{{ translate('confirmed_at') }}: {{ $config->get('stock_field_confirmed_at') }}</div>@endif
</div></div>
<div class="col-12"><label class="form-label" for="product_defaults">{{ translate('warehouse_product_defaults') }} (JSON)</label>
    <textarea class="form-control font-monospace fs-12" rows="3" id="product_defaults" name="product_defaults" placeholder='{"categoryId":309,"storageType":"BIN","pickingMethod":"FIFO"}'>{{ json_encode($config->get('product_defaults', []) ?: new stdClass, JSON_UNESCAPED_UNICODE) }}</textarea>
    <div class="fs-12 text-muted">{{ translate('warehouse_product_defaults_help') }}</div></div>

<div class="col-12"><hr><h6>{{ translate('webhook_security') }}</h6></div>
<div class="col-md-4"><div class="form-check form-switch">
    <input type="hidden" name="webhooks_enabled" value="0"><input class="form-check-input" type="checkbox" role="switch" id="webhooks_enabled" name="webhooks_enabled" value="1" @checked($config->bool('webhooks_enabled'))>
    <label class="form-check-label" for="webhooks_enabled">{{ translate('accept_webhooks') }}</label></div></div>
<div class="col-md-4"><div class="form-check form-switch">
    <input type="hidden" name="verify_terminal_webhooks" value="0"><input class="form-check-input" type="checkbox" role="switch" id="verify_terminal_webhooks" name="verify_terminal_webhooks" value="1" @checked($config->bool('verify_terminal_webhooks', true))>
    <label class="form-check-label" for="verify_terminal_webhooks">{{ translate('confirm_delivered_events_with_provider') }}</label>
    <div class="fs-12 text-muted">{{ translate('confirm_delivered_events_help') }}</div></div></div>
<div class="col-md-4"><label class="form-label" for="webhook_ip_allowlist">{{ translate('webhook_ip_allow_list') }}</label>
    <input class="form-control" id="webhook_ip_allowlist" name="webhook_ip_allowlist" value="{{ $config->string('webhook_ip_allowlist') }}" placeholder="203.0.113.7, 203.0.113.8"></div>
