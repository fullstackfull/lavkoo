<form action="{{route('vendor.orders.customer-due-amount-mark-as-paid')}}" method="post">
    @csrf
    <input type="hidden" name="order_id" value="{{ $order['id'] }}">
    <div class="modal fade" id="markAsPaidModal-{{ $order['id'] }}" tabindex="-1" aria-labelledby="markAsPaidModal"
         aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg">
                <div class="modal-header border-0 p-2 pb-0 d-flex justify-content-end">
                    <button type="button" class="btn btn-circle border-0 fs-12 text-body bg-section2 shadow-none"
                            style="--size: 2rem;" data-dismiss="modal" aria-label="Close">
                        <i class="fi fi-sr-cross d-flex"></i>
                    </button>
                </div>
                <div class="modal-body px-20 py-0 mb-30">
                    <div class="d-flex flex-column align-items-center text-center mb-30">
                        <img src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/icons/cod.png') }}"
                             width="64"
                             class="aspect-1 mb-20" alt="">
                        <h3 class="modal-title mb-3">{{ translate('Mark_as_Paid') }}?</h3>
                        <div>
                            {{ translate('please_carefully_review_the_customer’s_payment_details_and_the_receiving_account_information_before_marking_this_order_as_paid.') }}
                        </div>
                    </div>
                    <div class="d-flex justify-content-center gap-3">
                        <button type="button" class="btn btn-secondary w-100"
                                data-dismiss="modal">{{ translate('Cancel') }}</button>
                        <button type="submit" class="btn btn--primary w-100">{{ translate('Confirm') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

</form>
