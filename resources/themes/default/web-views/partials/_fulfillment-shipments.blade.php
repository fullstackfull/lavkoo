{{--
    Shipments of one order, as the customer sees them: one order, several parcels. Provider names and
    internal references are never shown. Renders nothing for an order with no provider shipments.
--}}
@php($fulfillmentView = app(\App\Services\Fulfillment\CustomerFulfillmentPresenter::class)->forOrder((int) $orderId))
@if ($fulfillmentView)
    <div class="border rounded-10 p--20 mb-3" data-order-shipments>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <h6 class="mb-0 fw-semibold">{{ translate('your_shipments') }}</h6>
            <span class="badge badge-soft-primary">{{ $fulfillmentView['status_label'] }}</span>
        </div>

        @if ($fulfillmentView['notice'])
            <p class="fs-13 text-muted mb-3">{{ $fulfillmentView['notice'] }}</p>
        @endif

        @foreach ($fulfillmentView['shipments'] as $shipment)
            <div class="light-box rounded-8 p-3 mb-2">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <strong>{{ translate('shipment') }} {{ $shipment['shipment_number'] }}</strong>
                    <span class="badge badge-soft-info">{{ $shipment['status_label'] }}</span>
                </div>
                <ul class="mb-2 mt-2 ps-3 fs-13">
                    @foreach ($shipment['items'] as $item)
                        <li>{{ $item['name'] }} <span class="text-muted">× {{ $item['quantity'] }}</span></li>
                    @endforeach
                </ul>
                @if ($shipment['tracking_number'])
                    <div class="fs-13">
                        {{ translate('tracking_number') }}:
                        @if ($shipment['tracking_url'])
                            <a href="{{ $shipment['tracking_url'] }}" target="_blank" rel="noopener noreferrer" class="text-primary">{{ $shipment['tracking_number'] }}</a>
                        @else
                            <span class="fw-semibold">{{ $shipment['tracking_number'] }}</span>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif
