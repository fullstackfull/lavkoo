@php($deliverySyriaRates = \App\Models\DeliverySyriaGovernorateRate::orderBy('governorate')->get())
@php($deliverySyriaParcel = \App\Models\DeliverySyriaParcel::where('invoice_no', (string) $order['id'])->first())

<div class="modal" id="delivery_syria_dispatch_modal" role="dialog" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{ translate('dispatch_to_delivery_syria') }}</h5>
                <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                        data-bs-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                @if($deliverySyriaParcel && $deliverySyriaParcel->parcel_id)
                    <div class="bg-section rounded p-3 mb-2">
                        <div class="fw-medium mb-1">{{ translate('already_dispatched') }}</div>
                        <div class="fs-12" dir="ltr">
                            {{ translate('tracking') }}: {{ $deliverySyriaParcel->tracking_number }}<br>
                            {{ translate('wallet_status') }}: {{ $deliverySyriaParcel->wallet_status }}
                        </div>
                    </div>
                @elseif($deliverySyriaRates->isEmpty())
                    <div class="text-muted">
                        {{ translate('no_rates_synced_yet') }}.
                        <a href="{{ route('admin.third-party.delivery-syria') }}">{{ translate('verify_and_sync') }}</a>
                    </div>
                @else
                    <form action="{{ route('admin.orders.delivery-syria.dispatch') }}" method="POST">
                        @csrf
                        <input type="hidden" name="order_id" value="{{ $order['id'] }}">
                        <div class="form-group mb-3">
                            <label class="form-label">{{ translate('destination_governorate') }}</label>
                            <select class="form-select" name="governorate_code" required>
                                <option value="">--{{ translate('select') }}--</option>
                                @foreach($deliverySyriaRates as $rate)
                                    <option value="{{ $rate->code }}">
                                        {{ $rate->governorate }} ({{ translate('per_kg') }}: {{ $rate->base_cost }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group mb-3">
                            <label class="form-label">{{ translate('total_weight_kg') }}</label>
                            <input class="form-control" type="number" step="0.001" min="0.001" name="weight"
                                   placeholder="{{ translate('ex') }}: 3" required>
                        </div>
                        <p class="fs-12 text-muted mb-3">
                            {{ translate('the_courier_calculates_the_final_charge_this_is_an_estimate_helper') }}.
                        </p>
                        <button class="btn btn-primary" type="submit">{{ translate('dispatch') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
