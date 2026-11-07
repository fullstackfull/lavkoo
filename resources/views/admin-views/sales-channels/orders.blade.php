@extends('layouts.admin.app')

@section('title', translate('Unified_Orders'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('Unified_Orders')" :subtitle="translate('unified_orders_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.index') }}">{{ translate('Channels_Hub') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.routing.index') }}">{{ translate('Dynamic_Routing') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        <div class="alert alert-info fs-12" role="note">{{ translate('channel_orders_are_not_storefront_orders') }}</div>

        <x-k.card class="mb-3">
            <form method="GET" action="{{ route('admin.sales-channels.orders.index') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fs-12" for="filter-q">{{ translate('search') }}</label>
                    <input class="form-control form-control-sm" id="filter-q" name="q" value="{{ $filters['q'] }}" placeholder="{{ translate('order_package_or_tracking_number') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-channel">{{ translate('channel') }}</label>
                    <select class="form-select form-select-sm" id="filter-channel" name="channel">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($channels as $channel)
                            <option value="{{ $channel->value }}" @selected($filters['channel'] === $channel->value)>{{ translate($channel->labelKey()) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fs-12" for="filter-account">{{ translate('account') }}</label>
                    <select class="form-select form-select-sm" id="filter-account" name="account">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected($filters['account'] === $account->id)>{{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-status">{{ translate('status') }}</label>
                    <select class="form-select form-select-sm" id="filter-status" name="status">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ translate($status->labelKey()) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-mode">{{ translate('fulfillment_mode') }}</label>
                    <select class="form-select form-select-sm" id="filter-mode" name="mode">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($modes as $mode)
                            <option value="{{ $mode->value }}" @selected($filters['mode'] === $mode->value)>{{ translate($mode->labelKey()) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fs-12" for="filter-policy">{{ translate('execution_policy') }}</label>
                    <select class="form-select form-select-sm" id="filter-policy" name="policy">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($policies as $policy)
                            <option value="{{ $policy->value }}" @selected($filters['policy'] === $policy->value)>{{ translate($policy->labelKey()) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fs-12" for="filter-link">{{ translate('shipment_link') }}</label>
                    <select class="form-select form-select-sm" id="filter-link" name="link">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($linkStates as $state)
                            <option value="{{ $state }}" @selected($filters['link'] === $state)>{{ $state === 'undecided' ? translate('not_decided_yet') : $labels->shipmentLinkState($state) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-from">{{ translate('purchased_from') }}</label>
                    <input class="form-control form-control-sm" type="date" id="filter-from" name="from" value="{{ $filters['from'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-to">{{ translate('purchased_to') }}</label>
                    <input class="form-control form-control-sm" type="date" id="filter-to" name="to" value="{{ $filters['to'] }}">
                </div>
                <div class="col-md-2">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="filter-review" name="review" value="1" @checked($filters['review'])>
                        <label class="form-check-label fs-12" for="filter-review">{{ translate('needs_review_only') }}</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="filter-returns" name="returns" value="awaiting" @checked($filters['returns'])>
                        <label class="form-check-label fs-12" for="filter-returns">{{ translate('awaiting_a_restock_decision') }}</label>
                    </div>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-primary btn-sm" type="submit">{{ translate('filter') }}</button>
                    <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.orders.index') }}">{{ translate('reset') }}</a>
                </div>
            </form>
        </x-k.card>

        <x-k.card :padded="false">
            <div class="k-table-wrap table-responsive">
                <table class="k-table">
                    <thead><tr>
                        <th>{{ translate('order') }}</th><th>{{ translate('account') }}</th><th>{{ translate('purchased') }}</th><th>{{ translate('status') }}</th>
                        <th>{{ translate('fulfillment_mode') }}</th><th>{{ translate('handling') }}</th><th>{{ translate('shipment') }}</th><th>{{ translate('total') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($orders as $order)
                        <tr>
                            <td>
                                <a href="{{ route('admin.sales-channels.orders.show', $order) }}">{{ $order->external_order_id }}</a>
                                @if ($order->external_package_id !== '')<div class="fs-10 text-muted">{{ translate('package') }} {{ $order->external_package_id }}</div>@endif
                            </td>
                            <td>{{ $order->account?->name ?? '—' }}<div class="fs-10 text-muted">{{ translate(($order->account?->channelCode()?->labelKey()) ?? 'channel') }} · {{ translate('seller') }}: {{ $order->seller_id === 0 ? translate('platform') : '#' . $order->seller_id }}</div></td>
                            <td class="fs-12">{{ $order->purchased_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td>@include('admin-views.sales-channels.partials._order-status', ['status' => $order->orderStatus()])
                                @if ($order->external_status)<div class="fs-10 text-muted">{{ $order->external_status }}</div>@endif</td>
                            <td>@include('admin-views.sales-channels.partials._fulfilment-mode', ['mode' => $order->fulfillmentMode()])
                                @if (($order->snapshot['is_cod'] ?? false) === true)<x-k.badge tone="warning" :dot="false">{{ translate('cash_on_delivery') }}</x-k.badge>@endif</td>
                            <td class="fs-12">
                                {{ $labels->executionPolicy($order->execution_policy) }}
                                @if ($order->review_reason)<div><x-k.badge tone="warning" :dot="false">{{ $labels->orderReason($order->review_reason) }}</x-k.badge></div>@endif
                            </td>
                            <td class="fs-12">
                                <x-k.badge :tone="$order->shipment_link_state === 'linked' ? 'success' : (in_array($order->shipment_link_state, ['needs_review', 'conflict'], true) ? 'warning' : 'neutral')" :dot="false">
                                    {{ $order->shipment_link_state === null ? translate('not_decided_yet') : $labels->shipmentLinkState($order->shipment_link_state) }}</x-k.badge>
                                @if ($order->tracking_number)<div class="text-break">{{ $order->tracking_number }}</div>@endif
                            </td>
                            <td class="k-table__num">@include('admin-views.sales-channels.partials._money', ['amount' => $order->order_total, 'currency' => $order->currency])</td>
                        </tr>
                    @empty
                        <tr><td colspan="8">
                            @if (array_filter($filters))
                                <x-k.empty icon="search" :title="translate('no_order_matches_the_filters')" :text="translate('no_order_matches_the_filters_help')">
                                    <x-slot:action><a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.orders.index') }}">{{ translate('reset') }}</a></x-slot:action>
                                </x-k.empty>
                            @else
                                <x-k.empty icon="orders" :title="translate('no_channel_orders_yet')" :text="translate('no_channel_orders_yet_help')">
                                    <x-slot:action><a class="btn btn-primary btn-sm" href="{{ route('admin.sales-channels.index') }}">{{ translate('Channels_Hub') }}</a></x-slot:action>
                                </x-k.empty>
                            @endif
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($orders->hasPages())
                <x-slot:footer>{{ $orders->links() }}</x-slot:footer>
            @endif
        </x-k.card>
    </div>
@endsection
