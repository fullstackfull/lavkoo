<div class="seo_wrapper">
    <div class="outline-wrapper">
        <div class="card card-body bg-animate">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h3 class="mb-1">
                        {{ translate('Seo_section') }}
                        <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                              data-bs-title="{{ translate('add_meta_titles_descriptions_and_images_for_blogs').', '.translate('this_will_help_more_people_to_find_them_on_search_engines_and_see_the_right_details_while_sharing_on_other_social_platforms') }}">
                            <i class="fi fi-sr-info"></i>
                        </span>
                    </h3>
                    <p class="fs-12 mb-0">{{ translate('add_meta_titles_descriptions_and_images_for_blog._this_will_help_more_people_to_find_them_on_search_engines.') }}</p>
                </div>
                @if(getActiveAIProviderConfigCache())
                <button type="button"
                    class="btn bg-white text-primary bg-transparent shadow-none border-0 opacity-1 generate_btn_wrapper p-0 blog_seo_section_auto_fill"
                    id="seo_section_auto_fill"
                    data-route="{{ route('admin.blog.seo-section-auto-fill') }}"
                    data-lang="en">
                    <div class="btn-svg-wrapper">
                        <img width="18" height="18"
                            src="{{ dynamicAsset(path: 'public/assets/back-end/img/ai/blink-right-small.svg') }}"
                            alt="">
                    </div>
                    <span class="ai-text-animation d-none" role="status">
                        {{ translate('Just_a_second') }}
                    </span>
                    <span class="btn-text">{{ translate('Generate') }}</span>
                </button>
                @endif
            </div>
            <div class="row g-3">
                <div class="col-xl-8">
                    <div class="bg-section rounded p-12 p-sm-20">
                        <div class="form-group">
                            <label class="form-label">
                                {{ translate('meta_Title') }}
                                <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                      data-bs-title="{{ translate('add_the_blogs_title_name_taglines_etc_here').' '.translate('this_title_will_be_seen_on_Search_Engine_Results_Pages_and_while_sharing_the_blogs_link_on_social_platforms') .' [ '. translate('character_Limit') }} : 100 ]">
                                    <i class="fi fi-sr-info"></i>
                                </span>
                            </label>
                             <input type="text" name="meta_title" placeholder="{{ translate('meta_Title') }}"
                                class="form-control" id="meta_title" value="{{ $blog?->seoInfo?->title }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                {{ translate('meta_Description') }}
                                <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                      data-bs-title="{{ translate('write_a_short_description_of_the_blog.').' '.translate('this_description_will_be_seen_on_search_engine_results_pages_and_while_sharing_the_blogs_link_on_social_platforms') .' [ '. translate('character_Limit') }} : 160 ]">
                                    <i class="fi fi-sr-info"></i>
                                </span>
                            </label>
                            <textarea rows="4" type="text" name="meta_description" id="meta_description"
                                class="form-control" data-maxlength="160"
                                placeholder="{{ translate('write_a_short_description_of_the_blog') }}">{{ $blog?->seoInfo?->description }}</textarea>
                            <div class="d-flex justify-content-end">
                                <span class="text-body-light">0/160</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4">
                    <div class="bg-section rounded p-12 p-sm-20 h-100">
                        <div class="d-flex flex-column justify-content-between h-100 gap-20">
                            <div class="text-center">
                                <label for="" class="form-label fw-semibold mb-1">
                                   {{ translate('Meta_Image') }}
                                </label>
                                <p class="fs-12 mb-0">{{ translate('Upload_meta_image') }}</p>
                            </div>
                            <div class="upload-file">
                                <input type="file" name="meta_image" class="upload-file__input single_file_input" accept="{{getFileUploadFormats(skip: '.svg')}}" value="" >
                                <label class="upload-file__wrapper w-325">
                                    <div class="upload-file-textbox text-center" style="">
                                        <img width="34" height="34" class="svg" src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}" alt="image upload">
                                        <h6 class="mt-1 fw-medium lh-base text-center">
                                            <span class="text-info">Click to upload</span>
                                            <br>
                                            Or drag and drop
                                        </h6>
                                    </div>
                                    <img class="upload-file-img" loading="lazy"
                                    src="{{ getStorageImages(path: $blog?->seoInfo?->image_full_url['path'] ? $blog?->seoInfo?->image_full_url : $blog?->meta_image_full_url, type: 'backend-banner') }}"
                                    data-default-src="{{ getStorageImages(path: $blog?->seoInfo?->image_full_url['path'] ? $blog?->seoInfo?->image_full_url : $blog?->meta_image_full_url, type: 'backend-banner') }}"
                                    alt="" style="display: none;">
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
                            <p class="fs-10 mb-0 text-center">
                                {{ getFileUploadFormats(skip: '.svg', asBladeMessage: true).' '. translate('Image_size'). ' : '. translate('Max').' '. getFileUploadMaxSize() . 'MB' }}
                                <span class="fw-medium">(325 x 100 px)</span>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="bg-section rounded p-12 p-sm-20 h-100">
                        <div class="robots-meta-checkbox-card h-100 d-flex flex-column gap-3">
                             <div class="bg-white p-3 rounded">
                                <div class="row gy-3">
                                    <div class="col-sm-6">
                                        <div class="item flex-grow-1 w-100">
                                            <label class="checkbox--item d-flex gap-2 align-items-center">
                                                <input type="radio" name="meta_index" class="form-check-input radio--input" value="index" {{ $blog?->seoInfo?->index != 'noindex' ? 'checked' : '' }}>
                                                <span class="user-select-none">{{ translate('Index') }}</span>
                                                <span data-bs-toggle="tooltip" title="{{ translate('allow_search_engines_to_put_this_web_page_on_their_list_or_index_and_show_it_on_search_results.') }}">
                                                    <img src="{{ dynamicAsset('public/assets/back-end/img/query.png') }}" alt="">
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="item flex-grow-1 w-100">
                                            <label class="checkbox--item d-flex gap-2 align-items-center">
                                                <input type="radio" name="meta_index" value="noindex" class="action-input-no-index-event form-check-input radio--input" {{ $blog?->seoInfo?->index == 'noindex' ? 'checked' : '' }}>
                                                <span class="user-select-none">{{ translate('no_index') }}</span>
                                                <span data-bs-toggle="tooltip" title="{{ translate('disallow_search_engines_to_put_this_web_page_on_their_list_or_index_and_do_not_show_it_on_search_results.') }}">
                                                    <img src="{{ dynamicAsset('public/assets/back-end/img/query.png') }}" alt="">
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                             </div>
                             <div class="bg-white p-3 rounded h-100">
                                <div class="row gy-3">
                                    <div class="col-sm-6">
                                        <div class="item d-flex gap-3 flex-column flex-grow-1">
                                            <label class="checkbox--item d-flex gap-2 align-items-center">
                                                <input type="checkbox" name="meta_no_follow" value="1" class="input-no-index-sub-element form-check-input checkbox--input" {{ !empty($blog?->seoInfo?->no_follow) ? 'checked' : '' }}>
                                                <span class="user-select-none">{{ translate('No_Follow') }}</span>
                                                <span data-bs-toggle="tooltip" title="{{ translate('instruct_search_engines_not_to_follow_links_from_this_web_page.') }}">
                                                    <img src="{{ dynamicAsset('public/assets/back-end/img/query.png') }}" alt="">
                                                </span>
                                            </label>
                                            <label class="checkbox--item d-flex gap-2 align-items-center">
                                                <input type="checkbox" name="meta_no_image_index" value="1" class="input-no-index-sub-element form-check-input checkbox--input" {{ $blog?->seoInfo?->no_image_index ? 'checked' : '' }}>
                                                <span class="user-select-none">{{ translate('No_Image_Index') }}</span>
                                                <span data-bs-toggle="tooltip" title="{{ translate('prevents_images_from_being_listed_or_indexed_by_search_engines') }}">
                                                    <img src="{{ dynamicAsset('public/assets/back-end/img/query.png') }}" alt="">
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="item d-flex gap-3 flex-column flex-grow-1">
                                            <label class="checkbox--item d-flex gap-2 align-items-center">
                                                <input type="radio" name="meta_index" value="noindex" class="action-input-no-index-event form-check-input radio--input" {{ $blog?->seoInfo?->index == 'noindex' ? 'checked' : '' }}>
                                                <span class="user-select-none">{{ translate('no_index') }}</span>
                                                <span data-bs-toggle="tooltip" title="{{ translate('disallow_search_engines_to_put_this_web_page_on_their_list_or_index_and_do_not_show_it_on_search_results.') }}">
                                                    <img src="{{ dynamicAsset('public/assets/back-end/img/query.png') }}" alt="">
                                                </span>
                                            </label>
                                            <label class="checkbox--item d-flex gap-2 align-items-center">
                                                <input type="checkbox" name="meta_no_archive" value="1" class="input-no-index-sub-element form-check-input checkbox--input" {{ $blog?->seoInfo?->no_archive ? 'checked' : '' }}>
                                                <span class="user-select-none">{{ translate('No_Archive') }}</span>
                                                <span data-bs-toggle="tooltip" title="{{ translate('instruct_search_engines_not_to_display_this_webpages_cached_or_saved_version.') }}">
                                                    <img src="{{ dynamicAsset('public/assets/back-end/img/query.png') }}" alt="">
                                                </span>
                                            </label>
                                            <label class="checkbox--item d-flex gap-2 align-items-center">
                                                <input type="checkbox" name="meta_no_snippet" value="1" class="input-no-index-sub-element form-check-input checkbox--input" {{ $blog?->seoInfo?->no_snippet ? 'checked' : '' }}>
                                                <span class="user-select-none">
                                                    {{ translate('No_Snippet') }}
                                                </span>
                                                <span data-bs-toggle="tooltip" data-bs-title="{{ translate('instruct_search_engines_not_to_show_a_summary_or_snippet_of_this_webpages_content_in_search_results.') }}">
                                                    <img src="{{ dynamicAsset('public/assets/back-end/img/query.png') }}" alt="">
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                             </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="bg-section rounded p-12 p-sm-20 h-100">
                        <div class="robots-meta-checkbox-card d-flex flex-column gap-3 gap-sm-2 h-100 bg-white p-3 rounded">
                            <div class="row gy-2">
                                <div class="col-sm-6">
                                    <div class="item">
                                        <label class="checkbox--item d-flex gap-2 align-items-center m-0">
                                            <input type="checkbox" name="meta_max_snippet" class="form-check-input checkbox--input" value="1" {{ $blog?->seoInfo?->max_snippet ? 'checked' : '' }}>
                                            <span class="user-select-none">
                                                {{ translate('max_Snippet') }}
                                            </span>
                                            <span data-bs-toggle="tooltip" data-bs-title="{{ translate('determine_the_maximum_length_of_a_snippet_or_preview_text_of_the_webpage.') }}">
                                                <img src="{{ dynamicAsset('public/assets/back-end/img/query.png') }}" alt="">
                                            </span>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="item w-120px flex-grow-0">
                                        <input type="number" placeholder="-1" class="form-control" name="meta_max_snippet_value" value="{{ $blog?->seoInfo?->max_snippet_value ?? '-1' }}">
                                    </div>
                                </div>
                            </div>
                            <div class="row gy-2">
                                <div class="col-sm-6">
                                    <div class="item">
                                        <label class="checkbox--item d-flex gap-2 align-items-center m-0">
                                            <input type="checkbox" name="meta_max_video_preview" class="form-check-input checkbox--input" value="1" {{ $blog?->seoInfo?->max_video_preview ? 'checked' : '' }}>
                                            <span class="user-select-none">
                                                {{ translate('max_Video_Preview') }}
                                            </span>
                                            <span data-bs-toggle="tooltip" data-bs-title="{{ translate('determine_the_maximum_duration_of_a_video_preview_that_search_engines_will_display') }}">
                                                <img src="{{ dynamicAsset('public/assets/back-end/img/query.png') }}" alt="">
                                            </span>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="item w-120px flex-grow-0">
                                        <input type="number" placeholder="-1" class="form-control" name="meta_max_video_preview_value" value="-1">
                                    </div>
                                </div>
                            </div>
                            <div class="row gy-2">
                                <div class="col-sm-6">
                                    <div class="item flex-1">
                                        <label class="checkbox--item d-flex gap-2 align-items-center m-0">
                                            <input type="checkbox" name="meta_max_image_preview" class="form-check-input checkbox--input" value="1" {{ $blog?->seoInfo?->max_image_preview ? 'checked' : '' }}>
                                            <span class="user-select-none">{{ translate('max_Image_Preview') }}</span>
                                            <span data-bs-toggle="tooltip" title="{{ translate('determine_the_maximum_size_or_dimensions_of_an_image_preview_that_search_engines_will_display.') }}">
                                                <img src="{{ dynamicAsset('public/assets/back-end/img/query.png') }}" alt="">
                                            </span>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="item w-120px flex-grow-1">
                                        <div class="select-wrapper">
                                            <select class="form-select" name="meta_max_image_preview_value">
                                                <option value="large" {{ $blog?->seoInfo?->max_image_preview_value == 'large' ? 'selected' : '' }}>{{ translate('large') }}</option>
                                                <option value="medium" {{ $blog?->seoInfo?->max_image_preview_value == 'medium' ? 'selected' : '' }}>{{ translate('medium') }}</option>
                                                <option value="small" {{ $blog?->seoInfo?->max_image_preview_value == 'small' ? 'selected' : '' }}>{{ translate('small') }}</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

