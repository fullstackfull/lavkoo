<div class="modal fade" id="note-modal-{{ $status['id'] }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg">
            <div class="modal-header border-0 p-2 d-flex justify-content-end">
                <button type="button" class="btn btn-circle border-0 fs-12 text-body bg-section2 shadow-none"
                        style="--size: 2rem;" data-bs-dismiss="modal" aria-label="Close">
                    <i class="fi fi-sr-cross d-flex"></i>
                </button>
            </div>
            <div class="modal-body px-20 py-0 mb-30">
                <div class="d-flex flex-column align-items-center text-center mb-30">
                    <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/sticky-note.png') }}" width="60"
                         class="mb-20" alt="">
                    <h2 class="modal-title mb-3">
                        {{ translate('Approved') }}/{{ translate('Rejected_note') }}
                    </h2>
                    <div class="text-center">
                        {!! $status->message !!}
                    </div>
                </div>
                <div class="d-flex justify-content-center gap-3">
                    <button type="button" class="btn btn-primary w-120" data-bs-dismiss="modal">
                        {{ translate('Okay') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
