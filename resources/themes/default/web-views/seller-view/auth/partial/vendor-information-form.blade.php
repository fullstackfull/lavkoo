<div class="second-el d--none">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body">
                        <h3 class="mb-4">{{ translate('create_an_account') }}</h3>
                        <div class="border p-3 p-xl-4 rounded">
                            <h4 class="mb-3">{{ translate('vendor_information') }}</h4>
                            <div class="row">
                                <div class="col-sm-6">
                                    <div class="form-group mb-4">
                                        <label for="f_name">{{ translate('first_name') }} <span
                                                class="text-danger">*</span></label>
                                        <input class="form-control" type="text" name="f_name"
                                            placeholder="{{ translate('ex') . ': John' }}" required>
                                    </div>
                                    <div class="form-group mb-4">
                                        <label for="l_name">{{ translate('last_name') }} <span
                                                class="text-danger">*</span></label>
                                        <input class="form-control" type="text" name="l_name"
                                            placeholder="{{ translate('ex') . ': Doe' }}" required>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="d-flex flex-column gap-3 align-items-center">
                                        <div class="upload-file">
                                            <input type="file" class="upload-file__input" name="image"
                                                   data-max-size="{{ getFileUploadMaxSize() }}"
                                                accept="{{ getFileUploadFormats(skip: '.svg') }}" >
                                            <div class="upload-file__img">
                                                <div class="temp-img-box">
                                                    <div class="d-flex align-items-center flex-column gap-2">
                                                        <i class="tio-upload fs-30"></i>
                                                        <div class="fs-12 text-muted text-capitalize">
                                                            {{ translate('upload_file') }}</div>
                                                    </div>
                                                </div>
                                                <img src="#" class="dark-support img-fit-contain border"
                                                    alt="" hidden>
                                            </div>
                                        </div>

                                        <div class="d-flex flex-column gap-1 upload-img-content text-center">
                                            <h6 class="text-uppercase mb-1 fs-14">
                                                {{ translate('vendor_image') }}
                                                <span class="text-danger">*</span>
                                            </h6>
                                            <div class="text-muted text-capitalize fs-12">
                                                {{ translate('image_ratio') . ' ' . '1:1' }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="border p-3 p-xl-4 rounded mt-4">
                            <h4 class="mb-3 text-capitalize">{{ translate('shop_information') }}</h4>

                            <div class="form-group mb-4">
                                <label for="store_name" class="text-capitalize">{{ translate('shop_Name') }} <span
                                        class="text-danger">*</span></label>
                                <input class="form-control" type="text" id="shop_name" name="shop_name"
                                    placeholder="{{ translate('Ex: XYZ store') }}" required>
                            </div>
                            <div class="form-group mb-4">
                                <label for="store_address" class="text-capitalize">{{ translate('shop_address') }}
                                    <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="shop_address" id="shop_address" rows="4"
                                    placeholder="{{ translate('shop_address') }}" required></textarea>
                            </div>

                            <div class="border p-3 p-xl-4 rounded mb-4">
                                <div class="d-flex flex-column gap-3 align-items-center">
                                    <div class="upload-file">
                                        <input type="file" class="upload-file__input" name="logo" data-max-size="{{ getFileUploadMaxSize() }}"  accept="{{getFileUploadFormats(skip: '.svg')}}"
                                            >
                                        <div class="upload-file__img">
                                            <div class="temp-img-box">
                                                <div class="d-flex align-items-center flex-column gap-2">
                                                    <i class="tio-upload fs-30"></i>
                                                    <div class="fs-12 text-muted text-capitalize">
                                                        {{ translate('upload_file') }}</div>
                                                </div>
                                            </div>
                                            <img src="#" class="dark-support img-fit-contain border"
                                                alt="" hidden>
                                        </div>
                                    </div>

                                    <div class="d-flex flex-column gap-1 upload-img-content text-center">
                                        <h6 class="text-uppercase mb-1 fs-14">
                                            {{ translate('upload_logo') }}
                                            <span class="text-danger">*</span>
                                        </h6>
                                        <div class="text-muted text-capitalize fs-12">
                                            {{ translate('image_ratio') . ' ' . '1:1' }}</div>
                                        <div class="text-muted text-capitalize fs-12">
                                            {{ translate('Image Size : Max 2 MB') }}</div>
                                    </div>
                                </div>
                            </div>

                            <div class="border p-3 p-xl-4 rounded">
                                <div class="d-flex flex-column gap-3 align-items-center">
                                    <div class="upload-file">
                                        <input type="file" class="upload-file__input" name="banner" data-max-size="{{ getFileUploadMaxSize() }}"   accept="{{getFileUploadFormats(skip: '.svg')}}"
                                            required>
                                        <div class="upload-file__img style--two">
                                            <div class="temp-img-box">
                                                <div class="d-flex align-items-center flex-column gap-2">
                                                    <i class="tio-upload fs-30"></i>
                                                    <div class="fs-12 text-muted text-capitalize">
                                                        {{ translate('upload_file') }}
                                                    </div>
                                                </div>
                                            </div>
                                            <img src="#" class="dark-support img-fit-contain border"
                                                alt="" hidden>
                                        </div>
                                    </div>

                                    <div class="d-flex flex-column gap-1 upload-img-content text-center">
                                        <h6 class="text-uppercase mb-1 fs-14">
                                            {{ translate('upload_banner') }}
                                            <span class="text-danger">*</span>
                                        </h6>
                                        <div class="text-muted text-capitalize fs-12">
                                            {{ translate('image_ratio') . ' ' . '2:1' }}</div>
                                        <div class="text-muted text-capitalize fs-12">
                                            {{ translate('Image Size : Max 2 MB') }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="border p-3 p-xl-4 rounded mt-2">
                            <div class="row gy-4">
                                <div class="col-lg-6">
                                    <div>
                                        <h4 class="mb-3 text-capitalize">
                                            {{ translate('Business_TIN') }}
                                        </h4>
                                        <div class="form-group mb-4">
                                            <label class="mb-2 text-capitalize" for="">
                                                {{ translate('taxpayer_identification_number(TIN)') }}
                                            </label>
                                            <input class="form-control" type="text"
                                                name="tax_identification_number"
                                                placeholder="{{ translate('type_your_user_name') }}">
                                        </div>
                                        <div class="form-group mb-4">
                                            <label class="mb-2 text-capitalize" for="">
                                                {{ translate('Expire_Date') }}
                                            </label>
                                            <div class="position-relative">
                                                <span class="fi fi-sr-calendar icon-absolute-on-right"></span>
                                                <input type="text"
                                                    class="js-daterangepicker_single-date-with-placeholder form-control"
                                                    placeholder="{{ translate('click_to_add_date') }}"
                                                    name="tin_expire_date" value="" readonly>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group mb-0">
                                        <div class="d-flex justify-content-center document-upload-container">
                                            <div class="document-file-assets"
                                                data-picture-icon="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/picture.svg') }}"
                                                data-document-icon="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/document.svg') }}"
                                                data-blank-thumbnail="{{ dynamicAsset(path: 'public/assets/back-end/img/file-placeholder.png') }}">
                                            </div>

                                            <div class="document-upload-wrapper doc-upload-wrapper">
                                                <input type="file" name="tin_certificate"
                                                       data-max-size="{{ getFileUploadMaxSize(type: 'file') }}"
                                                       data-validation-error-msg="{{ translate('File_size_is_too_large_Maximum_').' '.getFileUploadMaxSize(type: 'file').' '.'MB' }}"
                                                       class="document_input" accept=".pdf,.doc,.jpg">
                                                <div class="textbox">
                                                    <img class="svg"
                                                        src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/doc-upload-icon.svg') }}"
                                                        alt="">
                                                    <p class="fs-12 mb-0">
                                                        {{ translate('Select_a_file_or') }}
                                                        <span class="font-weight-semibold">
                                                            {{ translate('Drag_and_Drop_here') }}
                                                        </span>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="d-flex flex-column gap-1 upload-img-content text-center mt-3">
                                            <h6 class="text-uppercase mb-1">
                                                {{ translate('TIN_Certificate') }}
                                            </h6>
                                            <div class="text-muted text-capitalize">
                                                {{ translate('pdf,_doc,_jpg._file_size_:_max_5_MB') }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="my-2">

                        </div>

                        <div class="d-flex justify-content-start mt-2">
                            <label class="custom-checkbox align-items-center">
                                <input type="checkbox" class="" id="terms-checkbox">
                                <span class="form-check-label">
                                    {{ translate('i_agree_with_the') }}
                                    <a href="{{ route('business-page.view', ['slug' => 'terms-and-conditions']) }}"
                                        target="_blank" class="text-underline color-bs-primary-force">
                                        {{ translate('terms_&_conditions') }}
                                    </a>
                                </span>
                            </label>
                        </div>
                        <div class="d-flex justify-content-end mb-2 gap-2">
                            <button type="button" class="btn btn-secondary back-to-main-page">
                                {{ translate('back') }} </button>
                            <button type="submit" class="btn btn--primary" id="vendor-apply-submit"
                                disabled="disabled"> {{ translate('submit') }} </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
