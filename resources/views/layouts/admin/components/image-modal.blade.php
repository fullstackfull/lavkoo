<div id="imageModal" class="imageModal modal fade" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header justify-content-end gap-3 border-0 p-2">
                <button type="button" class="btn-close opacity-100 border-0 btn-circle bg-section2 shadow-none w-20 h-20 fs-8 m-0"
                        data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>
            <div class="modal-body text-center p-10 pt-0">
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
                <button type="button" class="btn-close opacity-100 border-0 btn-circle bg-section2 shadow-none w-20 h-20 fs-8 m-0"
                        data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>
            <div class="modal-body text-center p-10 pt-0">
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

<div id="" class="imageModal modal fade" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header justify-content-between gap-3 border-0 p-10 pb-3">
                <h4 class="mb-0 imageModal_title">2024-09-17-66e93d89d0896.webp</h4>
                <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none w-20 h-20 fs-12 m-0"
                        data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>
            <div class="modal-body text-center p-10 pt-0">
                <div>
                    <img src="" class="img-fluid imageModal_img" alt="Preview Image">
                </div>
                <div class="mt-3 d-flex justify-content-center align-items-center gap-3 flex-wrap">
                    <a href="#" class="btn btn-secondary px-4 w-120">
                        <i class="fi fi-sr-cloud-download-alt"></i>
                        Download
                    </a>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fi fi-rr-link-alt"></i>
                        Copy Path
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="" class="modal fade" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header justify-content-between gap-3 border-0 p-10 pb-3">
                <h4 class="mb-0 image-modal-title"></h4>
                <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none w-20 h-20 fs-12 m-0"
                        data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>
            <div class="modal-body text-center p-10 pt-0">
                <div>
                    <img src="" class="img-fluid image-modal-img" alt="{{ 'Preview Image' }}">
                </div>
                <div class="mt-3 d-flex justify-content-center align-items-center gap-3 flex-wrap">
                    <a href="#" class="btn btn-secondary px-4 w-120 image-modal-link" download>
                        <i class="fi fi-sr-cloud-download-alt"></i>
                        {{ translate('Download') }}
                    </a>
                    <button class="btn btn-primary px-4 copy-path image-modal-copy-path">
                        <i class="fi fi-rr-link-alt"></i>
                        {{ translate('Copy_Path') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
