<form action="{{ route('admin.customer.profile-update') }}" method="POST" enctype="multipart/form-data" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
    @csrf
    <div class="offcanvas offcanvas-end" tabindex="-1" id="profileUpdateOffcanvas"
         aria-labelledby="profileUpdateOffcanvasLabel">
        <div class="offcanvas-header bg-body">
            <h3 class="mb-0"> {{ translate('Profile_Update') }}</h3>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <input type="hidden" name="id" value="{{ $customer['id'] }}">
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 d-flex flex-column gap-20">
                <div>
                    <label for="" class="form-label fw-semibold mb-1">
                        {{ translate('Customer_Profile') }}
                        <span class="text-danger">*</span>
                    </label>
                    <p class="fs-12 mb-0"> {{ translate('JPG,_JPEG,_PNG_Less_Than_1MB_(Ratio_1:1)') }}</p>
                </div>
                <div class="upload-file">
                    <input type="file" name="image" class="upload-file__input single_file_input"
                           accept=".webp, .jpg, .jpeg, .png, .gif" value="">
                    <label class="upload-file__wrapper">
                        <div class="upload-file-textbox text-center">
                            <img width="34" height="34" class="svg"
                                 src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}"
                                 alt="image upload">
                            <h6 class="mt-1 fw-medium lh-base text-center text-body">
                                <span class="text-info">{{ translate('Click_to_upload') }}</span>
                                <br>
                                {{ translate('Or_drag_and_drop') }}
                            </h6>
                        </div>
                        <img class="upload-file-img" loading="lazy" src="{{ getStorageImages(path: $customer->image_full_url , type: 'backend-profile') }}"
                             data-default-src="{{ getStorageImages(path: $customer->image_full_url , type: 'backend-profile') }}"
                             alt="">
                    </label>
                    <div class="overlay">
                        <div class="d-flex gap-10 justify-content-center align-items-center h-100">
                            <button type="button" class="btn btn-outline-info icon-btn view_btn">
                                <i class="fi fi-sr-eye"></i>
                            </button>
                            <button type="button" class="btn btn-outline-info icon-btn edit_btn">
                                <i class="fi fi-rr-camera"></i>
                            </button>
                        </div>
                    </div>
                </div>

            </div>
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 d-flex flex-column gap-20">
                <div class="form-group mb-0">
                    <label class="form-label" for="">
                        {{ translate('First_Name') }}
                        <span class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control" value="{{ $customer['f_name'] }}"
                           placeholder="{{ translate('Type_First_Name') }}" name="f_name" required>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label" for="">
                        {{ translate('Last_Name') }}
                        <span class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control" value="{{ $customer['l_name'] }}"
                           placeholder="{{ translate('Type_First_Name') }}" name="l_name" required>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label" for="">
                        {{ translate('Customer_Email') }}
                        <span class="text-danger">*</span>
                    </label>
                    <input type="email" class="form-control" value="{{ $customer['email'] }}"
                           placeholder="{{ translate('Type_Customer_Email') }}" name="email" required>
                </div>
            </div>
        </div>
        <div class="offcanvas-footer shadow-popup">
            <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
                <button type="reset" class="btn btn-secondary flex-grow-1">
                    {{ translate('Reset') }}
                </button>
                <button type="submit" class="btn btn-primary flex-grow-1">
                    {{ translate('Update Info') }}
                </button>
            </div>
        </div>
    </div>
</form>
