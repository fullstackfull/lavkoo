@extends('layouts.seller.app')

@section('title', '#' . $order->id)

@php
    use App\Services\SellerCenter\Status;

    $isCod = ($order->payment_method ?? '') === 'cash_on_delivery';
    $currency = $breakdown['currency'] ?? 'SYP';
    $cancelled = in_array($order->order_status, ['canceled', 'failed'], true);

    $slaText = \App\Services\SellerCenter\Copy::sla($sla);

    /* Which timeline events are the system's own work, so an automatic step never looks like
       something the seller did (handoff 06 §7). */
    $automatic = ['auto_resolved', 'reservation_released', 'webhook', 'sla_entered_risk', 'sla_breached'];
@endphp

@section('content')
    <x-sc.page-header :title="'#' . $order->id"
                      :back="route('seller.orders.index')"
                      :crumbs="[
                          ['label' => translate('nav_orders')],
                          ['label' => translate('nav_all_orders'), 'href' => route('seller.orders.index')],
                          ['label' => '#' . $order->id],
                      ]"
                      :sub="translate('placed') . ' ' . \App\Services\SellerCenter\Moment::stamp($order->created_at) . ' · ' . ($isCod ? translate('cod') : translate('card'))">
        <x-slot:actions>
            @if ($cancelled)
                {{-- A cancelled order collapses to view-only; the timeline still explains it. --}}
                <x-sc.badge status="cancelled" />
            @else
                <x-sc.button variant="secondary" icon="printer" :href="url('vendor/orders/generate-invoice/' . $order->id)">{{ translate('print_invoice') }}</x-sc.button>
                <x-sc.button variant="primary" :href="url('vendor/orders/details/' . $order->id)">{{ translate('manage_order') }}</x-sc.button>
            @endif
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <div class="sc-page sc-grid-detail">
            <div class="sc-stack">
                {{-- The contextual alert: names the deadline and one action (handoff 04 §25). --}}
                @if (in_array($sla['state'], ['breached', 'closing', 'soon'], true))
                    <x-sc.alert :tone="$sla['tone']" :glyph="$sla['glyph']"
                                :title="translate('ship_by_sla_at_risk') . ' — ' . $slaText">
                        {{ translate('this_order_is_inside_its_ship_by_window_the_countdown_is_measured_from_when_it_was_placed') }}
                        <x-slot:action>
                            <x-sc.button variant="secondary" size="sm" :href="url('vendor/orders/details/' . $order->id)">
                                {{ translate('manage_order') }}
                            </x-sc.button>
                        </x-slot:action>
                    </x-sc.alert>
                @endif

                <x-sc.card :title="translate('items')" flush>
                    <div class="sc-table-wrap">
                        <table class="sc-table">
                            <thead>
                                <tr>
                                    <th>{{ translate('product') }}</th>
                                    <th style="width:130px">{{ translate('sku') }}</th>
                                    <th class="sc-cell--num" style="width:60px">{{ translate('qty') }}</th>
                                    <th class="sc-cell--num" style="width:110px">{{ translate('unit') }}</th>
                                    <th class="sc-cell--num" style="width:110px">{{ translate('line') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($order->orderDetails as $line)
                                    <tr>
                                        {{-- A line outlives the product it sold: a deleted product
                                             leaves its id rather than an empty row. --}}
                                        <td>{{ $line->product?->getRawOriginal('name') ?: ('#' . $line->product_id) }}</td>
                                        <td class="sc-code sc-muted">{{ $line->product?->code ?: '—' }}</td>
                                        <td class="sc-cell--num">{{ (int) $line->qty }}</td>
                                        <td class="sc-cell--num sc-money">{{ number_format((float) $line->price) }}</td>
                                        <td class="sc-cell--num sc-money">
                                            {{ number_format((float) $line->price * (int) $line->qty - (float) $line->discount) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div style="padding:12px 16px;border-top:1px solid var(--sc-line-soft);display:flex;justify-content:flex-end">
                        <div style="width:260px" class="sc-stack--tight">
                            <div class="sc-row">
                                <span class="sc-dim" style="flex:1 1 auto;font-size:12px">{{ translate('items_subtotal') }}</span>
                                <span class="sc-money">{{ number_format((float) $order->orderDetails->sum(fn ($line) => $line->price * $line->qty - $line->discount)) }}</span>
                            </div>
                            <div class="sc-row">
                                <span class="sc-dim" style="flex:1 1 auto;font-size:12px">{{ translate('shipping_charged') }}</span>
                                <span class="sc-money">{{ number_format((float) $order->shipping_cost) }}</span>
                            </div>
                            @if ((float) $order->discount_amount > 0)
                                <div class="sc-row">
                                    <span class="sc-dim" style="flex:1 1 auto;font-size:12px">{{ translate('discount') }}</span>
                                    {{-- Deductions carry a leading minus and stay text-coloured;
                                         colour in finance is reserved for a status (handoff 06 §4). --}}
                                    <span class="sc-money">−{{ number_format((float) $order->discount_amount) }}</span>
                                </div>
                            @endif
                            <div style="height:1px;background:var(--sc-line-soft);margin:2px 0"></div>
                            <div class="sc-row">
                                <span style="flex:1 1 auto;font-size:12.5px">
                                    {{ $isCod ? translate('customer_pays_cod') : translate('order_total') }}
                                </span>
                                <span class="sc-money" style="font-size:14px">{{ number_format((float) $order->order_amount) }} {{ $currency }}</span>
                            </div>
                        </div>
                    </div>
                </x-sc.card>

                <x-sc.card :title="translate('fulfilment_and_shipping')">
                    <div class="sc-info-grid">
                        <x-sc.info :label="translate('model')" :value="$order->order_type === 'POS' ? translate('pos') : translate('seller_fulfilled')" />
                        <x-sc.info :label="translate('carrier')" :value="$order->deliveryMan ? trim($order->deliveryMan->f_name . ' ' . $order->deliveryMan->l_name) : ($order->shipping_type ? translate($order->shipping_type) : '—')" />
                        <x-sc.info :label="translate('payment_status')" :value="translate($order->payment_status ?? 'unpaid')" />
                        <x-sc.info :label="translate('ship_by')"
                                   :tone="in_array($sla['state'], ['breached', 'closing', 'soon'], true) ? $sla['tone'] : null"
                                   :value="\App\Services\SellerCenter\Moment::stamp($sla['deadline'])" />
                        @if ($order->expected_delivery_date)
                            <x-sc.info :label="translate('promised_delivery')" :value="\App\Services\SellerCenter\Moment::day(\Illuminate\Support\Carbon::parse($order->expected_delivery_date), withYear: false)" />
                        @endif
                    </div>
                </x-sc.card>

                <x-sc.card :title="translate('timeline')">
                    @if ($timeline === null || $timeline['events'] === [])
                        <x-sc.empty glyph="list" :title="translate('nothing_recorded_yet')"
                                    :text="translate('every_status_change_appears_here_with_who_made_it')" />
                    @else
                        <x-sc.timeline>
                            @foreach ($timeline['events'] as $event)
                                @php($isSystem = in_array($event['key'], $automatic, true) || ($event['actor'] ?? null) === 'system')
                                <x-sc.timeline-item :tone="$isSystem ? 'info' : 'neutral'"
                                                    :time="\App\Services\SellerCenter\Moment::time(\Illuminate\Support\Carbon::parse($event['at']))"
                                                    :meta="\App\Services\SellerCenter\Moment::day(\Illuminate\Support\Carbon::parse($event['at'])) . ($event['actor'] ? ' · ' . translate($event['actor']) : '')">
                                    {{ translate($event['key']) }}{{ $event['note'] ? ' — ' . $event['note'] : '' }}
                                </x-sc.timeline-item>
                            @endforeach
                        </x-sc.timeline>
                    @endif
                </x-sc.card>
            </div>

            <div class="sc-stack sc-context">
                {{-- The earnings card requires finance read. Without it the card is absent, not
                     blanked, and the order total above stays visible (handoff 07.6). --}}
                @if ($canSeeEarnings && $breakdown !== null)
                    <x-sc.card side :label="translate('your_earnings')">
                        <div class="sc-stack--tight">
                            <div class="sc-row">
                                <span class="sc-dim" style="flex:1 1 auto;font-size:12px">{{ translate('gross') }}</span>
                                <span class="sc-money">{{ number_format((float) $breakdown['commissionable_amount']) }}</span>
                            </div>
                            <div class="sc-row">
                                <span class="sc-dim" style="flex:1 1 auto;font-size:12px">{{ translate('commission') }}</span>
                                <span class="sc-money">−{{ number_format((float) $breakdown['commission_amount']) }}</span>
                            </div>
                            @if ((float) ($breakdown['reversed_amount'] ?? 0) > 0)
                                <div class="sc-row">
                                    <span class="sc-dim" style="flex:1 1 auto;font-size:12px">{{ translate('reversed') }}</span>
                                    <span class="sc-money">−{{ number_format((float) $breakdown['reversed_amount']) }}</span>
                                </div>
                            @endif
                            <div style="height:1px;background:var(--sc-line-soft);margin:2px 0"></div>
                            <div class="sc-row">
                                <span style="flex:1 1 auto;font-size:12.5px">{{ translate('net_to_balance') }}</span>
                                <span class="sc-money" style="font-size:14px">{{ number_format((float) $breakdown['seller_receives']) }}</span>
                            </div>
                        </div>
                        {{-- "Not settled yet" is a real answer; a blank where a number belongs is not. --}}
                        <div class="sc-muted" style="font-size:11px;margin-top:6px">
                            {{ $breakdown['is_settled'] ? translate('recorded_in_the_ledger') : translate('not_settled_yet_this_is_what_it_will_be') }}
                        </div>
                    </x-sc.card>
                @endif

                <x-sc.card side :label="translate('customer_and_delivery')">
                    <div class="sc-stack--tight">
                        <x-sc.info :label="translate('name')"
                                   :value="$order->customer ? trim($order->customer->f_name . ' ' . $order->customer->l_name) : translate('walk_in_customer')" />
                        @if ($order->shippingAddress)
                            <x-sc.info :label="translate('city')" :value="$order->shippingAddress->city ?? '—'" />
                            <x-sc.info :label="translate('phone')">
                                {{-- Masked here on purpose; the carrier gets the full number at pickup. --}}
                                <span class="sc-code">{{ \Illuminate\Support\Str::mask((string) ($order->shippingAddress->phone ?? ''), '•', 5, 5) }}</span>
                            </x-sc.info>
                        @endif
                    </div>
                    <p class="sc-muted" style="font-size:11px;margin:8px 0 0">{{ translate('disclaimer_customer_contact') }}</p>
                </x-sc.card>
            </div>
        </div>
    </div>
@endsection
