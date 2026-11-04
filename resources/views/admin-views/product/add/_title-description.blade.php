<div class="bg-section rounded-10 p-12 p-sm-20">
    <div class="position-relative nav--tab-wrapper mb-20">
        <ul class="nav nav-pills nav--tab text-capitalize lang_tab" id="pills-tab"
            role="tablist">
            @foreach ($languages as $lang)
                <li class="nav-item py-0" role="presentation">
                    <a class="nav-link {{ $lang == $defaultLanguage ? 'active' : '' }}"
                    id="{{ $lang }}-link" data-bs-toggle="pill" href="#{{ $lang }}-form"
                    role="tab" aria-controls="{{ $lang }}-form" aria-selected="true">
                        {{ getLanguageName($lang) . '(' . strtoupper($lang) . ')' }}
                    </a>
                </li>
            @endforeach
        </ul>
        <div class="nav--tab__prev">
            <button type="button" class="btn btn-circle border-0 bg-white text-primary">
                <i class="fi fi-sr-angle-left"></i>
            </button>
        </div>
        <div class="nav--tab__next">
            <button type="button" class="btn btn-circle border-0 bg-white text-primary">
                <i class="fi fi-sr-angle-right"></i>
            </button>
        </div>
    </div>
    <div class="tab-content" id="pills-tabContent">
        @foreach ($languages as $lang)
            <div class="tab-pane fade {{ $lang == $defaultLanguage ? 'show active' : '' }}"
                id="{{ $lang }}-form" role="tabpanel">
                <div class="form-group mb-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <label class="form-label" for="{{ $lang }}_name">
                            {{ translate('Product_Name') }}
                            ({{ strtoupper($lang) }})
                            @if($lang == $defaultLanguage)
                                <span class="input-required-icon text-danger">*</span>
                            @endif
                        </label>
                        @if(getActiveAIProviderConfigCache())
                            <button type="button" class="btn bg-white text-primary bg-transparent shadow-none border-0 opacity-1 generate_btn_wrapper p-0 auto_fill_title"
                                    id="title-{{ $lang }}-action-btn"  data-lang="{{  getLanguageCode(country_code: $lang) }}" data-route="{{ route('admin.product.title-auto-fill') }}">
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

                    <div class="outline-wrapper" id="title-container-{{ $lang }}">
                        <input type="text" {{ $lang == $defaultLanguage ? 'required' : '' }} name="name[]"
                            id="{{ getLanguageCode($lang) }}_name"  data-required-msg="{{ translate('title_field_is_required') }}"
                            class="form-control {{ $lang == $defaultLanguage ? 'product-title-default-language' : '' }}"
                            placeholder="{{ translate('ex') }}: {{ translate('new_Product') }}">
                    </div>
                </div>

                <input type="hidden" name="lang[]" value="{{ $lang }}">
                <div class="form-group mb-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <label class="form-label" for="{{ $lang }}_description">
                            {{ translate('description') }} ({{ strtoupper($lang) }})
                            @if($lang == $defaultLanguage)
                                <span class="input-required-icon text-danger">*</span>
                            @endif
                        </label>

                        @if(getActiveAIProviderConfigCache())
                        <button type="button" class="btn bg-white text-primary bg-transparent shadow-none border-0 opacity-1 generate_btn_wrapper p-0 auto_fill_description"   id="description-{{ $lang }}-action-btn"  data-lang="{{getLanguageCode($lang) }}" data-route="{{ route('admin.product.description-auto-fill') }}">
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
</div>
