@extends('layouts.admin.app')

@section('title', translate('delivery_syria'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('3rd_Party') }} - {{ translate('Other_Configurations') }}
            </h2>
        </div>
        @include('admin-views.third-party._third-party-others-menu')

        <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded-8 mb-3">
            <div class="d-flex gap-2 align-items-start">
                <i class="fi fi-sr-info text-info mt-1"></i>
                <div>
                    <h4 class="fw-medium mb-1">{{ translate('delivery_syria_is_an_optional_shipping_company') }}</h4>
                    <span>{{ translate('paste_the_secret_from_your_delivery_syria_account_then_enable_and_verify_to_connect') }}</span>
                </div>
            </div>
        </div>

        <form action="{{ config('app.mode') != 'demo' ? route('admin.third-party.delivery-syria') : 'javascript:' }}"
              method="POST">
            @csrf

            {{-- Status --}}
            <div class="card mb-3">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8 col-xl-9">
                            <h2>{{ translate('delivery_syria') }}</h2>
                            <p class="mb-0 fs-12">
                                {{ translate('when_enabled_orders_can_be_dispatched_to_delivery_syria_and_status_updates_are_received') }}.
                            </p>
                        </div>
                        <div class="col-md-4 col-xl-3">
                            <div class="mt-3 mt-md-0">
                                <div class="d-flex justify-content-between align-items-center gap-3 border rounded px-20 py-3 user-select-none">
                                    <span class="fw-medium text-dark">{{ translate('Status') }}</span>
                                    <label class="switcher" for="delivery-syria-status">
                                        <input class="switcher_input" type="checkbox" value="1" name="status"
                                               id="delivery-syria-status" {{ $enabled ? 'checked' : '' }}>
                                        <span class="switcher_control"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Credentials --}}
            <div class="card mb-3">
                <div class="card-body">
                    <div class="mb-3">
                        <h2>{{ translate('credentials') }}</h2>
                        <p class="mb-0 fs-12">{{ translate('your_secrets_are_stored_encrypted_leave_a_field_blank_to_keep_the_current_value') }}.</p>
                    </div>
                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="row g-4">
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label class="form-label">
                                        {{ translate('delivery_syria_secret') }} ({{ translate('outbound_bearer') }})
                                        @if($hasSecret)<span class="badge badge-soft-success ms-1">{{ translate('saved') }}</span>@endif
                                    </label>
                                    <input type="password" class="form-control" name="secret_token" autocomplete="new-password"
                                           placeholder="{{ $hasSecret ? translate('leave_blank_to_keep_current') : 'sk_...' }}">
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label class="form-label">
                                        {{ translate('webhook_token') }} ({{ translate('inbound_bearer') }})
                                        @if($hasWebhookToken)<span class="badge badge-soft-success ms-1">{{ translate('saved') }}</span>@endif
                                    </label>
                                    <input type="password" class="form-control" name="webhook_token" autocomplete="new-password"
                                           placeholder="{{ $hasWebhookToken ? translate('leave_blank_to_keep_current') : translate('token_delivery_syria_will_send_us') }}">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Connection & defaults --}}
            <div class="card mb-3">
                <div class="card-body">
                    <div class="mb-3">
                        <h2>{{ translate('connection_and_parcel_defaults') }}</h2>
                        <p class="mb-0 fs-12">{{ translate('these_defaults_are_used_when_creating_a_parcel') }}.</p>
                    </div>
                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="row g-4">
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label class="form-label">{{ translate('base_url') }}</label>
                                    <input type="text" class="form-control" name="base_url" value="{{ $baseUrl }}" dir="ltr">
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label class="form-label">{{ translate('platform_header') }}</label>
                                    <input type="text" class="form-control" name="platform" value="{{ $platform }}" dir="ltr">
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="form-group">
                                    <label class="form-label">{{ translate('hub_id') }}</label>
                                    <input type="text" class="form-control" name="hub_id" value="{{ $hubId }}" dir="ltr">
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="form-group">
                                    <label class="form-label">{{ translate('delivery_type_id') }}</label>
                                    <input type="text" class="form-control" name="delivery_type_id" value="{{ $deliveryTypeId }}" dir="ltr">
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="form-group">
                                    <label class="form-label">{{ translate('packaging_id') }}</label>
                                    <input type="text" class="form-control" name="packaging_id" value="{{ $packagingId }}" dir="ltr">
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label class="form-label">{{ translate('pickup_phone') }}</label>
                                    <input type="text" class="form-control" name="pickup_phone" value="{{ $pickupPhone }}" dir="ltr">
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label class="form-label">{{ translate('pickup_address') }}</label>
                                    <input type="text" class="form-control" name="pickup_address" value="{{ $pickupAddress }}">
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label class="form-label">{{ translate('pickup_latitude') }}</label>
                                    <input type="text" class="form-control" name="pickup_lat" value="{{ $pickupLat }}" dir="ltr">
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label class="form-label">{{ translate('pickup_longitude') }}</label>
                                    <input type="text" class="form-control" name="pickup_long" value="{{ $pickupLong }}" dir="ltr">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end flex-wrap gap-3 mt-4">
                        <button type="reset" class="btn btn-secondary w-120 px-4">{{ translate('reset') }}</button>
                        <button type="{{ config('app.mode') != 'demo' ? 'submit' : 'button' }}"
                                class="btn btn-primary w-120 px-4 {{ config('app.mode') != 'demo' ? '' : 'call-demo-alert' }}">
                            {{ translate('save') }}
                        </button>
                    </div>
                </div>
            </div>
        </form>

        {{-- Inbound webhook endpoint --}}
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="mb-1">{{ translate('inbound_status_webhook') }}</h2>
                <p class="mb-3 fs-12">{{ translate('give_this_endpoint_and_the_webhook_token_to_delivery_syria_so_it_can_push_status_updates') }}.</p>
                <div class="p-12 p-sm-20 bg-section rounded">
                    <div class="mb-2"><span class="fw-medium">{{ translate('endpoint') }}:</span>
                        <code dir="ltr">POST {{ $webhookUrl }}</code>
                    </div>
                    <div><span class="fw-medium">{{ translate('required_header') }}:</span>
                        <code dir="ltr">X-Platform: {{ $inboundPlatform }}</code>
                    </div>
                </div>
            </div>
        </div>

        {{-- Verify & sync + rates --}}
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex gap-3 justify-content-between align-items-center flex-wrap mb-3">
                    <div>
                        <h2 class="mb-1">{{ translate('governorate_rates') }}</h2>
                        <p class="mb-0 fs-12">{{ translate('per_kg_price_synced_from_delivery_syria') }}.</p>
                    </div>
                    <form action="{{ config('app.mode') != 'demo' ? route('admin.third-party.delivery-syria.verify-sync') : 'javascript:' }}" method="POST">
                        @csrf
                        <button type="{{ config('app.mode') != 'demo' ? 'submit' : 'button' }}"
                                class="btn btn-outline-primary {{ config('app.mode') != 'demo' ? '' : 'call-demo-alert' }}">
                            <i class="fi fi-rr-refresh"></i> {{ translate('verify_and_sync') }}
                        </button>
                    </form>
                </div>
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                        <tr>
                            <th>{{ translate('governorate') }}</th>
                            <th>{{ translate('code') }}</th>
                            <th>{{ translate('base_cost_per_kg') }}</th>
                            <th>{{ translate('synced_at') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($rates as $rate)
                            <tr>
                                <td>{{ $rate->governorate }}</td>
                                <td dir="ltr">{{ $rate->code }}</td>
                                <td>{{ $rate->base_cost }}</td>
                                <td>{{ $rate->synced_at }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">{{ translate('no_rates_synced_yet') }}</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
