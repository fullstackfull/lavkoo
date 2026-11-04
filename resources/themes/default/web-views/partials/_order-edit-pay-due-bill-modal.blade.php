<div class="modal fade"
     id="orderEditPayDueBill-{{ $order['id'] }}"
     tabindex="-1"
     aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-md">
        <div class="modal-content">
            <div class="modal-header border-0">
                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
            </div>
            <div class="modal-body px-5">
                <form action="{{ route('customer.customer-order-edit-pay-amount') }}" method="post">
                    @csrf
                    <div class="pb-4">
                        <h4 class="text-center">
                            {{ translate('add_Fund_to_Wallet') }}
                        </h4>
                        <p class="text-center">
                            {{ translate('add_fund_by_from_secured_digital_payment_gateways') }}
                        </p>
                        <input type="hidden" name="order_id" value="{{ $order?->id }}">
                        <input type="text"
                               name="order_due_payment_note"
                               class="form-control mb-3"
                               placeholder="{{ translate('note_optional') }}">
                        <input type="number"
                               class="h-70 form-control text-center text-24 rounded-10 fs-25-important light-placeholder"
                               id="add-fund-amount-input"
                               value="{{ $order?->edit_due_amount ?? 0 }}"
                               name="amount"
                               required
                               placeholder="{{ translate('ex') }}: {{ webCurrencyConverter(amount: 500) }}">
                        <input type="hidden" name="payment_platform" value="web" required>
                        <input type="hidden"
                               name="external_redirect_link"
                               value="{{ request()->url() }}"
                               required>
                    </div>

                    <div id="add-fund-list-area">
                        @if(count($paymentGatewayList) > 0)
                            <h6 class="mb-2">
                                {{ translate('payment_Methods') }}
                                <small>({{ translate('faster_&_secure_way_to_pay_bill') }})</small>
                            </h6>

                            <div class="gateways_list">
                                @foreach ($paymentGatewayList as $gateway)
                                    @php(
                                        $payment_method_title = !empty($gateway->additional_data)
                                            ? (json_decode($gateway->additional_data)->gateway_title
                                                ?? ucwords(str_replace('_',' ', $gateway->key_name)))
                                            : ucwords(str_replace('_',' ', $gateway->key_name))
                                    )

                                    @php(
                                        $payment_method_img = !empty($gateway->additional_data)
                                            ? json_decode($gateway->additional_data)->gateway_image
                                            : ''
                                    )

                                    <label class="form-check form--check rounded mb-2">
                                        <input type="radio"
                                               class="form-check-input d-none"
                                               name="payment_method"
                                               value="{{ $gateway->key_name }}"
                                               required>

                                        <div class="form-check-label d-flex align-items-center">
                                            <img width="60"
                                                 alt="{{ translate('payment') }}"
                                                 src="{{ getValidImage(
                                                     path: 'storage/app/public/payment_modules/gateway_image/'.$payment_method_img,
                                                     type: 'banner'
                                                 ) }}">

                                            <span class="ms-3">
                                                {{ $payment_method_title }}
                                            </span>
                                        </div>
                                    </label>
                                @endforeach
                            </div>

                            <div class="d-flex justify-content-center pt-2 pb-3">
                                <button type="submit"
                                        class="btn btn--primary w-75 mx-3"
                                        id="add_fund_to_wallet_form_btn">
                                    {{ translate('add_Fund') }}
                                </button>
                            </div>
                        @else
                            <h6 class="small text-center">
                                {{ translate('no_Payment_Methods_Gateway_found') }}
                            </h6>
                        @endif
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<!-- Button trigger modal -->
