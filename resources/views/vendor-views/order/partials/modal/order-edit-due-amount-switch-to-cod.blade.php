<form action="{{ route('vendor.orders.customer-due-amount')  }}" method="post">
    @csrf
    <input type="hidden" name="order_id" value="{{ $order['id'] }}">
    <input type="hidden" name="order_due_amount" value="{{ $order['edit_due_amount'] ?? 0 }}">
    <div class="modal fade" id="switchToCODModal" tabindex="-1" aria-labelledby="switchToCODModal"
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
                        <h3 class="modal-title mb-3">{{ translate('Switch_to_Cash_on_Delivery?') }}</h3>
                        <div>
                            {{ translate('before_switching_this_order_to') }}
                            <strong>{{ translate('Cash_On_Delivery') }} (COD),</strong>
                            {{ translate('please_confirm_the_payment_issue_with_the_customer_to_avoid_any_misunderstandings') }}
                        </div>
                    </div>
                    <div class="d-flex justify-content-center gap-3">
                        <button type="button" class="btn btn-secondary w-100" data-dismiss="modal">
                            {{ translate('Cancel') }}
                        </button>
                        <button type="submit" class="btn btn--primary w-100">{{ translate('Confirm') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
