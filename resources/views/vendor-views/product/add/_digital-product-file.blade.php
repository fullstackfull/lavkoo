<div class="col-md-4 digital-product-sections-show h-100">
    <div class="card card-body h-100">
        <div class="mb-20">
           <h3 class="mb-1">{{ translate('Product_Preview_File') }}</h3>
           <p class="fs-12 mb-0">
               {{ translate('Upload_a_short_preview.') }}
               <span class="text-info">{{ translate('Pdf,_Mp4,_Mp3,_size_:_Max_10_MB') }}</span>
           </p>
        </div>
        <div class="bg-section rounded-10 p-3 d-flex justify-content-center align-items-center">
            <div class="image-uploader overflow-visible" style="--size: 100px;"

                data-pdf-icon="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/product-upload-file.svg') }}"
                data-mp3-icon="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/product-upload-mp3.svg') }}"
                data-mp4-icon="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/product-upload-mp4.svg') }}"
                data-default-icon="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/product-upload-cloud.svg') }}"
            >

                <input type="file" name="preview_file" class="image-uploader__zip" id="input-file" accept=".pdf,.mp3,.mp4" data-max-size="{{ getFileUploadMaxSize(type: 'file')  }}">

                <div class="image-uploader__zip-preview gap-1 overflow-hidden">
                    <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/product-upload-cloud.svg') }}"
                        class="mx-auto upload-preview-icon h-30" width="28" alt="">
                    <div class="image-uploader__title fs-10 fw-medium text-info overflow-wrap-anywhere line-2">
                        {{ translate('Upload_File') }}
                    </div>
                </div>

                <span class="btn btn-danger btn-circle p-0 collapse zip-remove-btn zip-remove-btn__outside"
                    style="--size: 21px;">
                    <div class="d-flex justify-content-center align-items-center h-100">
                        <i class="fi fi-rr-cross-small d-flex"></i>
                    </div>
                </span>

            </div>
        </div>

    </div>
</div>
