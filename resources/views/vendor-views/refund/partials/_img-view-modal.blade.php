<div class="modal fade imgViewModal" id="imgViewModal" tabindex="-1"
    aria-labelledby="imgViewModalLabel" role="dialog" aria-modal="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content bg-transparent border-0">
            <div class="modal-body pt-0">
                <div class="imgView-slider owl-theme owl-carousel" dir="ltr">
                    @foreach ($refund->images_full_url as $key => $photo)
                    <div class="imgView-item">
                        <div class="d-flex justify-content-between align-items-end">
                            <a href="{{ getStorageImages(path: $photo,type:'backend-basic') }}"
                                class="d-flex align-items-center gap-2 mb-2" download>
                                <div
                                    class="btn btn--download icon-btn bg-white d-flex justify-content-center align-items-center">
                                    <i class="fi fi-rr-download"></i>
                                </div>
                                <h5 class="text-white text-decoration-underline mb-0">
                                    {{ translate('Download_Image') }}
                                </h5>
                            </a>
                            <button type="button"
                                    class="btn btn-close close p-1 border-0"
                                    data-dismiss="modal"
                                    aria-label="Close">
                                <i class="fi fi-rr-cross-small fs-14 d-flex"></i>
                            </button>
                        </div>
                        <div class="image-wrapper">
                            <div class="position-relative">
                                <div class="image-wrapper">
                                    <img class="image" alt=""
                                        src="{{ getStorageImages(path: $photo,type:'backend-basic') }}">
                                </div>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
                @if(count($refund->images_full_url) > 1)
                <div class="imgView-slider_buttons d-flex justify-content-center" dir="ltr">
                    <button type="button" class="btn owl-btn imgView-owl-prev">
                        <i class="fi fi-sr-angle-small-left"></i>
                    </button>
                    <button type="button" class="btn owl-btn imgView-owl-next">
                        <i class="fi fi-sr-angle-small-right"></i>
                    </button>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>
