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
    @foreach($languages as $language)
            <?php
            if (count($product['translations'])) {
                $translate = [];
                foreach ($product['translations'] as $translation) {
                    if ($translation->locale == $language && $translation->key == "name") {
                        $translate[$language]['name'] = $translation->value;
                    }
                    if ($translation->locale == $language && $translation->key == "description") {
                        $translate[$language]['description'] = $translation->value;
                    }
                }
            }
            ?>
        <div class="{{ $language != 'en'? 'd-none' : '' }} form-system-language-form" id="{{ getLanguageCode($language) }}-form">
            <div class="form-group mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <label class="title-color" for="{{ getLanguageCode($language) }}_name">
                        {{ translate('product_name') }}
                        ({{strtoupper($language) }})
                        @if($language == 'en')
                            <span class="input-required-icon">*</span>
                        @endif
                    </label>

                    @if(getActiveAIProviderConfigCache())
                    <button type="button" class="btn bg-white text-primary bg-transparent shadow-none border-0 opacity-1 generate_btn_wrapper p-0 auto_fill_title" id="title-{{ getLanguageCode($language) }}-action-btn" data-item='@json(["title" => $translate[$language]["name"] ?? $product["name"]])' data-lang="{{ getLanguageCode($language) }}" data-route="{{ route('vendor.product.title-auto-fill') }}">
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
                <div class="outline-wrapper" id="title-container-{{ getLanguageCode($language) }}">
                    <input type="text" {{ $language == 'en' ? 'required':'' }} name="name[]"
                        id="{{ getLanguageCode($language) }}_name"
                        value="{{ $translate[$language]['name']??$product['name']}}"
                        class="form-control" placeholder="{{ translate('new_Product') }}" required>
                </div>
            </div>
            <input type="hidden" name="lang[]" value="{{ $language}}">
            <div class="form-group mb-0">
                <div class="d-flex justify-content-between align-items-center">
                    <label class="title-color">
                        {{ translate('description') }}
                        ({{strtoupper($language) }})
                        @if($language == 'en')
                            <span class="input-required-icon">*</span>
                        @endif
                    </label>

                    @if(getActiveAIProviderConfigCache())
                    <button type="button" class="btn bg-white text-primary bg-transparent shadow-none border-0 opacity-1 generate_btn_wrapper p-0 auto_fill_description"
                            id="description-{{ getLanguageCode($language) }}-action-btn" data-item='@json(["description" => $translate[$language]["description"] ?? $product["details"]])'
                            data-lang="{{ getLanguageCode($language) }}"
                            data-route="{{ route('vendor.product.description-auto-fill') }}">
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

                <div class="outline-wrapper" id="editor-container-{{ getLanguageCode($language) }}">
                    <div id="description-{{ getLanguageCode($language) }}-editor" class="quill-editor editor-min-h-80">{!! $translate[$language]['description']??$product['details'] !!}</div>
                    <textarea name="description[]" id="description-{{getLanguageCode($language)}}"
                              style="display:none;" data-required-msg="{{ translate('Description_field_is_required') }}" required>{!! $translate[$language]['description']??$product['details'] !!}</textarea>
                    <div class="blue-fire-animation"></div>
                </div>
            </div>
        </div>
    @endforeach
</div>
