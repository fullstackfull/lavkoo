<form action="{{ route('admin.deal.clearance-sale.update-seo-meta') }}"
    method="POST" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate"
    enctype="multipart/form-data" novalidate="novalidate">
    @csrf
    <div class="offcanvas offcanvas-end" tabindex="-1" id="clearanceSaleMetaSetupOffcanvas"
         aria-labelledby="clearanceSaleMetaSetupOffcanvasLabel" style="--bs-offcanvas-width: 500px;">
        <div class="offcanvas-header bg-body">
            <h3 class="mb-0">
                {{ translate('Meta Data Setup') }}
            </h3>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <div class="d-flex flex-column gap-20">
                <div class="p-12 p-sm-20 bg-section rounded">
                    <div class="form-group mb-20">
                        <label class="form-label">
                            {{ translate('meta_Title') }}
                            <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                  data-bs-placement="top"
                                  title="{{ translate('add_the_clearance_sale_taglines_etc_here.').' '.translate('this_meta_title_will_be_seen_on_search_engine_results_pages_and_while_sharing_the_clearance_sale_link_on_social_platforms') .' [ '. translate('character_Limit') }} : 50 ]">
                                <i class="fi fi-sr-info"></i>
                            </span>
                        </label>
                        <input type="text" name="meta_title" placeholder="{{ translate('meta_Title') }}"
                               class="form-control" id="meta_title" data-maxlength="50"
                        value="{{ $clearanceConfig?->seo?->title ?? '' }}">
                        <div class="d-flex justify-content-end">
                            <span class="text-body-light">0/50</span>
                        </div>
                    </div>
                    <div class="form-group mb-20">
                        <label class="form-label">
                            {{ translate('meta_Description') }}
                            <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                  data-bs-placement="top"
                                  title="{{ translate('write_a_short_description_of_the_clearance_sale.').' '.translate('this_description_will_be_seen_on_search_engine_results_pages_and_while_sharing_the_clearance_sale_link_on_social_platforms') .' [ '. translate('character_Limit') }} : 160 ]">
                                <i class="fi fi-sr-info"></i>
                            </span>
                        </label>
                        <textarea rows="4" type="text" name="meta_description" id="meta_description" data-maxlength="160"
                                  class="form-control" placeholder="{{ translate('Enter_your_meta_description') }}">{{ $clearanceConfig?->seo?->description ?? '' }}</textarea>
                        <div class="d-flex justify-content-end">
                            <span class="text-body-light">0/160</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="d-flex justify-content-center">
                            <label for="meta_Image" class="form-label fw-semibold mb-0">
                                {{ translate('Meta_Image') }}
                                <span class="badge badge-info text-bg-info">
                                    {{ THEME_RATIO[theme_root_path()]['Meta Thumbnail'] }}
                                </span>
                                <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                      aria-label="{{ translate('add_Meta_Image_in') }} {{ getFileUploadFormats(skip:'.svg,.gif') }} {{ translate('format_within') }} {{ getFileUploadMaxSize().'MB' }}, {{ translate('which_will_be_shown_in_search_engine_results') }}."
                                      data-bs-title="{{ translate('add_Meta_Image_in') }} {{ getFileUploadFormats(skip:'.svg,.gif') }} {{ translate('format_within') }} {{ getFileUploadMaxSize().'MB' }}, {{ translate('which_will_be_shown_in_search_engine_results') }}."
                                >
                                    <i class="fi fi-sr-info"></i>
                                </span>
                            </label>
                        </div>
                        <div class="d-flex justify-content-center pt-4">
                            <div class="upload-file">
                                <input type="file" name="meta_image"
                                       class="upload-file__input single_file_input"
                                       data-max-size="{{ getFileUploadMaxSize() }}"
                                       id="meta_image_input"
                                       accept="{{ getFileUploadFormats(skip:'.svg,.gif') }}"
                                       value=""
                                >
                                <label
                                    class="upload-file__wrapper ratio-2-1">
                                    <div class="upload-file-textbox text-center">
                                        <img width="34" height="34"
                                             src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}"
                                             alt="image upload">
                                        <h6 class="mt-1 fw-medium lh-base text-center">
                                            <span class="text-info">{{ translate('Click_to_upload') }}</span>
                                            <br>
                                            {{ translate('or_drag_and_drop') }}
                                        </h6>
                                    </div>
                                    <img class="upload-file-img pre-meta-image-viewer" loading="lazy"
                                         src="{{ $clearanceConfig?->seo?->image_full_url['path'] ? getStorageImages(path: $clearanceConfig?->seo?->image_full_url['path'] ? $clearanceConfig?->seo?->image_full_url : '', type: 'backend-banner') : '' }}"
                                         data-default-src="{{ $clearanceConfig?->seo?->image_full_url['path'] ? getStorageImages(path: $clearanceConfig?->seo?->image_full_url['path'] ? $clearanceConfig?->seo?->image_full_url : '', type: 'backend-banner') : '' }}"
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
                    </div>
                </div>

            </div>
        </div>

        <div class="offcanvas-footer shadow-popup">
            <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
                <button type="reset" id="reset" class="btn btn-secondary flex-grow-1">
                    {{ translate('reset') }}
                </button>
                <button type="submit" class="btn btn-primary flex-grow-1">
                    {{ translate('update') }}
                </button>
            </div>
        </div>
    </div>
</form>
