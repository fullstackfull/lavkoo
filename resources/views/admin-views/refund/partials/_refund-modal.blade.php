<div class="modal fade" id="refundModal-{{ $refund['id'] }}">
    <div class="modal-dialog modal-dialog-centered modal-lg" style="--bs-modal-width: 600px;">
        <div class="modal-content">
            <form action="{{ route('admin.refund-section.refund.refund-status-update') }}" method="post"
                  id="submit-refund-form-{{$refund['id']}}">
                @csrf
                 <div class="modal-header border-0 p-2 d-flex justify-content-end">
                    <button type="button" class="btn btn-circle border-0 fs-12 text-body bg-section2 shadow-none" style="--size: 2rem;" data-bs-dismiss="modal" aria-label="Close">
                        <i class="fi fi-sr-cross d-flex"></i>
                    </button>
                </div>
                <div class="modal-body py-0">
                    <input type="hidden" name="id" value="{{ $refund->id}}">
                    <input type="hidden" name="refund_status" value="refunded">
                    <div class="text-center">
                        <img class="mb-3"
                        width="60"
                             src="{{ dynamicAsset(path: 'public/assets/back-end/img/refund.png') }}"
                             alt="{{ translate('refund_approve') }}">
                        <h4 class="fs-18 mb-4 mx-auto px-0 px-sm-4">
                            {{ translate('once_you_refund_that_refund_request').', '.translate('then_you_would_not_able_change_any_status') }}
                        </h4>
                    </div>

                    @if($refund['status'] != 'approved')
                    <div class="bg-section rounded-10 p-3 mb-20">
                        <label class="form-label mb-2" for="">
                            {{ translate('Approval_Note') }} <span class="text-danger">*</span>
                        </label>
                        <textarea name="approved_note" id="" class="form-control" rows="3" placeholder="{{ translate('Please_write_the_approve_reason..') }}" required></textarea>
                    </div>
                    @endif

                    <div class="bg-section rounded-10 p-3">
                        <div class="mb-3">
                            <label class="form-label mb-2" for="">
                                {{ translate('payment_method') }} <span class="text-danger">*</span>
                            </label>
                            <div class="select-wrapper">
                                <select class="form-select" name="payment_method">
                                    <option value="cash">{{ translate('cash') }}</option>
                                    <option value="digitally_paid">{{ translate('digitally_paid') }}</option>
                                    @if ($walletStatus == 1 && $walletAddRefund == 1)
                                        <option value="customer_wallet">{{ translate('customer_wallet') }}</option>
                                    @endif
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="form-label mb-2" for="">{{ translate('payment_info') }} <span class="text-danger">*</span>
                                <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                      data-bs-placement="right"
                                      area-label="{{ translate('please_enter_the_payment_information_according_to_your_chosen_payment_method').'.'.translate('without_a_proper_payment_info,you_cannot_change_the_Refund_Status').'.'}}"
                                      data-bs-title="{{ translate('please_enter_the_payment_information_according_to_your_chosen_payment_method').'.'.translate('without_a_proper_payment_info,you_cannot_change_the_Refund_Status').'.'}}">
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                            </label>
                            <input type="text" class="form-control" name="payment_info" required
                                   placeholder="{{ translate('ex').' : '.'Paypal'}}">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-30">
                    <div class="d-flex flex-wrap justify-content-end gap-3">
                        <button type="button" class="btn btn-secondary px-4"
                                data-bs-dismiss="modal">{{ translate('close') }}</button>
                        <button type="button" class="btn btn-primary px-4 form-submit" data-form-id="submit-refund-form-{{$refund['id']}}"
                                data-message="{{ translate('want_to_refund_this_refund_request').'?' }}"
                                data-redirect-route="{{ route('admin.refund-section.refund.list', ['status'=>$refund['status']]) }}">
                            {{ translate('submit') }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
