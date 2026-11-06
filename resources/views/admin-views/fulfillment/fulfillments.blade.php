@extends('layouts.admin.app')

@section('title', translate('provider_fulfillments'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('provider_fulfillments')" :subtitle="translate('provider_fulfillments_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.fulfillment-providers.index') }}">{{ translate('Fulfillment_Providers') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.fulfillment-providers.logs') }}">{{ translate('provider_api_logs') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        <x-k.card class="mb-3">
            <form method="GET" action="{{ route('admin.fulfillment-providers.fulfillments.index') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fs-12" for="filter-q">{{ translate('search') }}</label>
                    <input class="form-control form-control-sm" id="filter-q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ translate('reference_order_or_tracking') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-provider">{{ translate('provider') }}</label>
                    <select class="form-select form-select-sm" id="filter-provider" name="provider">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($providers as $provider)
                            <option value="{{ $provider->value }}" @selected(($filters['provider'] ?? '') === $provider->value)>{{ config("fulfillment.providers.{$provider->value}.label") }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fs-12" for="filter-status">{{ translate('status') }}</label>
                    <select class="form-select form-select-sm" id="filter-status" name="status">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ translate($status->labelKey()) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="filter-attention" name="attention" value="1" @checked(!empty($filters['attention']))>
                        <label class="form-check-label fs-12" for="filter-attention">{{ translate('needs_attention_only') }}</label>
                    </div>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-primary btn-sm" type="submit">{{ translate('filter') }}</button>
                    <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.fulfillment-providers.fulfillments.index') }}">{{ translate('reset') }}</a>
                </div>
            </form>
        </x-k.card>

        <x-k.card :padded="false">
            <div class="k-table-wrap">
                <table class="k-table">
                    <thead><tr>
                        <th>{{ translate('reference') }}</th><th>{{ translate('order') }}</th><th>{{ translate('provider') }}</th><th>{{ translate('status') }}</th>
                        <th>{{ translate('items') }}</th><th>{{ translate('payment') }}</th><th>{{ translate('tracking_number') }}</th><th>{{ translate('updated') }}</th><th></th>
                    </tr></thead>
                    <tbody>
                    @forelse ($fulfillments as $fulfillment)
                        <tr>
                            <td><a href="{{ route('admin.fulfillment-providers.fulfillments.show', $fulfillment->id) }}">{{ $fulfillment->reference }}</a></td>
                            <td><a href="{{ route('admin.orders.details', ['id' => $fulfillment->order_id]) }}">#{{ $fulfillment->order_id }}</a></td>
                            <td>{{ config("fulfillment.providers.{$fulfillment->provider}.label", $fulfillment->provider) }}</td>
                            <td>
                                @include('admin-views.fulfillment.partials._status-badge', ['status' => $fulfillment->status])
                                @if ($fulfillment->hold_reason && $fulfillment->status === \App\Enums\Fulfillment\FulfillmentStatus::Held)
                                    <div class="fs-10 text-muted">{{ translate($fulfillment->hold_reason) }}</div>
                                @endif
                                @if ($fulfillment->last_error_message && in_array($fulfillment->status, [\App\Enums\Fulfillment\FulfillmentStatus::Failed, \App\Enums\Fulfillment\FulfillmentStatus::RetryWait, \App\Enums\Fulfillment\FulfillmentStatus::NeedsAttention], true))
                                    <div class="fs-10 text-danger">{{ \Illuminate\Support\Str::limit($fulfillment->last_error_message, 80) }}</div>
                                @endif
                            </td>
                            <td>{{ $fulfillment->items->sum('quantity') }}</td>
                            <td>{{ translate('payment_mode_' . $fulfillment->payment_mode->value) }}
                                @if ($fulfillment->payment_mode === \App\Enums\Fulfillment\PaymentMode::CashOnDelivery)<div class="fs-10 text-muted">{{ number_format((float) $fulfillment->cod_amount, 2) }} {{ $fulfillment->currency }}</div>@endif</td>
                            <td>{{ $fulfillment->tracking_number ?? '—' }}</td>
                            <td class="fs-12">{{ $fulfillment->updated_at?->format('Y-m-d H:i') }}</td>
                            <td><a class="btn btn-outline-primary btn-sm" href="{{ route('admin.fulfillment-providers.fulfillments.show', $fulfillment->id) }}">{{ translate('view') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="9"><x-k.empty icon="box" :title="translate('no_provider_fulfillments')" :text="translate('no_provider_fulfillments_help')" /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($fulfillments->hasPages())
                <x-slot:footer>{{ $fulfillments->links() }}</x-slot:footer>
            @endif
        </x-k.card>
    </div>
@endsection
