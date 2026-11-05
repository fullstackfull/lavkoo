@extends('layouts.admin.app')

@section('title', $fulfillment->reference)

@section('content')
    @use('App\Enums\Fulfillment\FulfillmentStatus')
    @php
        $status = $fulfillment->status;
        $accepted = $status->isAcceptedByProvider();
        $open = !$status->isTerminal();
        $action = static fn (string $name): string => route('admin.fulfillment-providers.fulfillments.action', ['id' => $fulfillment->id, 'action' => $name]);
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="$fulfillment->reference" :subtitle="config('fulfillment.providers.' . $fulfillment->provider . '.label', $fulfillment->provider)">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.fulfillment-providers.fulfillments.index') }}">{{ translate('back') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.orders.details', ['id' => $fulfillment->order_id]) }}">{{ translate('View_Order_Details') }} #{{ $fulfillment->order_id }}</a>
                @if ($canLabel)
                    <form method="POST" action="{{ route('admin.fulfillment-providers.fulfillments.label', $fulfillment->id) }}" class="d-inline">@csrf
                        <button class="btn btn-outline-primary btn-sm" type="submit">{{ translate('download_awb') }}</button></form>
                @endif
            </x-slot:actions>
        </x-k.page-header>

        <div class="row g-3">
            <div class="col-lg-8">
                @if ($fulfillment->isCancelBlocked())
                    <div class="alert alert-danger fs-12" role="alert">
                        <strong>{{ translate('cancel_blocked_title') }}</strong>
                        <div>{{ translate('cancel_blocked_help') }} {{ $fulfillment->cancel_blocked_reason }}</div>
                    </div>
                @elseif ($fulfillment->isCancelRequested())
                    <div class="alert alert-warning fs-12" role="alert">{{ translate('cancel_requested_note') }}</div>
                @elseif ($fulfillment->cancel_intent_at && $open)
                    <div class="alert alert-info fs-12" role="alert">{{ translate('cancel_wanted_note') }}
                        @if ($fulfillment->cancel_attempts > 0){{ translate('cancel_retrying_note') }}@endif</div>
                @endif

                <x-k.card :title="translate('shipment')" class="mb-3">
                    <dl class="row mb-0 fs-12">
                        <dt class="col-sm-4">{{ translate('status') }}</dt>
                        <dd class="col-sm-8">@include('admin-views.fulfillment.partials._status-badge', ['status' => $status])
                            @if ($fulfillment->hold_reason && $status === FulfillmentStatus::Held)<span class="text-muted">· {{ translate($fulfillment->hold_reason) }}</span>@endif</dd>

                        <dt class="col-sm-4">{{ translate('provider_status') }}</dt>
                        <dd class="col-sm-8">{{ $fulfillment->provider_status ?? '—' }}</dd>

                        @if ($fulfillment->warehouse_status)
                            <dt class="col-sm-4">{{ translate('warehouse_status') }}</dt>
                            <dd class="col-sm-8">{{ $fulfillment->warehouse_status }} <span class="text-muted">({{ translate('warehouse_status_display_only') }})</span></dd>
                        @endif

                        <dt class="col-sm-4">{{ translate('provider_reference') }}</dt>
                        <dd class="col-sm-8">{{ $fulfillment->provider_reference ?? '—' }}
                            @if ($fulfillment->provider_internal_id)<span class="text-muted">· {{ $fulfillment->provider_internal_id }}</span>@endif
                            @if ($fulfillment->provider_secondary_reference)<span class="text-muted">· {{ $fulfillment->provider_secondary_reference }}</span>@endif</dd>

                        <dt class="col-sm-4">{{ translate('tracking_number') }}</dt>
                        <dd class="col-sm-8">
                            @if ($fulfillment->tracking_url)<a href="{{ $fulfillment->tracking_url }}" target="_blank" rel="noopener noreferrer">{{ $fulfillment->tracking_number ?? $fulfillment->tracking_url }}</a>
                            @else{{ $fulfillment->tracking_number ?? '—' }}@endif</dd>

                        <dt class="col-sm-4">{{ translate('payment') }}</dt>
                        <dd class="col-sm-8">{{ translate('payment_mode_' . $fulfillment->payment_mode->value) }}
                            @if ($fulfillment->payment_mode === \App\Enums\Fulfillment\PaymentMode::CashOnDelivery)· {{ translate('cash_to_collect') }}: <strong>{{ number_format((float) $fulfillment->cod_amount, 2) }} {{ $fulfillment->currency }}</strong>@endif</dd>

                        <dt class="col-sm-4">{{ translate('items_total') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $fulfillment->items_total, 2) }} {{ $fulfillment->currency }}
                            · {{ translate('shipping') }} {{ number_format((float) $fulfillment->shipping_allocation, 2) }}
                            · {{ translate('total') }} {{ number_format((float) $fulfillment->total_amount, 2) }}</dd>

                        @if ($fulfillment->provider_cost !== null)
                            <dt class="col-sm-4">{{ translate('provider_charge') }}</dt>
                            <dd class="col-sm-8">{{ number_format((float) $fulfillment->provider_cost, 2) }} {{ $fulfillment->currency }}</dd>
                        @endif

                        <dt class="col-sm-4">{{ translate('attempts') }}</dt>
                        <dd class="col-sm-8">{{ $fulfillment->attempts }} @if ($fulfillment->reconcile_attempts)· {{ translate('reconcile_attempts') }}: {{ $fulfillment->reconcile_attempts }}@endif
                            @if ($fulfillment->next_attempt_at && $status === FulfillmentStatus::RetryWait)· {{ translate('next_attempt') }}: {{ $fulfillment->next_attempt_at->format('Y-m-d H:i') }}@endif</dd>

                        @if ($fulfillment->last_error_message)
                            <dt class="col-sm-4">{{ translate('last_error') }}</dt>
                            <dd class="col-sm-8 text-danger">{{ $fulfillment->last_error_code }} {{ $fulfillment->last_error_message }}</dd>
                        @endif

                        <dt class="col-sm-4">{{ translate('timeline') }}</dt>
                        <dd class="col-sm-8">
                            @foreach (['submitted_at', 'shipped_at', 'delivered_at', 'cancelled_at', 'returned_at', 'failed_at'] as $moment)
                                @if ($fulfillment->{$moment})<div>{{ translate('fulfillment_' . $moment) }}: {{ $fulfillment->{$moment}->format('Y-m-d H:i') }}</div>@endif
                            @endforeach
                            <div>{{ translate('created') }}: {{ $fulfillment->created_at?->format('Y-m-d H:i') }}</div>
                        </dd>
                    </dl>
                </x-k.card>

                <x-k.card :title="translate('items')" :padded="false" class="mb-3">
                    <div class="k-table-wrap">
                        <table class="k-table">
                            <thead><tr><th>{{ translate('product') }}</th><th>{{ translate('sku') }}</th><th>{{ translate('provider_product_id') }}</th><th>{{ translate('quantity') }}</th><th>{{ translate('line_total') }}</th></tr></thead>
                            <tbody>
                            @foreach ($fulfillment->items as $item)
                                <tr>
                                    <td>{{ $item->name }} @if ($item->variant_key !== '')<div class="fs-10 text-muted">{{ $item->variant_key }}</div>@endif</td>
                                    <td>{{ $item->external_sku ?? $item->sku ?? '—' }}</td>
                                    <td>{{ $item->external_product_id ?? '—' }}</td>
                                    <td>{{ $item->quantity }}</td>
                                    <td>{{ number_format((float) $item->line_total, 2) }} {{ $fulfillment->currency }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-k.card>

                <x-k.card :title="translate('status_history')" :padded="false">
                    <div class="k-table-wrap">
                        <table class="k-table">
                            <thead><tr><th>{{ translate('received') }}</th><th>{{ translate('source') }}</th><th>{{ translate('event') }}</th><th>{{ translate('provider_status') }}</th><th>{{ translate('result') }}</th></tr></thead>
                            <tbody>
                            @forelse ($fulfillment->events as $event)
                                <tr>
                                    <td class="fs-12">{{ ($event->received_at ?? $event->created_at)?->format('Y-m-d H:i:s') }}
                                        @if ($event->occurred_at)<div class="fs-10 text-muted">{{ translate('occurred') }} {{ $event->occurred_at->format('Y-m-d H:i:s') }}</div>@endif</td>
                                    <td>{{ $event->source }}</td>
                                    <td>{{ $event->event_type }}</td>
                                    <td>{{ $event->provider_status ?? '—' }} @if ($event->internal_status)<div class="fs-10 text-muted">→ {{ $event->internal_status }}</div>@endif</td>
                                    <td>{{ $event->outcome }} @if ($event->outcome_note)<div class="fs-10 text-muted">{{ \Illuminate\Support\Str::limit($event->outcome_note, 120) }}</div>@endif</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-3">{{ translate('no_events_yet') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-k.card>
            </div>

            <div class="col-lg-4">
                <x-k.card :title="translate('operator_actions')" class="mb-3">
                    <p class="fs-12 text-muted">{{ translate('operator_actions_help') }}</p>

                    @if ($accepted)
                        <form method="POST" action="{{ $action('refresh') }}" class="mb-2">@csrf
                            <button class="btn btn-outline-primary btn-sm w-100" type="submit">{{ translate('refresh_status_from_provider') }}</button></form>
                    @endif

                    @if (in_array($status, [FulfillmentStatus::Held, FulfillmentStatus::DryRun, FulfillmentStatus::Failed], true))
                        <form method="POST" action="{{ $action('release') }}" class="mb-2">@csrf
                            <button class="btn btn-primary btn-sm w-100" type="submit">{{ translate('release_for_dispatch') }}</button></form>
                    @endif

                    @if ($accepted && $open)
                        <form method="POST" action="{{ $action('cancel') }}" class="mb-2" onsubmit="return confirm(@js(translate('cancel_at_provider_confirmation')))">@csrf
                            <button class="btn btn-outline-danger btn-sm w-100" type="submit">{{ translate('cancel_at_provider') }}</button></form>
                    @endif

                    @if ($status === FulfillmentStatus::Cancelled)
                        <form method="POST" action="{{ $action('redispatch') }}" class="mb-2" onsubmit="return confirm(@js(translate('redispatch_confirmation')))">@csrf
                            <input class="form-control form-control-sm mb-1" name="reason" required maxlength="255" placeholder="{{ translate('confirm_original_cancelled_at_provider') }}">
                            <button class="btn btn-outline-primary btn-sm w-100" type="submit">{{ translate('redispatch_as_new_shipment') }}</button></form>
                    @endif

                    @if ($status === FulfillmentStatus::ReconcilePending)
                        <div class="alert alert-warning fs-12">{{ translate('outcome_unknown_explainer') }}</div>
                        <form method="POST" action="{{ $action('confirm-not-created') }}" class="mb-2">@csrf
                            <label class="form-label fs-12" for="not-created-reason">{{ translate('confirm_not_created_reason') }}</label>
                            <input class="form-control form-control-sm mb-1" id="not-created-reason" name="reason" required maxlength="255" placeholder="{{ translate('what_did_you_check') }}">
                            <button class="btn btn-outline-warning btn-sm w-100" type="submit">{{ translate('confirm_provider_has_no_such_order') }}</button></form>
                    @endif

                    @if (in_array($status, [FulfillmentStatus::ReconcilePending, FulfillmentStatus::Failed, FulfillmentStatus::Held, FulfillmentStatus::Pending], true))
                        <form method="POST" action="{{ $action('link') }}" class="mb-2">@csrf
                            <label class="form-label fs-12" for="link-reference">{{ translate('link_existing_provider_order') }}</label>
                            <input class="form-control form-control-sm mb-1" id="link-reference" name="reference" required maxlength="120" placeholder="{{ translate('provider_reference') }}">
                            <input class="form-control form-control-sm mb-1" name="tracking_number" maxlength="120" placeholder="{{ translate('tracking_number_optional') }}">
                            <input class="form-control form-control-sm mb-1" name="reason" required maxlength="255" placeholder="{{ translate('what_did_you_check') }}">
                            <button class="btn btn-outline-warning btn-sm w-100" type="submit">{{ translate('link_provider_order') }}</button></form>
                    @endif

                    @if ($accepted && $open)
                        @foreach (['mark-delivered' => 'mark_delivered_by_hand', 'mark-cancelled' => 'mark_cancelled_by_hand'] as $name => $label)
                            <form method="POST" action="{{ $action($name) }}" class="mb-2" onsubmit="return confirm(@js(translate('are_you_sure')))">@csrf
                                <input class="form-control form-control-sm mb-1" name="reason" required maxlength="255" placeholder="{{ translate('reason_recorded_in_audit_log') }}">
                                <button class="btn btn-outline-secondary btn-sm w-100" type="submit">{{ translate($label) }}</button></form>
                        @endforeach
                    @endif
                </x-k.card>

                <x-k.card :title="translate('recent_api_calls')" :padded="false">
                    <ul class="list-unstyled mb-0 fs-12">
                        @forelse ($logs as $log)
                            <li class="px-3 py-2 border-bottom">
                                <x-k.badge :tone="$log->success ? 'success' : 'danger'">{{ $log->response_status ?? '—' }}</x-k.badge>
                                {{ $log->operation }}
                                <div class="fs-10 text-muted">{{ $log->created_at?->format('Y-m-d H:i:s') }} · {{ $log->duration_ms }} ms @if ($log->error_message)· {{ \Illuminate\Support\Str::limit($log->error_message, 80) }}@endif</div>
                            </li>
                        @empty
                            <li class="px-3 py-3 text-muted">{{ translate('no_api_calls_yet') }}</li>
                        @endforelse
                    </ul>
                </x-k.card>
            </div>
        </div>
    </div>
@endsection
