<div class="bg-section rounded-10 p-12 p-sm-20">
    <div class="position-relative nav--tab-wrapper mb-3">
        <ul class="nav nav-tabs w-fit-content mb-4 border-0">
            @foreach ($languages as $lang)
                <li class="nav-item px-2">
                    <span class="nav-link text-capitalize form-system-language-tab {{ $lang == $defaultLanguage ? 'active' : '' }} cursor-pointer p-1"
                            id="{{ getLanguageCode($lang) }}-link">{{ getLanguageName($lang) . '(' . strtoupper($lang) . ')' }}</span>
                </li>
            @endforeach
        </ul>
    </div>
    @foreach ($languages as $lang)
        <div class="{{ $lang != $defaultLanguage ? 'd-none' : '' }} form-system-language-form {{ getLanguageCode($lang) }}-form"
                id="{{ getLanguageCode($lang) }}-form">
            <div class="form-group mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <label class="title-color"
                        for="{{ getLanguageCode($lang) }}_name">{{ translate('product_name') }}
                        ({{ strtoupper($lang) }})
                        @if($lang == $defaultLanguage)
                            <span class="input-required-icon">*</span>
                        @endif
                    </label>
                    @if(getActiveAIProviderConfigCache())
                    <button type="button" class="btn bg-white text-primary bg-transparent shadow-none border-0 opacity-1 generate_btn_wrapper p-0 auto_fill_title"
                            id="title-{{ getLanguageCode($lang) }}-action-btn"
                            data-lang="{{ getLanguageCode($lang) }}" data-route="{{ route('vendor.product.title-auto-fill') }}">
                            <div class="btn-svg-wrapper">
                                <img width="18" height="18" class=""
                                src="{{ dynamicAsset(path: 'public/assets/back-end/img/ai/blink-right-small.svg') }}" alt="">
                            </div>
                            <span class="ai-text-animation d-none" role="status">
                                {{ translate('Just_a_second') }}
                            </span>
                            <span class="btn-text">{{ translate('Generate') }}</span>
                    </button>
                    @endif
                </div>
                <div class="outline-wrapper" id="title-container-{{ getLanguageCode($lang) }}">
                    <input type="text" {{ $lang == $defaultLanguage ? 'required' : '' }} name="name[]"
                            data-required-msg="{{ translate('name_is_required') }}"
                            id="{{ getLanguageCode($lang) }}_name" class="form-control {{ $lang == $defaultLanguage ? 'product-title-default-language' : '' }}"
                            placeholder="{{ translate('new_product') }}">
                </div>
            </div>
            <input type="hidden" name="lang[]" value="{{ $lang }}">

            <div class="form-group mb-0">
                <div class="d-flex justify-content-between align-items-center">
                    <label class="title-color"
                            for="{{ getLanguageCode($lang) }}_description">
                        {{ translate('description') }}
                        ({{ strtoupper($lang) }})
                        @if($lang == $defaultLanguage)
                            <span class="input-required-icon">*</span>
                        @endif
                    </label>

                    @if(getActiveAIProviderConfigCache())
                    <button type="button" class="btn bg-white text-primary bg-transparent shadow-none border-0 opacity-1 generate_btn_wrapper p-0 auto_fill_description" id="title-{{ getLanguageCode($lang) }}-action-btn"
                            data-lang="{{ getLanguageCode($lang) }}" data-route="{{ route('vendor.product.description-auto-fill') }}">
                        <div class="btn-svg-wrapper">
                            <img width="18" height="18" class=""
                            src="{{ dynamicAsset(path: 'public/assets/back-end/img/ai/blink-right-small.svg') }}" alt="">
                        </div>
                        <span class="ai-text-animation d-none" role="status">
                            {{ translate('Just_a_second') }}
                        </span>
                        <span class="btn-text">{{ translate('Generate') }}</span>
                    </button>
                    @endif
                </div>
                <div class="outline-wrapper" id="editor-container-{{ getLanguageCode($lang)}}">
                    <div id="description-{{ getLanguageCode($lang) }}-editor" class="quill-editor editor-min-h-80"></div>
                    <textarea name="description[]" id="description-{{ getLanguageCode($lang) }}"
                              class="{{ $lang == $defaultLanguage ? 'product-description-default-language' : '' }}"
                              style="display:none;"></textarea>
                </div>
            </div>
        </div>
    @endforeach
</div>
