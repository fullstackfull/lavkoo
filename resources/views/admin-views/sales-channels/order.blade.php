@extends('layouts.admin.app')

@section('title', translate('order') . ' ' . $mapping->external_order_id)

@section('content')
    @use('App\Enums\Fulfillment\ProviderCode')
    @php
        $mode = $mapping->fulfillmentMode();
        $currency = $mapping->currency;
        $trackingHref = $mapping->tracking_url && preg_match('#^https?://#i', $mapping->tracking_url) === 1 ? $mapping->tracking_url : null;
        $canLink = $mapping->shipment_link_state !== 'linked' && $mapping->order_id === null && !$mode->isFulfilledByChannel() && $mode->value !== 'unknown';
        $canNotify = $mapping->shipment_link_state === 'linked' && filled($mapping->tracking_number);
        $linkBag = $errors->getBag('link_shipment');
        $unlinkBag = $errors->getBag('unlink_shipment');
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="translate('order') . ' ' . $mapping->external_order_id" :subtitle="($account?->name ?? '') . ($mapping->external_package_id !== '' ? ' · ' . translate('package') . ' ' . $mapping->external_package_id : '')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.orders.index') }}">{{ translate('Unified_Orders') }}</a>
                @if ($account)<a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.accounts.show', $account) }}">{{ translate('open_account') }}</a>@endif
            </x-slot:actions>
        </x-k.page-header>

        <div class="row g-3">
            <div class="col-lg-8">
                <x-k.card :title="translate('order_facts')" class="mb-3">
                    <dl class="row mb-0 fs-12">
                        <dt class="col-sm-4">{{ translate('status') }}</dt>
                        <dd class="col-sm-8">@include('admin-views.sales-channels.partials._order-status', ['status' => $mapping->orderStatus()]) <span class="text-muted">{{ $mapping->external_status }}</span></dd>

                        <dt class="col-sm-4">{{ translate('fulfillment_mode') }}</dt>
                        <dd class="col-sm-8">@include('admin-views.sales-channels.partials._fulfilment-mode', ['mode' => $mode])
                            @if ($mapping->fulfillment_channel_raw)<span class="text-muted">· {{ $mapping->fulfillment_channel_raw }}</span>@endif
                            @if ($mode->value === 'unknown')<div class="text-warning">{{ $labels->orderReason('unknown_fulfillment_mode') }}</div>@endif</dd>

                        <dt class="col-sm-4">{{ translate('handling') }}</dt>
                        <dd class="col-sm-8">{{ $labels->executionPolicy($mapping->execution_policy) }}
                            @if ($mapping->review_reason)<div><x-k.badge tone="warning" :dot="false">{{ $labels->orderReason($mapping->review_reason) }}</x-k.badge></div>@endif</dd>

                        <dt class="col-sm-4">{{ translate('cash_on_delivery') }}</dt>
                        <dd class="col-sm-8">@if ($cod === null)@include('admin-views.sales-channels.partials._missing', ['label' => translate('unknown_value')])@else{{ $cod ? translate('yes') : translate('no') }}@endif</dd>

                        <dt class="col-sm-4">{{ translate('destination') }}</dt>
                        <dd class="col-sm-8">{{ collect([$destination['city'] ?? null, $destination['country'] ?? null])->filter()->implode(', ') ?: '—' }}
                            <span class="text-muted">· {{ translate('no_buyer_personal_data_is_stored') }}</span></dd>

                        <dt class="col-sm-4">{{ translate('purchased') }}</dt><dd class="col-sm-8">{{ $mapping->purchased_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                        <dt class="col-sm-4">{{ translate('last_changed_at_the_channel') }}</dt><dd class="col-sm-8">{{ $mapping->external_updated_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                        <dt class="col-sm-4">{{ translate('shipped') }}</dt><dd class="col-sm-8">{{ $mapping->shipped_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                        <dt class="col-sm-4">{{ translate('delivered') }}</dt><dd class="col-sm-8">{{ $mapping->delivered_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                        <dt class="col-sm-4">{{ translate('cancelled') }}</dt><dd class="col-sm-8">{{ $mapping->cancelled_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                        <dt class="col-sm-4">{{ translate('last_synced') }}</dt><dd class="col-sm-8">{{ $mapping->last_synced_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                        @if ($mapping->order_id !== null)
                            <dt class="col-sm-4">{{ translate('internal_order') }}</dt><dd class="col-sm-8">#{{ $mapping->order_id }}</dd>
                        @endif
                    </dl>
                </x-k.card>

                <x-k.card :title="translate('order_lines')" :padded="false" class="mb-3">
                    <div class="k-table-wrap table-responsive">
                        <table class="k-table">
                            <thead><tr>
                                <th>{{ translate('item') }}</th><th>{{ translate('our_product') }}</th><th>{{ translate('quantity') }}</th><th>{{ translate('unit_price') }}</th><th>{{ translate('line_total') }}</th><th>{{ translate('provider') }}</th>
                            </tr></thead>
                            <tbody>
                            @forelse ($mapping->lines as $line)
                                @php($product = $line->product_id === null ? null : $products->get($line->product_id))
                                <tr>
                                    <td>
                                        <div class="text-break">{{ $line->title ?: $line->external_line_id }}</div>
                                        <div class="fs-12 text-muted">{{ translate('sku') }}: {{ $line->sku ?? '—' }} · {{ translate('barcode') }}: {{ $line->barcode ?? '—' }} · {{ translate('line') }} {{ $line->external_line_id }}</div>
                                    </td>
                                    <td class="fs-12">
                                        @if ($product)
                                            {{ $product->name }}<div class="text-muted">#{{ $product->id }} · {{ $product->code ?? '—' }}</div>
                                        @elseif ($line->product_id !== null)#{{ $line->product_id }}
                                        @else
                                            @include('admin-views.sales-channels.partials._missing', ['label' => translate('not_linked')])
                                            <div><a href="{{ route('admin.sales-channels.products.index', ['account' => $mapping->account_id, 'q' => $line->sku ?: $line->barcode]) }}">{{ translate('find_the_listing') }}</a></div>
                                        @endif
                                    </td>
                                    <td class="fs-12 k-table__num">
                                        {{ $line->quantity }}
                                        @if ($line->quantity_cancelled > 0)<div class="text-danger">{{ translate('cancelled') }}: {{ $line->quantity_cancelled }}</div>@endif
                                        @if ($line->quantity_returned > 0)<div class="text-warning">{{ translate('returned') }}: {{ $line->quantity_returned }}</div>@endif
                                    </td>
                                    <td class="k-table__num">@include('admin-views.sales-channels.partials._money', ['amount' => $line->unit_price, 'currency' => $currency])</td>
                                    <td class="k-table__num">@include('admin-views.sales-channels.partials._money', ['amount' => $line->line_total, 'currency' => $currency])</td>
                                    <td class="fs-12">
                                        {{ $line->provider ? (ProviderCode::tryFrom($line->provider)?->label() ?? $line->provider) : '—' }}
                                        @if ($line->inventory_location_id)<div class="text-muted">{{ $locations->get($line->inventory_location_id) ?? '#' . $line->inventory_location_id }}</div>@endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-3">{{ translate('no_lines_recorded') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-k.card>

                <x-k.card :title="translate('routing_decisions')" class="mb-3">
                    <x-slot:actions>
                        <form method="POST" action="{{ route('admin.sales-channels.orders.route', $mapping) }}">@csrf
                            <button class="btn btn-outline-primary btn-sm" type="submit">{{ translate('decide_again_now') }}</button>
                        </form>
                    </x-slot:actions>
                    <p class="fs-12 text-muted">{{ translate('decide_again_help') }}</p>
                    @forelse ($decisions as $decision)
                        @include('admin-views.sales-channels.partials._decision')
                    @empty
                        <p class="text-muted mb-0">{{ translate('nothing_has_been_decided_for_this_order') }}</p>
                    @endforelse
                </x-k.card>

                @if ($showDispatch)
                    <x-k.card :title="translate('dispatch_card_title')" class="mb-3">
                        <p class="fs-12 text-muted">{{ translate('dispatch_card_help') }}</p>
                        @if ($dispatch)
                            <dl class="row mb-2">
                                <dt class="col-sm-4">{{ translate('dispatch_state') }}</dt>
                                <dd class="col-sm-8"><x-k.badge :tone="$dispatch->state === 'accepted' ? 'success' : (in_array($dispatch->state, ['blocked', 'failed'], true) ? 'warning' : 'neutral')" :dot="false">{{ $labels->dispatchState($dispatch->state) }}</x-k.badge>
                                    @if ($dispatch->reason_code)<div class="fs-12 text-muted">{{ $labels->dispatchReason($dispatch->reason_code) }}</div>@endif</dd>
                                <dt class="col-sm-4">{{ translate('dispatch_attempts') }}</dt><dd class="col-sm-8">{{ $dispatch->attempts }}</dd>
                                @if ($dispatch->next_attempt_at)<dt class="col-sm-4">{{ translate('dispatch_next_attempt') }}</dt><dd class="col-sm-8">{{ $dispatch->next_attempt_at->format('Y-m-d H:i') }}</dd>@endif
                                @if ($dispatch->buyer_data_purged_at)<dt class="col-sm-4">{{ translate('dispatch_buyer_data_removed') }}</dt><dd class="col-sm-8">{{ $dispatch->buyer_data_purged_at->format('Y-m-d H:i') }}</dd>@endif
                            </dl>
                        @else
                            <p class="text-muted">{{ translate('dispatch_not_started') }}</p>
                        @endif
                        @if ($mapping->order_id === null)
                            @if ($dispatchBlockers !== [])
                                <div class="fs-12 text-muted mb-1">{{ translate('dispatch_blocked_by') }}</div>
                                <ul class="fs-12 mb-0">@foreach ($dispatchBlockers as $blocker)<li>{{ $labels->dispatchReason($blocker) }}</li>@endforeach</ul>
                            @else
                                <form method="POST" action="{{ route('admin.sales-channels.orders.dispatch', $mapping) }}" onsubmit="return confirm(@js(translate('dispatch_now_confirm')))">@csrf
                                    <button class="btn btn-primary btn-sm" type="submit">{{ translate('dispatch_now') }}</button>
                                </form>
                            @endif
                        @endif
                    </x-k.card>
                @endif
            </div>

            <div class="col-lg-4">
                <x-k.card :title="translate('money')" class="mb-3">
                    <dl class="row mb-0 fs-12">
                        <dt class="col-6">{{ translate('items_total') }}</dt><dd class="col-6">@include('admin-views.sales-channels.partials._money', ['amount' => $mapping->items_total, 'currency' => $currency])</dd>
                        <dt class="col-6">{{ translate('discount') }}</dt><dd class="col-6">@include('admin-views.sales-channels.partials._money', ['amount' => $mapping->discount_total, 'currency' => $currency])</dd>
                        <dt class="col-6">{{ translate('shipping') }}</dt><dd class="col-6">@include('admin-views.sales-channels.partials._money', ['amount' => $mapping->shipping_total, 'currency' => $currency])</dd>
                        <dt class="col-6">{{ translate('tax') }}</dt><dd class="col-6">@include('admin-views.sales-channels.partials._money', ['amount' => $mapping->tax_total, 'currency' => $currency])</dd>
                        <dt class="col-6">{{ translate('total') }}</dt><dd class="col-6 fw-semibold">@include('admin-views.sales-channels.partials._money', ['amount' => $mapping->order_total, 'currency' => $currency])</dd>
                        <dt class="col-6">{{ translate('prices_include_tax') }}</dt>
                        <dd class="col-6">@if ($mapping->prices_include_tax === null)@include('admin-views.sales-channels.partials._missing', ['label' => translate('unknown_value')])@else{{ $mapping->prices_include_tax ? translate('yes') : translate('no') }}@endif</dd>
                    </dl>
                </x-k.card>

                <x-k.card :title="translate('shipment')" class="mb-3">
                    <dl class="row mb-2 fs-12">
                        <dt class="col-5">{{ translate('shipment_link') }}</dt>
                        <dd class="col-7"><x-k.badge :tone="$mapping->shipment_link_state === 'linked' ? 'success' : (in_array($mapping->shipment_link_state, ['needs_review', 'conflict'], true) ? 'warning' : 'neutral')">
                            {{ $mapping->shipment_link_state === null ? translate('not_decided_yet') : $labels->shipmentLinkState($mapping->shipment_link_state) }}</x-k.badge></dd>
                        <dt class="col-5">{{ translate('provider') }}</dt>
                        <dd class="col-7">{{ $mapping->shipment_link_state === 'linked' && $mapping->provider ? (ProviderCode::tryFrom($mapping->provider)?->label() ?? $mapping->provider) : '—' }}</dd>
                        <dt class="col-5">{{ translate('shipment_reference') }}</dt><dd class="col-7 text-break">{{ $mapping->shipment_reference ?? '—' }}</dd>
                        <dt class="col-5">{{ translate('tracking_number') }}</dt>
                        <dd class="col-7 text-break">@if ($trackingHref)<a href="{{ $trackingHref }}" target="_blank" rel="noopener noreferrer">{{ $mapping->tracking_number ?? $trackingHref }}</a>@else{{ $mapping->tracking_number ?? '—' }}@endif</dd>
                        <dt class="col-5">{{ translate('carrier') }}</dt><dd class="col-7">{{ $mapping->carrier ?? '—' }}</dd>
                        <dt class="col-5">{{ translate('shipment_status') }}</dt><dd class="col-7">{{ $mapping->shipment_status ?? '—' }}</dd>
                        <dt class="col-5">{{ translate('checked') }}</dt><dd class="col-7">{{ $mapping->shipment_checked_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                        <dt class="col-5">{{ translate('channel_told') }}</dt>
                        <dd class="col-7">
                            @if (! empty($notified['tracking']))<x-k.badge tone="success" :dot="false">{{ translate('yes') }}</x-k.badge> {{ $notified['tracking'] }}
                            @else<x-k.badge :dot="false">{{ translate('not_yet') }}</x-k.badge>@endif
                        </dd>
                    </dl>
                    @if (! empty($notifyError['error']))<div class="alert alert-danger fs-12 py-2 text-break" role="alert">{{ $labels->channelReason('channel_notify_error') }}: {{ $notifyError['error'] }}</div>@endif

                    @if ($canUnlink)
                        <form method="POST" action="{{ route('admin.sales-channels.orders.unlink-shipment', $mapping) }}" class="d-flex flex-column gap-1 mb-3" onsubmit="return confirm(@js(translate('unlink_shipment_confirm')))">@csrf
                            <label class="form-label fs-12 mb-0" for="unlink-reason">{{ translate('reason') }}</label>
                            <textarea class="form-control form-control-sm" id="unlink-reason" name="reason" rows="2" minlength="5" maxlength="255" required>{{ $unlinkBag->any() ? old('reason') : '' }}</textarea>
                            @if ($unlinkBag->has('reason'))<div class="text-danger fs-12" role="alert">{{ $unlinkBag->first('reason') }}</div>@endif
                            <div><button class="btn btn-outline-danger btn-sm" type="submit">{{ translate('unlink_shipment') }}</button></div>
                        </form>
                    @endif

                    @if ($canLink)
                        <form method="POST" action="{{ route('admin.sales-channels.orders.link-shipment', $mapping) }}" class="d-flex flex-column gap-2 mb-3">@csrf
                            <p class="fs-12 text-muted mb-0">{{ translate('link_shipment_help') }}</p>
                            <div>
                                <label class="form-label fs-12 mb-0" for="link-provider">{{ translate('provider') }}</label>
                                <select class="form-select form-select-sm" id="link-provider" name="provider" required>
                                    @foreach ($providers as $provider)
                                        <option value="{{ $provider->value }}" @selected(old('provider') === $provider->value)>{{ $provider->label() }}</option>
                                    @endforeach
                                </select>
                                @if ($linkBag->has('provider'))<div class="text-danger fs-12" role="alert">{{ $linkBag->first('provider') }}</div>@endif
                            </div>
                            <div>
                                <label class="form-label fs-12 mb-0" for="link-reference">{{ translate('shipment_reference') }}</label>
                                <input class="form-control form-control-sm" id="link-reference" name="reference" maxlength="120" required value="{{ $linkBag->any() ? old('reference') : '' }}">
                                @if ($linkBag->has('reference'))<div class="text-danger fs-12" role="alert">{{ $linkBag->first('reference') }}</div>@endif
                            </div>
                            <div>
                                <label class="form-label fs-12 mb-0" for="link-tracking">{{ translate('tracking_number_optional') }}</label>
                                <input class="form-control form-control-sm" id="link-tracking" name="tracking_number" maxlength="120" value="{{ $linkBag->any() ? old('tracking_number') : '' }}">
                            </div>
                            <div>
                                <label class="form-label fs-12 mb-0" for="link-carrier">{{ translate('carrier_optional') }}</label>
                                <input class="form-control form-control-sm" id="link-carrier" name="carrier" maxlength="120" value="{{ $linkBag->any() ? old('carrier') : '' }}">
                            </div>
                            <div><button class="btn btn-primary btn-sm" type="submit">{{ translate('link_shipment') }}</button></div>
                        </form>
                    @endif

                    @unless ($canLink || $canUnlink)
                        <div class="alert alert-secondary fs-12 py-2" role="note">
                            @if ($mapping->order_id !== null){{ translate('shipment_link_refused_application_fulfils') }}
                            @elseif ($mode->isFulfilledByChannel()){{ translate('shipment_link_refused_channel_ships') }}
                            @else{{ translate('shipment_link_refused_mode_unknown') }}@endif
                        </div>
                    @endunless

                    @if ($canNotify)
                        <form method="POST" action="{{ route('admin.sales-channels.orders.notify', $mapping) }}" class="d-flex flex-column gap-1">@csrf
                            @if ($writeState->sendsForReal())
                                <label class="form-check mb-0 fs-12">
                                    <input class="form-check-input" type="checkbox" name="confirm_live" value="1">
                                    <span class="form-check-label">{{ translate('confirm_that_this_is_sent_for_real') }}</span>
                                </label>
                                @if ($errors->getBag('notify')->has('confirm_live'))<div class="text-danger fs-12" role="alert">{{ $errors->getBag('notify')->first('confirm_live') }}</div>@endif
                            @elseif ($writeState->isRehearsal())
                                <div class="fs-12 text-muted">{{ translate('rehearsal_only_nothing_is_sent') }}</div>
                            @endif
                            <div><button class="btn btn-outline-primary btn-sm" type="submit">{{ translate('tell_the_channel_about_this_shipment') }}</button></div>
                        </form>
                    @endif
                </x-k.card>
            </div>
        </div>
    </div>
@endsection
