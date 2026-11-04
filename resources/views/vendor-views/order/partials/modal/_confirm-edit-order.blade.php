<div class="modal fade" id="confirm-edit-order-modal" tabindex="-1" aria-labelledby="confirm-edit-order-modal" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg">
            <div class="modal-header border-0 p-2 pb-0 d-flex justify-content-end">
                <button type="button" class="btn btn-circle border-0 fs-12 text-body bg-section2 shadow-none" style="--size: 2rem;" data-dismiss="modal" aria-label="Close">
                    <i class="fi fi-sr-cross d-flex"></i>
                </button>
            </div>
            <div class="modal-body px-20 py-0 mb-30">
                <div class="d-flex flex-column align-items-center text-center mb-30">
                    <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/modal/warning-new.png') }}" width="70" height="70" class="aspect-1 mb-20" id="toggle-modal-image" alt="">
                    <h3 class="modal-title mb-3" id="toggle-modal-title">{{ translate('Edit_this_order') }}?</h3>
                    <div class="text-center" id="toggle-modal-message">
                        {{ translate('make_sure_you_have_saved_all_changes_&_settle_the_payment_after_edit') }}.
                    </div>
                </div>
                <div class="d-flex justify-content-center gap-3">
                    <button type="button" class="btn btn-secondary max-w-120 flex-grow-1" data-dismiss="modal">{{ translate('No') }}</button>
                    <button type="button" id="confirm-edit-order" class="btn btn--primary max-w-120 flex-grow-1" data-dismiss="modal">{{ translate('Yes') }}</button>
                </div>
            </div>
        </div>
    </div>
</div>