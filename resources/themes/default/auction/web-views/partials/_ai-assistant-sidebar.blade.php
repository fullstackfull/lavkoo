@if(getActiveAIProviderConfigCache())
<style>
    /* Fallback when the keyword field is a plain input (tagsinput not initialised). */
    #auctionAiAssistantModal .generate-text-input-group .form-control {
        border: none;
        background: transparent;
        box-shadow: none;
        outline: none;
        flex: 1;
        min-width: 100px;
        height: auto;
        padding: 0;
    }
    /* Mirror the reference suggestion-list styling (reference scopes this to #titlesList). */
    #auctionTitlesList .list-group-item {
        border: none;
        background-color: transparent;
        color: var(--bs-dark);
    }
    /* Highlight the section currently being auto-filled by the cascade. */
    .auction-ai-current {
        position: relative;
        box-shadow: 0 0 0 2px rgba(55, 125, 255, 0.18), 0 14px 30px rgba(55, 125, 255, 0.12);
        transition: box-shadow .25s ease, transform .25s ease;
    }
    .auction-ai-current::after {
        content: 'AI generating...';
        position: absolute;
        top: 14px;
        right: 14px;
        font-size: 12px;
        font-weight: 600;
        color: #377dff;
        background: #eef5ff;
        border: 1px solid rgba(55, 125, 255, 0.18);
        border-radius: 999px;
        padding: 4px 10px;
        z-index: 2;
    }
</style>

<div class="floating-ai-button">
    <button type="button" class="btn btn-lg rounded-circle shadow-lg" data-bs-toggle="modal" data-bs-target="#auctionAiAssistantModal"
        title="{{ translate('AI_Assistant') }}">
        <span class="ai-btn-animation">
            <span class="gradientCirc"></span>
        </span>
        <span class="position-relative z-1 text-white d-flex flex-column gap-1 align-items-center">
            <img width="16" height="17" src="{{ dynamicAsset(path: 'public/assets/back-end/img/ai/hexa-ai.svg') }}" alt="">
            <span class="fs-12 fw-semibold">{{ translate('Use_AI') }}</span>
        </span>
    </button>
    <div class="ai-tooltip">
        <span>{{ translate('AI_Assistant') }}</span>
    </div>
</div>

<div class="modal fade p-0" id="auctionAiAssistantModal" tabindex="-1" aria-labelledby="auctionAiAssistantModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-slideInRight modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header d-flex gap-2 aign-items-center justify-content-between">
                <h5 class="modal-title d-flex align-items-center gap-2" id="auctionAiAssistantModalLabel">
                    <span class="square-div">
                        <span class="ai-btn-animation">
                            <span class="gradientCirc"></span>
                        </span>
                        <img class="position-relative z-1" width="15" height="12" src="{{ dynamicAsset(path: 'public/assets/back-end/img/ai/blink-right.svg') }}" alt="">
                    </span>
                    <span id="auctionAiModalTitle">{{ translate('Upload_&_Analyze_Image') }}</span>
                </h5>
                <button type="button" class="btn btn-circle d-flex align-items-center justify-content-center p-0" style="--size: 24px; width: 24px; height: 24px; background-color: #efefef;" data-bs-dismiss="modal" aria-label="{{ translate('Close') }}">
                    <i class="fi fi-rr-cross d-flex" style="font-size: 11px; line-height: 1; color: #4b4b4b;"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="auctionMainAiContent" class="ai-modal-content" style="display: none;">
                    <div class="text-center mb-4">
                        <div class="ai-avatar mb-3">
                            <div class="avatar-circle mx-auto">
                                <span class="ai-btn-animation">
                                    <span class="gradientCirc"></span>
                                </span>
                                <img class="position-relative z-1" width="40" height="34" src="{{ dynamicAsset(path: 'public/assets/back-end/img/ai/blink-right.svg') }}" alt="">
                            </div>
                        </div>

                        <div class="ai-greeting mb-5">
                            <h4 class="text-title">{{ translate('Hi_There') }},</h4>
                            <h2 class="mb-2">{{ translate('I_am_here_to_help_you') }}!</h2>
                            <p class="">
                                {{ translate('i’m_your_personal_assistance_to_easy_your_long_task_smile') }}.
                                {{ translate('just_select_below_how_you_give_me_instruction_to_get_your_products_all_data') }}.
                            </p>
                        </div>

                        <div class="ai-actions d-flex flex-column align-items-center gap-3">
                            <button type="button" class="btn btn-outline-primary text-dark bg-transparent rounded-10 min-w-280px d-flex gap-2 ai-action-btn"
                                data-action="upload">
                                <img width="18" height="18" src="{{ dynamicAsset(path: 'public/assets/back-end/img/ai/picture.svg') }}" alt="">
                                <span class="text-title">{{ translate('Upload_Image') }}</span>
                            </button>
                            <button type="button" class="btn bg-section2 border text-dark rounded-10 min-w-280px d-flex gap-2 ai-action-btn"
                                data-action="title">
                                <img width="18" height="18" src="{{ dynamicAsset(path: 'public/assets/back-end/img/ai/text-generate.svg') }}" alt="">
                                <span class="text-title">{{ translate('Generate_Product_Name') }}</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div id="auctionUploadImageContent" class="ai-modal-content h-100" style="display: none;">
                <div class="d-flex justify-content-center align-items-end w-100 h-100">
                    <div>
                        <div class="mb-4">
                            <h5 class="fs-16 fw-bold">{{ translate('give_the_product_name_or_upload_image') }}</h5>
                            <p class="mb-3">{{ translate('please_give_proper_product_name_or_image_to_generate_full__data_for_your_product') }}</p>
                            <ul class="d-flex flex-column gap-2 mb-5">
                                <li>{{ translate('try_to_use_a_clean_&_avoid_blur_image') }}</li>
                                <li>{{ translate('use_as_close_as_your_product_image') }}</li>
                            </ul>
                        </div>
                        <div class="text-center mb-4">
                            <label class="upload-zone w-100 mx-auto" id="auctionChooseImageBtn">
                                <input type="file" id="auctionAiImageUpload" class="image-compressor d-none" hidden data-max-size="{{ getFileUploadMaxSize() }}"
                                       accept="{{ getFileUploadFormats(skip: '.svg,.gif') }}">
                                <div class="text-box mx-auto">
                                    <div class="w-100 d-flex flex-column gap-2 justify-content-center align-items-center py-4">
                                        <img width="40" height="40" src="{{ dynamicAsset(path: 'public/assets/back-end/img/ai/image-upload.svg') }}" alt="">
                                        <div class="d-flex gap-2 align-items-center justify-content-center flex-wrap fs-14">
                                            <span class="text-dark">{{ translate('drag_&_drop_your_image') }}</span>
                                            <span class="text-lowercase">{{ translate('or') }}</span>
                                            <span type="button" class="text-primary fw-semibold fs-12 text-underline">
                                                {{ translate('Browse_Image') }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div id="auctionImagePreview" class="w-fit-content mx-auto position-relative" style="display: none;">
                                    <img id="auctionPreviewImg" src="" alt="{{ translate('Preview') }}" class="upload-zone_img max-w-250px" style="max-height: 200px;">
                                    <div class="d-flex justify-content-center gap-2 flex-wrap">
                                        <button type="button" class="btn btn-danger p-0 square-div z-2 fs-10 remove_image_btn" id="auctionRemoveImageBtn"
                                            data-toggle="tooltip" title="{{ translate('Remove_image') }}">
                                            <i class="fi fi-rr-cross"></i>
                                        </button>
                                    </div>
                                </div>
                            </label>
                            <div class="mt-4 text-center analyzeImageBtn_wrapper">
                                <button type="button" class="btn btn-primary text-white mb-3 d-flex align-items-center gap-2 opacity-1 border-0 mx-auto px-4 py-3 position-relative"
                                    id="auctionAnalyzeImageBtn"
                                    data-url="{{ route('customer.auction.product.analyze-image-auto-fill') }}"
                                    data-lang="{{ getLanguageCode($defaultLanguage) }}">
                                    <span class="ai-btn-animation d-none">
                                        <span class="gradientRect"></span>
                                    </span>
                                    <span class="position-relative z-1 d-flex gap-2 align-items-center">
                                        <span class="d-flex align-items-center bg-transparent text-white btn-text">{{ translate('Generate_Product') }}</span>
                                        <img width="17" height="15" src="{{ dynamicAsset(path: 'public/assets/back-end/img/ai/blink-left.svg') }}" alt="">
                                    </span>
                                </button>
                                <div id="auctionAiProgressText" class="small text-primary fw-semibold d-none"></div>
                            </div>
                        </div>
                    </div>
                </div>
                </div>

                <div id="auctionGiveTitleContent" class="ai-modal-content" style="display: none;">
                    <div class="mb-4">
                        <div class="giveTitleContent_text">
                            <h5 class="mb-3 fs-16 fw-bold text-body lh-base">
                                {{ translate('great!') }}
                                <br>
                                {{ translate('now,_tell_me_which_product_you_want_to_create._just_type_it_simply,_like:') }}
                            </h5>
                            <ul class="d-flex flex-column gap-2 mb-3">
                                <li>{{ translate('i_need_product_details_for_men’s_converse_shoes') }}</li>
                                <li>{{ translate('i_want_to_add_a_men’s_t-shirt') }}</li>
                                <li>{{ translate('i_want_to_create_a_product_for_women’s_jeans') }}</li>
                            </ul>
                            <p class="mb-4">{{ translate('feel_free_to_describe_it_your_own_way!') }}</p>
                        </div>
                        <div class="generate-text-input-group">
                            <input type="text" class="form-control" id="auctionProductKeywords"
                                placeholder="{{ translate('Tell_me_about_your_item') }}" data-role="tagsinput">
                            <button type="button" class="btn btn-primary border-0" id="auctionGenerateTitleBtn"
                                data-route="{{ route('customer.auction.product.generate-title-suggestions') }}"
                                data-lang="{{ getLanguageCode($defaultLanguage) }}">
                                <span class="ai-loader-animation z-2 d-none">
                                    <span class="loader-circle"></span>
                                    <div class="position-relative h-100 d-flex justify-content-center align-items-center">
                                        <img width="15" height="15" class=""
                                        src="{{ dynamicAsset(path: 'public/assets/back-end/img/ai/blink-left.svg') }}" alt="">
                                    </div>
                                </span>
                                <span class="position-rtelative z-1 text-generate-icon"><i class="fi fi-rr-arrow-right"></i></span>
                            </button>
                        </div>
                    </div>

                    <div id="auctionGeneratedTitles" style="display: none;">
                        <div class="text-primary generate_btn_wrapper show_generating_text d-none mb-3">
                            <div class="btn-svg-wrapper">
                                <img width="18" height="18" class="" src="{{ dynamicAsset(path: 'public/assets/back-end/img/ai/blink-right-small.svg') }}"
                                alt="">
                            </div>
                            <span class="ai-text-animation ai-text-animation-visible">
                                {{ translate('Just_a_second') }}
                            </span>
                        </div>
                        <h4 class="mb-2 titlesList_title fs-14 fw-bold mb-4 d-none">{{ translate('Suggest_Product_Name') }}</h4>
                        <div id="auctionTitlesList" class="list-group gap-4"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-transparent border-0 shadow-none d-flex justify-content-center align-items-center pt-0">
                <div class="__bg-FAFAFA px-2 py-1 rounded text-center">
                    <p class="mb-0">{{ translate('AI_may_make_mistakes._please_recheck_important_data.') }}</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endif
