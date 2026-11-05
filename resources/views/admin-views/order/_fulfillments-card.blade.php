{{-- Provider shipments of this order (M5AZN / Torod Point). Expects $order. Renders nothing for an order no provider fulfils. --}}
@if (\App\Services\Fulfillment\FulfillmentSchema::ready())
    @php
        $aggregator = \App\Services\Fulfillment\FulfillmentAggregator::class;
        $fulfillmentSummary = app($aggregator)->summarize($order['id']);
    @endphp
    @if ($fulfillmentSummary->fulfillments->isNotEmpty())
        @php
            $canManageFulfillments = \App\Utils\Helpers::module_permission_check('3rd_party_setup');
            $summaryTone = match ($fulfillmentSummary->status) {
                $aggregator::DELIVERED => 'success',
                $aggregator::ATTENTION_REQUIRED => 'danger',
                default => 'info',
            };
        @endphp
        <div class="card">
            <div class="card-body">
                <div class="d-flex gap-2 align-items-center justify-content-between mb-3">
                    <h4 class="fs-14 fw-bold mb-0">{{ translate('provider_fulfillments') }}</h4>
                    <x-k.badge :tone="$summaryTone">{{ translate('fulfillment_summary_' . $fulfillmentSummary->status) }}</x-k.badge>
                </div>

                @if ($fulfillmentSummary->mixed)
                    <p class="fs-12 text-muted mb-3">{{ translate('mixed_provider_order_admin_hint') }}</p>
                @endif
                @if ($fulfillmentSummary->deliveryBlockedReason && $order['order_status'] !== 'delivered')
                    <p class="fs-12 text-danger mb-3">{{ translate($fulfillmentSummary->deliveryBlockedReason) }}</p>
                @endif

                <div class="d-flex flex-column gap-3">
                    @foreach ($fulfillmentSummary->fulfillments as $providerFulfillment)
                        <div class="p-2 bg-section rounded">
                            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                                <strong class="fs-12">
                                    {{ config("fulfillment.providers.{$providerFulfillment->provider}.label", $providerFulfillment->provider) }}
                                    · {{ $providerFulfillment->reference }}
                                </strong>
                                @include('admin-views.fulfillment.partials._status-badge', ['status' => $providerFulfillment->status])
                            </div>
                            @if ($providerFulfillment->hold_reason && $providerFulfillment->status === \App\Enums\Fulfillment\FulfillmentStatus::Held)
                                <div class="fs-12 text-muted mt-1">{{ translate($providerFulfillment->hold_reason) }}</div>
                            @endif
                            <ul class="fs-12 mt-2 mb-0 ps-3">
                                @foreach ($providerFulfillment->items as $fulfillmentItem)
                                    <li>{{ $fulfillmentItem->name }} × {{ $fulfillmentItem->quantity }}</li>
                                @endforeach
                            </ul>
                            @if ($providerFulfillment->tracking_number)
                                <div class="fs-12 mt-2">
                                    {{ translate('tracking_number') }}:
                                    @if ($providerFulfillment->tracking_url)
                                        <a href="{{ $providerFulfillment->tracking_url }}" target="_blank" rel="noopener noreferrer">{{ $providerFulfillment->tracking_number }}</a>
                                    @else
                                        {{ $providerFulfillment->tracking_number }}
                                    @endif
                                </div>
                            @endif
                            @if ($providerFulfillment->payment_mode === \App\Enums\Fulfillment\PaymentMode::CashOnDelivery)
                                <div class="fs-12 mt-1">{{ translate('cash_to_collect') }}: {{ number_format((float) $providerFulfillment->cod_amount, 2) }} {{ $providerFulfillment->currency }}</div>
                            @endif
                            @if ($canManageFulfillments)
                                <a class="fs-12 d-inline-block mt-2" href="{{ route('admin.fulfillment-providers.fulfillments.show', $providerFulfillment->id) }}">{{ translate('view_shipment_details') }}</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
@endif
