<div id="imageModal" class="imageModal modal fade" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header justify-content-end gap-3 border-0 p-2">
                <button type="button" class="btn opacity-100 border-0 btn-circle bg-section2 shadow-none" style="--size: 20px;"
                        data-dismiss="modal" aria-label="Close">
                    <i class="fi fi-rr-cross-small d-flex"></i>
                </button>
            </div>
            <div class="modal-body text-center p-2 pt-0">
                <div class="imageModal_img_wrapper">
                    <img src="" class="img-fluid imageModal_img" alt="{{ translate('Preview_Image') }}">
                    <div class="imageModal_btn_wrapper">
                        <a href="javascript:" class="btn icon-btn download_btn" title="{{ translate('Download') }}" download>
                            <i class="fi fi-rr-download"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="image-modal-with-path" class="imageModal modal fade" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header justify-content-end gap-3 border-0 p-2">
                <button type="button" class="btn opacity-100 border-0 btn-circle bg-section2 shadow-none" style="--size: 20px;"
                        data-dismiss="modal" aria-label="Close">
                    <i class="fi fi-rr-cross-small d-flex"></i>
                </button>
            </div>
            <div class="modal-body text-center p-2 pt-0">
                <div class="imageModal_img_wrapper">
                    <img src="" class="img-fluid imageModal_img image-modal-img" alt="{{ translate('Preview_Image') }}">
                    <div class="imageModal_btn_wrapper">
                        <button class="btn btn-primary icon-btn copy-path image-modal-copy-path" title="{{ translate('Copy_Path') }}">
                            <i class="fi fi-rr-link-alt"></i>
                        </button>
                        <a href="javascript:" class="btn icon-btn download_btn image-modal-link" title="{{ translate('Download') }}" download>
                        <i class="fi fi-rr-download"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>