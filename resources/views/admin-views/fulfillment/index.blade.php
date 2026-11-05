@extends('layouts.admin.app')

@section('title', translate('Fulfillment_Providers'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('Fulfillment_Providers')" :subtitle="translate('fulfillment_providers_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.fulfillment-providers.fulfillments.index') }}">{{ translate('all_provider_fulfillments') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.fulfillment-providers.logs') }}">{{ translate('api_logs') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        @unless ($globallyEnabled)
            <div class="alert alert-warning d-flex gap-2 align-items-start" role="alert">
                <i class="fi fi-rr-triangle-warning mt-1"></i>
                <span>{{ translate('fulfillment_integrations_disabled_by_environment') }}</span>
            </div>
        @endunless

        <div class="row g-3">
            @foreach ($cards as $card)
                <div class="col-xl-6">
                    <x-k.card :title="$card['label']">
                        <x-slot:actions>
                            <x-k.badge :tone="$card['enabled'] ? 'success' : 'neutral'">{{ $card['enabled'] ? translate('enabled') : translate('disabled') }}</x-k.badge>
                            @if (!$card['dispatchEnabled'])
                                <x-k.badge tone="neutral">{{ translate('dispatch_off') }}</x-k.badge>
                            @elseif ($card['dryRun'])
                                <x-k.badge tone="warning">{{ translate('dispatch_dry_run') }}</x-k.badge>
                            @else
                                <x-k.badge tone="success">{{ translate('dispatch_live') }}</x-k.badge>
                            @endif
                        </x-slot:actions>

                        <p class="fs-12 text-muted mb-3">{{ translate('provider_role_' . $card['role']) }}</p>

                        <div class="pair-list mb-3">
                            <div><span class="key">{{ translate('base_url') }}</span><span>:</span><span class="value text-break">{{ $card['baseUrl'] }}</span></div>
                            @if ($card['companyId'] !== '')
                                <div><span class="key">{{ translate('company_id') }}</span><span>:</span><span class="value">{{ $card['companyId'] }}</span></div>
                            @endif
                            @if ($card['goLiveAt'])
                                <div><span class="key">{{ translate('live_since') }}</span><span>:</span><span class="value">{{ $card['goLiveAt'] }}</span></div>
                            @endif
                            <div><span class="key">{{ translate('webhook') }}</span><span>:</span>
                                <span class="value">{{ $card['webhookConfigured'] ? translate('secret_configured') : translate('not_configured') }}</span></div>
                        </div>

                        <div class="d-flex flex-wrap gap-3 mb-3">
                            <a class="text-decoration-none" href="{{ route('admin.fulfillment-providers.fulfillments.index', ['provider' => $card['code']->value]) }}"><strong>{{ $card['open'] }}</strong> <span class="fs-12 text-muted">{{ translate('open') }}</span></a>
                            <a class="text-decoration-none" href="{{ route('admin.fulfillment-providers.fulfillments.index', ['provider' => $card['code']->value, 'status' => 'held']) }}"><strong class="text-warning">{{ $card['held'] }}</strong> <span class="fs-12 text-muted">{{ translate('held') }}</span></a>
                            <a class="text-decoration-none" href="{{ route('admin.fulfillment-providers.fulfillments.index', ['provider' => $card['code']->value, 'status' => 'failed']) }}"><strong class="text-danger">{{ $card['failed'] }}</strong> <span class="fs-12 text-muted">{{ translate('failed') }}</span></a>
                            <a class="text-decoration-none" href="{{ route('admin.fulfillment-providers.fulfillments.index', ['provider' => $card['code']->value, 'status' => 'reconcile_pending']) }}"><strong class="text-warning">{{ $card['unknown'] }}</strong> <span class="fs-12 text-muted">{{ translate('outcome_unknown') }}</span></a>
                        </div>

                        <div class="k-table-wrap mb-3">
                            <table class="k-table">
                                <thead><tr>
                                    <th>{{ translate('capability') }}</th><th>{{ translate('status') }}</th>
                                </tr></thead>
                                <tbody>
                                @foreach ($card['readiness']->capabilities as $capability)
                                    <tr>
                                        <td>{{ translate('capability_' . $capability->key) }}
                                            @if ($capability->reason)<div class="fs-10 text-muted">{{ translate($capability->reason) }}</div>@endif
                                        </td>
                                        <td>@include('admin-views.fulfillment.partials._capability-badge', ['capability' => $capability])</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="fs-12 mb-3">
                            <div class="fw-semibold mb-1">{{ translate('synchronisation_health') }}</div>
                            @forelse ($card['states'] as $state)
                                <div class="d-flex justify-content-between gap-2 flex-wrap border-bottom py-1">
                                    <span>{{ translate('capability_' . $state->capability) }}</span>
                                    <span class="text-muted">
                                        {{ translate('last_success') }}: {{ $state->last_success_at?->diffForHumans() ?? '—' }}
                                        @if ($state->last_received_at) · {{ translate('last_received') }}: {{ $state->last_received_at->diffForHumans() }}@endif
                                        @if ($state->last_reconciled_at) · {{ translate('last_reconciled') }}: {{ $state->last_reconciled_at->diffForHumans() }}@endif
                                    </span>
                                </div>
                            @empty
                                <span class="text-muted">{{ translate('nothing_has_run_yet') }}</span>
                            @endforelse
                        </div>

                        @if ($card['lastError'])
                            <div class="alert alert-danger fs-12 py-2 mb-3">
                                <strong>{{ translate('last_api_error') }}</strong> ({{ translate('capability_' . $card['lastError']->capability) }}, {{ $card['lastError']->last_failure_at?->diffForHumans() }}):
                                <span class="text-break">{{ $card['lastError']->last_error }}</span>
                            </div>
                        @endif

                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-primary btn-sm" href="{{ route('admin.fulfillment-providers.show', $card['slug']) }}">{{ translate('settings') }}</a>
                            @if ($card['code'] === \App\Enums\Fulfillment\ProviderCode::M5azn)
                                <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.fulfillment-providers.m5azn.products') }}">{{ translate('imported_products') }}</a>
                            @else
                                <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.fulfillment-providers.torod.matching') }}">{{ translate('product_matching') }}</a>
                            @endif
                        </div>
                    </x-k.card>
                </div>
            @endforeach
        </div>
    </div>
@endsection
