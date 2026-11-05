@extends('layouts.admin.app')

@section('title', $card['label'] . ' · ' . translate('Fulfillment_Providers'))

@php
    $isM5azn = $code === \App\Enums\Fulfillment\ProviderCode::M5azn;
    $secretField = static fn (string $name): string => ($secrets[$name] ?? false) ? '•••••••• (' . translate('configured') . ')' : '';
@endphp

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="$card['label']" :subtitle="translate('provider_role_' . $card['role'])">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.fulfillment-providers.index') }}">{{ translate('back_to_integration_center') }}</a>
                @if ($isM5azn)
                    <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.fulfillment-providers.m5azn.products') }}">{{ translate('imported_products') }}</a>
                @else
                    <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.fulfillment-providers.torod.matching') }}">{{ translate('product_matching') }}</a>
                @endif
            </x-slot:actions>
        </x-k.page-header>

        @if ($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <div class="row g-3">
            <div class="col-xl-8">
                <form method="POST" action="{{ route('admin.fulfillment-providers.update', $card['slug']) }}" autocomplete="off">
                    @csrf
                    <x-k.card :title="translate('connection')" class="mb-3">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="enabled" name="enabled" value="1" @checked($config->enabled)>
                            <label class="form-check-label" for="enabled">{{ translate('integration_enabled') }}</label>
                            <div class="fs-12 text-muted">{{ translate('integration_enabled_help') }}</div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label" for="base_url">{{ translate($isM5azn ? 'base_url' : 'fulfillment_api_base_url') }}</label>
                                <input class="form-control" type="url" id="base_url" name="base_url" value="{{ old('base_url', $config->baseUrl) }}">
                            </div>
                            @if ($isM5azn)
                                @include('admin-views.fulfillment.partials._m5azn-fields')
                            @else
                                @include('admin-views.fulfillment.partials._torod-fields')
                            @endif
                        </div>
                    </x-k.card>

                    <x-k.card :title="translate('dispatch_controls')" class="mb-3">
                        <div class="alert alert-info fs-12 py-2">{{ translate('dispatch_controls_help') }}</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input type="hidden" name="dispatch_enabled" value="0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="dispatch_enabled" name="dispatch_enabled" value="1" @checked($config->bool('dispatch_enabled'))>
                                    <label class="form-check-label" for="dispatch_enabled">{{ translate('send_orders_to_provider') }}</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input type="hidden" name="dry_run" value="0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="dry_run" name="dry_run" value="1" @checked($config->bool('dry_run', true))>
                                    <label class="form-check-label" for="dry_run">{{ translate('dry_run_mode') }}</label>
                                    <div class="fs-12 text-muted">{{ translate('dry_run_help') }}</div>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">{{ translate('dispatch_when_order_status_is') }}</label>
                                <div class="d-flex flex-wrap gap-3">
                                    @foreach ($statuses as $orderStatus)
                                        <label class="form-check">
                                            <input class="form-check-input" type="checkbox" name="dispatch_order_statuses[]" value="{{ $orderStatus }}" @checked(in_array($orderStatus, $config->list('dispatch_order_statuses'), true))>
                                            <span class="form-check-label">{{ translate($orderStatus) }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <div class="fs-12 text-muted">{{ translate('dispatch_statuses_help') }}</div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input type="hidden" name="cod_supported" value="0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="cod_supported" name="cod_supported" value="1" @checked($config->bool('cod_supported', true))>
                                    <label class="form-check-label" for="cod_supported">{{ translate('cash_on_delivery_supported') }}</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input type="hidden" name="cod_verified" value="0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="cod_verified" name="cod_verified" value="1" @checked($config->bool('cod_verified'))>
                                    <label class="form-check-label" for="cod_verified">{{ translate('cod_settlement_verified') }}</label>
                                    <div class="fs-12 text-muted">{{ translate('cod_settlement_verified_help') }}</div>
                                </div>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label" for="tracking_url_template">{{ translate('tracking_url_template') }}</label>
                                <input class="form-control" type="url" id="tracking_url_template" name="tracking_url_template" value="{{ old('tracking_url_template', $config->string('tracking_url_template')) }}" placeholder="https://…/track?code={tracking}">
                                <div class="fs-12 text-muted">{{ translate('tracking_url_template_help') }}</div>
                            </div>
                        </div>
                    </x-k.card>

                    <div class="mb-3 d-flex gap-2">
                        <button class="btn btn-primary" type="submit">{{ translate('save_settings') }}</button>
                    </div>
                </form>

                @if (!$isM5azn)
                    <x-k.card :title="translate('webhooks')" class="mb-3">
                        <p class="fs-12 text-muted">{{ translate('torod_webhooks_help') }}</p>
                        @if ($revealedSecret)
                            <div class="alert alert-success fs-12">{{ translate('webhook_secret_shown_once') }}</div>
                        @endif
                        @forelse ($webhookUrls as $type => $url)
                            <div class="mb-2">
                                <label class="form-label fs-12">{{ translate('webhook_' . $type) }}</label>
                                <input class="form-control form-control-sm" type="text" readonly value="{{ $url }}" onfocus="this.select()">
                            </div>
                        @empty
                            <p class="text-muted fs-12">{{ translate('no_webhook_secret_yet') }}</p>
                        @endforelse
                        <form method="POST" action="{{ route('admin.fulfillment-providers.rotate-secret', $card['slug']) }}" onsubmit="return confirm('{{ translate('rotate_webhook_secret_confirm') }}')">
                            @csrf
                            <button class="btn btn-outline-primary btn-sm" type="submit">{{ translate($webhookUrls === [] ? 'generate_webhook_secret' : 'rotate_webhook_secret') }}</button>
                        </form>
                    </x-k.card>
                @endif

                <x-k.card :title="translate('field_maps')" class="mb-3">
                    <p class="fs-12 text-muted">{{ translate('field_maps_help') }}</p>
                    @foreach ($maps as $capability => $map)
                        @include('admin-views.fulfillment.partials._field-map', ['capability' => $capability, 'map' => $map])
                    @endforeach
                </x-k.card>

                <x-k.card :title="translate('recent_api_calls')" :padded="false">
                    <div class="k-table-wrap">
                        <table class="k-table">
                            <thead><tr><th>{{ translate('time') }}</th><th>{{ translate('operation') }}</th><th>{{ translate('http') }}</th><th>{{ translate('result') }}</th><th>{{ translate('duration') }}</th></tr></thead>
                            <tbody>
                            @forelse ($recentLogs as $log)
                                <tr>
                                    <td class="fs-12">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                                    <td class="fs-12">{{ $log->operation }}</td>
                                    <td>{{ $log->response_status ?? '—' }}</td>
                                    <td><x-k.badge :tone="$log->success ? 'success' : 'danger'">{{ $log->success ? translate('ok') : ($log->error_class ?? translate('failed')) }}</x-k.badge>
                                        @if ($log->error_message)<div class="fs-10 text-muted text-break">{{ $log->error_message }}</div>@endif</td>
                                    <td class="fs-12">{{ $log->duration_ms }} ms</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-3">{{ translate('no_api_calls_yet') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-k.card>
            </div>

            <div class="col-xl-4">
                <x-k.card :title="translate('actions')" class="mb-3">
                    <div class="d-grid gap-2">
                        <form method="POST" action="{{ route('admin.fulfillment-providers.test', $card['slug']) }}">@csrf
                            <button class="btn btn-outline-primary w-100" type="submit">{{ translate('test_connection') }}</button>
                        </form>
                        <form method="POST" action="{{ route('admin.fulfillment-providers.sync', [$card['slug'], 'products']) }}">@csrf
                            <button class="btn btn-outline-secondary w-100" type="submit">{{ translate($isM5azn ? 'sync_products_now' : 'sync_warehouse_products_now') }}</button>
                        </form>
                        <form method="POST" action="{{ route('admin.fulfillment-providers.sync', [$card['slug'], 'inventory']) }}">@csrf
                            <button class="btn btn-outline-secondary w-100" type="submit">{{ translate('sync_stock_now') }}</button>
                        </form>
                        @unless ($isM5azn)
                            <form method="POST" action="{{ route('admin.fulfillment-providers.sync', [$card['slug'], 'cities']) }}">@csrf
                                <button class="btn btn-outline-secondary w-100" type="submit">{{ translate('sync_cities_now') }}</button>
                            </form>
                        @endunless
                    </div>
                </x-k.card>

                <x-k.card :title="translate('capabilities')" class="mb-3" :padded="false">
                    <div class="k-table-wrap">
                        <table class="k-table">
                            <tbody>
                            @foreach ($card['readiness']->capabilities as $capability)
                                <tr>
                                    <td>{{ translate('capability_' . $capability->key) }}
                                        @if ($capability->reason)<div class="fs-10 text-muted">{{ translate($capability->reason) }}</div>@endif</td>
                                    <td>@include('admin-views.fulfillment.partials._capability-badge', ['capability' => $capability])</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-k.card>

                @if ($card['readiness']->missing !== [])
                    <div class="alert alert-warning fs-12">
                        <strong>{{ translate('missing_configuration') }}:</strong> {{ collect($card['readiness']->missing)->map(fn ($key) => translate('setting_' . $key))->implode(', ') }}
                    </div>
                @endif

                <x-k.card :title="translate('not_provided_by_the_official_documentation')" class="mb-3">
                    <ul class="fs-12 ps-3 mb-0">
                        @foreach (collect($card['documented'])->filter(fn ($documented) => $documented === false)->keys() as $gap)
                            <li>{{ translate('doc_gap_' . $gap) }}</li>
                        @endforeach
                    </ul>
                </x-k.card>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script>
        (function () {
            document.querySelectorAll('[data-field-map-sample]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var panel = document.getElementById(button.dataset.target);
                    panel.textContent = button.dataset.loading;
                    fetch(button.dataset.url, {method: 'POST', headers: {'X-CSRF-TOKEN': button.dataset.token, 'Accept': 'application/json'}})
                        .then(function (response) { return response.json(); })
                        .then(function (data) {
                            if (!data.ok) { panel.textContent = data.error || 'error'; return; }
                            panel.textContent = JSON.stringify(data.items, null, 2);
                            var form = button.closest('[data-field-map]');
                            var proposal = data.proposal || {};
                            form.querySelectorAll('[data-proposal]').forEach(function (hint) {
                                var logical = hint.dataset.proposal;
                                hint.textContent = proposal[logical] ? '→ ' + proposal[logical] : '';
                                hint.dataset.value = proposal[logical] || '';
                            });
                        })
                        .catch(function () { panel.textContent = 'error'; });
                });
            });
            document.querySelectorAll('[data-apply-proposal]').forEach(function (button) {
                button.addEventListener('click', function () {
                    button.closest('[data-field-map]').querySelectorAll('[data-proposal]').forEach(function (hint) {
                        var input = button.closest('[data-field-map]').querySelector('input[name="paths[' + hint.dataset.proposal + ']"]');
                        if (input && hint.dataset.value) { input.value = hint.dataset.value; }
                    });
                });
            });
        })();
    </script>
@endpush
