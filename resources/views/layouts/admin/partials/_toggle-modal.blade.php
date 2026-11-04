<div class="modal fade" id="toggle-modal" tabindex="-1" aria-labelledby="toggle-modal" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg">
            <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                    data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>
            <div class="modal-body px-20 py-0 mb-30">
                <div class="d-flex flex-column align-items-center text-center mb-30">
                    <img src="" width="80" class="mb-20" id="toggle-modal-image" alt="">
                    <h2 class="modal-title mb-3" id="toggle-modal-title"></h2>
                    <div class="text-center" id="toggle-modal-message"></div>
                </div>
                <div class="d-flex justify-content-center gap-3">
                    <button type="button" class="btn btn-secondary max-w-120 flex-grow-1"
                        data-bs-dismiss="modal">{{ translate('cancel') }}</button>
                    <button type="button" class="btn btn-primary max-w-120 flex-grow-1" id="toggle-modal-ok-button"
                        data-bs-dismiss="modal">{{ translate('yes') }}</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="toggle-status-modal" tabindex="-1" aria-labelledby="toggle-modal" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg">
            <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                    data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>
            <div class="modal-body px-20 py-0 mb-30">
                <div class="d-flex flex-column align-items-center text-center gap-2 mb-30">
                    <div
                        class="toggle-modal-img-box d-flex flex-column justify-content-center align-items-center mb-3 position-relative">
                        <img src="" class="status-icon" alt="" width="30" />
                        <img src="" id="toggle-status-modal-image" alt="" />
                    </div>
                    <h2 class="modal-title mb-3" id="toggle-status-modal-title"></h2>
                    <div class="text-center" id="toggle-status-modal-message"></div>
                </div>
                <div class="d-flex justify-content-center gap-3">
                    <button type="button" class="btn btn-secondary max-w-120 flex-grow-1 toggle-status-modal-no-button-text"
                        data-bs-dismiss="modal">{{ translate('cancel') }}</button>
                    <button type="button" class="btn btn-primary max-w-120 flex-grow-1 toggle-status-modal-button-text"
                        id="toggle-status-modal-ok-button" data-bs-dismiss="modal">{{ translate('yes') }}</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="toggle-status-custom-modal" tabindex="-1" aria-labelledby="toggle-modal" aria-hidden="true"
    style="z-index: 999999">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg">
            <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                    data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>
            <div class="modal-body px-20 py-0 mb-30">
                <div class="d-flex flex-column align-items-center text-center gap-2 mb-2">
                    <div
                        class="toggle-modal-img-box d-flex flex-column justify-content-center align-items-center mb-3 position-relative">
                        <img src="" class="status-icon" alt="" width="30" />
                        <img src="" id="toggle-status-custom-modal-image" alt="" />
                    </div>
                    <h3 class="modal-title" id="toggle-status-custom-modal-title"></h3>
                    <div class="text-center" id="toggle-status-custom-modal-message"></div>
                </div>
                <div class="d-flex justify-content-center gap-3 mt-4">
                    <button type="button" class="btn bg-light text-dark min-w-120 border-0"
                        data-bs-dismiss="modal">{{ translate('Not_Now') }}</button>
                    <button type="button"
                        class="btn btn-primary min-w-120 text-capitalize toggle-status-custom-modal-button-text"
                        id="toggle-status-custom-modal-ok-button"
                        data-bs-dismiss="modal">{{ translate('Yes_on') }}</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="success-modal" tabindex="-1" aria-labelledby="toggle-modal" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg">
            <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                    data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>
            <div class="modal-body px-20 py-0 mb-30">
                <div class="d-flex flex-column align-items-center text-center mb-30">
                    <img src="{{  dynamicAsset(path: 'public/assets/new/back-end/img/icons/modal-success-logo.png') }}" width="80" class="mb-20" alt="">
                    <h2 class="modal-title mb-3">System File Uploaded Successfully!</h2>
                    <div class="text-center">Your chosen theme are successfully uploaded. If want to active this theme click Active, or you can ignore.</div>
                </div>
                <div class="d-flex justify-content-center gap-3">
                    <button type="button" class="btn btn-primary max-w-180 flex-grow-1"
                        data-bs-dismiss="modal">{{ translate('Okay, Got it') }}</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="toggle-new-modal" tabindex="-1" aria-labelledby="toggle-modal" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg">
            <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                        data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>
            <div class="modal-body px-20 py-0 mb-30">
                <div class="d-flex flex-column align-items-center text-center mb-30">
                    <img src="" width="80" class="mb-20" id="toggle-new-modal-image" alt="">
                    <h2 class="modal-title mb-3" id="toggle-new-modal-title"></h2>
                    <div class="text-center" id="toggle-new-modal-message"></div>
                </div>
                <div class="d-flex justify-content-center gap-3">
                    <button type="button" class="btn btn-secondary max-w-120 flex-grow-1"
                            data-bs-dismiss="modal">{{ translate('no') }}</button>
                    <button type="button" class="btn btn-primary max-w-120 flex-grow-1" id="toggle-new-modal-ok-button"
                            data-bs-dismiss="modal">{{ translate('yes') }}</button>
                </div>
            </div>
        </div>
    </div>
</div>
