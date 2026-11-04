<div class="modal fade" id="editWithdrawInfoModal{{ $withdrawRequest['id'] }}" tabindex="-1"
     aria-labelledby="editWithdrawInfoModal{{ $withdrawRequest['id'] }}" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content shadow-lg">
            <div class="modal-header border-0 pb-3 d-flex justify-content-end align-items-end">
                <h5 class="fs-18 mb-0 flex-grow-1">{{ translate('Edit_Withdraw_Information') }}</h5>
                <button type="button" class="btn btn-circle border-0 fs-10 text-body bg-section2"
                        style="--size: 1.5rem;" data-dismiss="modal" aria-label="Close">
                    <i class="fi fi-sr-cross d-flex"></i>
                </button>
            </div>

            <form action="{{ route('vendor.dashboard.withdraw-request-update') }}" method="post" class="d-flex flex-column flex-grow-1 max-h-100vh-150px">
                @csrf

                @include("vendor-views.withdraw._withdraw-request-update-form", [
                    'withdrawalMethods' => $withdrawalMethods,
                    'vendorWithdrawMethods' => $vendorWithdrawMethods,
                    'withdrawRequest' => $withdrawRequest,
                ])

                <div class="modal-footer p-3 border-top bg-white d-flex flex-nowrap gap-1">
                    <button type="button" class="btn btn-secondary w-100" data-dismiss="modal">
                        {{ translate('close') }}
                    </button>
                    <button type="submit" class="btn btn--primary w-100">
                        {{ translate('Update_Request') }}
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>
