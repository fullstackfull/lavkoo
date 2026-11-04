<form action="{{ route('vendor.orders.customer-return-amount') }}" method="post" id="order-edit-return-amount-form-{{ $order['id'] }}">
    @csrf
    <input type="hidden" name="order_id" value="{{ $order['id'] }}">
    <input type="hidden" name="amount" value="{{ $order?->edit_return_amount ?? 0 }}">
    <div class="modal fade" id="returnDueAmountModal-{{ $order['id'] }}" tabindex="-1" aria-labelledby="returnDueAmountModal-{{ $order['id'] }}"
         aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content shadow-lg">
                <div class="modal-header border-0 p-2 pb-0 d-flex justify-content-end">
                    <button type="button" class="btn btn-circle border-0 fs-12 text-body bg-section2 shadow-none"
                            style="--size: 2rem;" data-dismiss="modal" aria-label="Close">
                        <i class="fi fi-sr-cross d-flex"></i>
                    </button>
                </div>
                <div class="modal-body px-20 pt-0">
                    <div class="d-flex flex-column align-items-center text-center mb-20">
                        <img src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/icons/cash.png') }}" width="64"
                             class="aspect-1 mb-20" alt="">
                        <h3 class="modal-title mb-3">{{ translate('Returned_the_Due_Amount') }}</h3>
                        <div>
                            {{ translate('please_confirm_that_the_due_amount_of') }}
                            <strong>{{ webCurrencyConverter(amount:  $order?->edit_return_amount ?? 0 ) }}</strong>
                            {{ translate('has_been_returned_to_the_customer') }}.
                        </div>
                    </div>
                    <div class="bg-section rounded-10 p-3">
                        <div class="mb-3">
                            <label for="" class="form-label">{{ translate('Payment_Method') }}</label>
                            <select class="custom-select" name="order_return_payment_method" id="">
                                <option value="manually">{{ translate('Paid_by_manually') }}</option>
                                @if($order['is_guest'] == 0 && getWebConfig(name: 'wallet_status') == 1)
                                    <option value="wallet">{{ translate('Wallet') }}</option>
                                @endif
                            </select>
                        </div>
                        <div>
                            <label for="" class="form-label d-flex gap-1">{{ translate('Payment_Info') }}
                                <span class="tooltip-icon" data-toggle="tooltip" data-placement="top"
                                      aria-label="{{ translate('Enter_your_payment_info') }}"
                                      data-title="{{ translate('Enter_your_payment_info') }}">
                                    <i class="fi fi-sr-info"></i>
                                </span>
                            </label>
                            <input type="text" class="form-control" name="order_return_payment_note" placeholder="{{ translate('Type_payment_info') }}" required>
                        </div>

                    </div>
                </div>
                <div class="modal-footer border-0 p-20">
                    <div class="w-100 d-flex justify-content-end gap-3">
                        <button type="button" class="btn btn-secondary max-w-120 flex-grow-1"
                                data-dismiss="modal">{{ translate('Cancel') }}</button>
                        <button type="submit" class="btn btn--primary max-w-120 flex-grow-1">{{ translate('Submit') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
