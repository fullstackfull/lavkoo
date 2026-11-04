
    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasProductGalleryDetails{{ $product['id'] }}"
        aria-labelledby="offcanvasProductGalleryDetails{{ $product->id }}Label" style="--bs-offcanvas-width: 560px;">
        <div class="offcanvas-header bg-body">
            <h3 class="mb-0">{{ translate('Product_Details') }}</h3>
            <button type="button" class="btn btn-circle bg-white text-dark fs-10" style="--size: 1.5rem;" data-bs-dismiss="offcanvas" aria-label="Close">
                <i class="fi fi-rr-cross d-flex"></i>
            </button>
        </div>
        <div class="offcanvas-body">
            <div class="mb-4" id="product-gallery-offcanvas-item-{{ $product->id }}">
                <div class="pd-img-wrap position-relative d-flex gap-4 gap-sm-3 flex-column flex-sm-row">
                    <div class="">
                        <div class="quickviewSlider2 swiper-container border rounded aspect-1 inline-size-100 max-w-130 mx-auto position-relative" id="offcanvasQuickviewSlider2-{{ $product->id }}">
                            <div class="swiper-wrapper">
                                @php
                                    $imageSources = ($product->product_type === 'physical' && !empty($product->color_image) && count($product->color_images_full_url) > 0)
                                        ? $product->color_images_full_url
                                        : $product->images_full_url;
                                @endphp

                                @foreach ($imageSources as $key => $photo)
                                    @php
                                        $imagePath = isset($photo['image_name'])
                                            ? getStorageImages(path: $photo['image_name'], type: 'backend-product')
                                            : getStorageImages(path: $photo, type: 'backend-product');
                                    @endphp
                                    <div class="swiper-slide position-relative rounded border aspect-1">
                                        <div class="easyzoom easyzoom--overlay is-ready">
                                            <a href="{{ $imagePath }}">
                                                <img class="h-100 aspect-1 rounded min-w-130" alt="" src="{{ $imagePath }}">
                                            </a>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div class="d-flex flex-column gap-3">
                        <h3 class="text-capitalize mb-0">{{ $product['name'] }}</h3>
                        @if ($product?->tags->count() > 0)
                            <div class="d-flex gap-2 align-items-center text-dark">
                                <div>{{ translate('Tag') }}:</div>

                                <div class="d-flex flex-wrap gap-2 align-items-center fs-12 collapse-tag-div">

                                    @foreach ($product->tags->take(4) as $tag)
                                        <span class="bg-section2 rounded max-w-70 px-2 py-1 line-1">
                                            {{ $tag->tag }}
                                        </span>
                                    @endforeach

                                    @foreach ($product->tags->skip(4) as $tag)
                                        <span class="bg-section2 rounded max-w-70 px-2 py-1 line-1 extra-tag d-none">
                                            {{ $tag->tag }}
                                        </span>
                                    @endforeach

                                    @if ($product->tags->count() > 4)
                                        <button type="button" class="fw-semibold fs-12 border-0 bg-transparent p-0 collapse-tag-count">
                                            {{ $product->tags->count() - 4 }}+
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @endif

                        <div class="mt-1 user-select-none">
                            <div class="quickviewSliderThumb2 side__slider-custom  swiper-container position-relative active-border" id="offcanvasQuickviewSliderThumb2-{{ $product->id }}">
                                <div class="swiper-wrapper auto-item-width justify-content-start">
                                    @foreach ($imageSources as $key => $photo)
                                        @php
                                            $imagePath = isset($photo['image_name'])
                                                ? getStorageImages(path: $photo['image_name'], type: 'backend-product')
                                                : getStorageImages(path: $photo, type: 'backend-product');
                                        @endphp
                                        <div class="swiper-slide position-relative rounded border aspect-1" role="group" style="--size: 40px;">
                                            <img class="aspect-1" alt="" src="{{ $imagePath }}">
                                        </div>
                                    @endforeach
                                </div>

                                <div class="swiper-button-next" id="offcanvas-swiper-button-next-{{ $product->id }}" style="--size: 20px;"></div>
                                <div class="swiper-button-prev" id="offcanvas-swiper-button-prev-{{ $product->id }}" style="--size: 20px;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="p-10px bg-section rounded mb-3 overflow-wrap-anywhere">
                <div class="view--more rich-editor-html-content">
                    <div class="d-flex flex-column gap-1 fs-12">
                        {!! $product['details'] !!}
                    </div>
                    <button type="button" class="expandable-btn d-none h-auto p-0">
                        <span class="btn btn-outline-primary btn-sm mb-3">
                            <span class="more">{{ translate('See_More') }}</span>
                            <span class="less d-none">{{ translate('See_Less') }}</span>
                        </span>
                    </button>
                </div>
            </div>
            <div class="p-10px bg-section rounded mb-3 overflow-wrap-anywhere">
                <h4 class="mb-2">{{ translate('General_Information') }}</h4>
                <table class="fs-12">
                    <tbody>
                    <tr>
                        <td class="py-1 text-nowrap min-w-120">{{ translate('Product_Type') }}</td>
                        <td class="py-1 px-2">:</td>
                        <td class="py-1 text-dark fw-medium overflow-wrap-anywhere">
                            {{ translate($product->product_type) }}
                        </td>
                    </tr>
                    @if (!empty($product->category?->default_name))
                        <tr>
                            <td class="py-1 text-nowrap min-w-120">{{ translate('Category') }}</td>
                            <td class="py-1 px-2">:</td>
                            <td class="py-1 text-dark fw-medium overflow-wrap-anywhere">
                                {{ $product->category->default_name }}
                            </td>
                        </tr>
                    @endif
                    @if (!empty($product->subCategory?->default_name))
                        <tr>
                            <td class="py-1 text-nowrap min-w-120">{{ translate('Sub_category') }}</td>
                            <td class="py-1 px-2">:</td>
                            <td class="py-1 text-dark fw-medium overflow-wrap-anywhere">
                                {{ $product->subCategory->default_name }}
                            </td>
                        </tr>
                    @endif
                    @if ($product->product_type == 'physical' && !empty($product->brand?->default_name))
                        <tr>
                            <td class="py-1 text-nowrap min-w-120">{{ translate('Brand') }}</td>
                            <td class="py-1 px-2">:</td>
                            <td class="py-1 text-dark fw-medium overflow-wrap-anywhere">
                                {{ $product->brand->default_name }}
                            </td>
                        </tr>
                    @endif
                    @if ($product->product_type == 'physical')
                        <tr>
                            <td class="py-1 text-nowrap min-w-120">{{ translate('Unit') }}</td>
                            <td class="py-1 px-2">:</td>
                            <td class="py-1 text-dark fw-medium overflow-wrap-anywhere">
                                {{ $product->unit }}
                            </td>
                        </tr>
                        @if( $product->current_stock > 0)
                        <tr>
                            <td class="py-1 text-nowrap min-w-120">{{ translate('Current_Stock') }}</td>
                            <td class="py-1 px-2">:</td>
                            <td class="py-1 text-dark fw-medium overflow-wrap-anywhere">
                                {{ $product->current_stock }}
                            </td>
                        </tr>
                        @endif
                    @endif
                    <tr>
                        <td class="py-1 text-nowrap min-w-120">{{ translate('Product_SKU') }}</td>
                        <td class="py-1 px-2">:</td>
                        <td class="py-1 text-dark fw-medium overflow-wrap-anywhere">
                            {{ $product->code }}
                        </td>
                    </tr>
                    @if ($product->barcode)
                        <tr>
                            <td class="py-1 text-nowrap min-w-120">{{ translate('product_barcode') }}</td>
                            <td class="py-1 px-2">:</td>
                            <td class="py-1 text-dark fw-medium overflow-wrap-anywhere direction-ltr">
                                {{ $product->barcode }}
                            </td>
                        </tr>
@endif
                    </tbody>
                </table>
            </div>

            <div class="p-10px bg-section rounded mb-3 overflow-wrap-anywhere">
                <h4 class="mb-2">{{ translate('Price_Information') }}</h4>
                <table class="fs-12">
                    <tbody>
                    <tr>
                        <td class="py-1 text-nowrap min-w-120">{{ translate('Unit_Price') }}</td>
                        <td class="py-1 px-2">:</td>
                        <td class="py-1 text-dark fw-medium overflow-wrap-anywhere">
                            {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $product->unit_price), currencyCode: getCurrencyCode()) }}
                        </td>
                    </tr>
                    @if ($product->product_type == 'physical')
                        <tr>
                            <td class="py-1 text-nowrap min-w-120">{{ translate('Shipping_Cost') }}</td>
                            <td class="py-1 px-2">:</td>
                            <td class="py-1 text-dark fw-medium overflow-wrap-anywhere">
                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $product->shipping_cost)) }}
                                @if ($product->multiply_qty == 1)
                                    ({{ translate('multiply_with_quantity') }})
                                @endif
                            </td>
                        </tr>
                    @endif
                    @if (getProductPriceByType(product: $product, type: 'discount', result: 'value') > 0)
                        <tr>
                            <td class="py-1 text-nowrap min-w-120">{{ translate('Discount') }}</td>
                            <td class="py-1 px-2">:</td>
                            <td class="py-1 text-dark fw-medium overflow-wrap-anywhere">
                                {{ getProductPriceByType(product: $product, type: 'discount', result: 'string') }}
                            </td>
                        </tr>
                    @endif
                    </tbody>
                </table>
            </div>
            @php
                $colors = json_decode($product->colors ?? '[]', true);
                $colorNames = [];
                foreach ($colors as $c) {
                    $name = getColorNameByCode(code: $c);
                    $colorNames[] = $name ?: $c;
                }
                $choiceOptions = ($product->product_type === 'physical' && $product->choice_options)
                    ? json_decode($product->choice_options)
                    : [];
                $digitalExtensions = ($product->product_type === 'digital'
                    && !empty($product->digital_product_extensions))
                    ? $product->digital_product_extensions
                    : [];
                $hasVariations =count($colorNames) > 0 ||count($choiceOptions) > 0 || count($digitalExtensions) > 0;
            @endphp

            @if ($hasVariations && (count($colorNames) > 0 || count($choiceOptions) > 0 || count($digitalExtensions) > 0))
                <div class="p-10px bg-section rounded mb-3 overflow-wrap-anywhere">
                    <h4 class="mb-2">{{ translate('Available_Variations') }}</h4>

                    <table class="fs-12">
                        <tbody>
                        @if (count($colorNames) > 0)
                            <tr>
                                <td class="py-1 text-nowrap min-w-120">{{ translate('Color') }}</td>
                                <td class="py-1 px-2">:</td>
                                <td class="py-1 text-dark fw-medium overflow-wrap-anywhere">
                                    {{ implode(', ', $colorNames) }}
                                </td>
                            </tr>
                        @endif
                        @foreach ($choiceOptions as $choice)
                            <tr>
                                <td class="py-1 text-nowrap min-w-120">{{ $choice->title }}</td>
                                <td class="py-1 px-2">:</td>
                                <td class="py-1 text-dark fw-medium overflow-wrap-anywhere">
                                    {{ implode(', ', $choice->options) }}
                                </td>
                            </tr>
                        @endforeach
                        @foreach ($digitalExtensions as $extensionKey => $extensionGroup)
                            <tr>
                                <td class="py-1 text-nowrap text-capitalize">{{ translate($extensionKey) }}</td>
                                <td class="py-1 px-2">:</td>
                                <td class="py-1 text-dark fw-medium overflow-wrap-anywhere">
                                    {{ implode(', ', $extensionGroup) }}
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ((!empty($product['variation']) && count(json_decode($product['variation'])) > 0) || !empty($product->digitalVariation) && count($product->digitalVariation) > 0)
            <div class="p-10px bg-section rounded mb-3 overflow-wrap-anywhere">
                @if (!empty($product['variation']) && count(json_decode($product['variation'])) > 0)
                    <div class="col-md-12">
                        <div class="card border-0">
                            <div class="card-body p-0">
                                <div class="k-table-wrap">
                                    <table
                                        class="k-table">
                                        <thead>
                                        <tr>
                                            <th>{{ translate('SKU') }}</th>
                                            <th class="text-center text-capitalize">
                                                {{ translate('variation_wise_price') }}</th>
                                            <th class="text-center">{{ translate('stock') }}</th>
                                            <th></th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach (json_decode($product['variation']) as $key => $value)
                                            <tr>
                                                <td class="min-w-180 text-wrap overflow-wrap-anywhere">
                                                    <span class="py-1">{{ $value->sku }}</span>
                                                </td>
                                                <td class="text-center">
                                                    <span
                                                        class="py-1">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $value->price), currencyCode: getCurrencyCode()) }}</span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="py-1">{{ $value->qty }}</span>
                                                </td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                @if (!empty($product->digitalVariation) && count($product->digitalVariation) > 0)
                    <div class="col-md-12">
                        <div class="card border-0">
                            <div class="card-body p-0">
                                <div class="k-table-wrap">
                                    <table
                                        class="k-table">
                                        <thead>
                                        <tr>
                                            <th class="text-center">{{ translate('SL') }}</th>
                                            <th class="text-center">{{ translate('Variation_Name') }}</th>
                                            <th class="text-center">{{ translate('SKU') }}</th>
                                            <th class="text-center">{{ translate('price') }}</th>
                                            @if ($product->digital_product_type == 'ready_product')
                                                <th class="text-center">{{ translate('Action') }}</th>
                                            @endif
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach ($product->digitalVariation as $key => $variation)
                                            <tr>
                                                <td class="text-center">
                                                    {{ $key + 1 }}
                                                </td>
                                                <td class="text-center text-capitalize">
                                                    <span class="py-1">{{ $variation->variant_key ?? '-' }}</span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="py-1">{{ $variation->sku }}</span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="py-1">
                                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $variation->price), currencyCode: getCurrencyCode()) }}
                                                    </span>
                                                </td>

                                                @if ($product->digital_product_type == 'ready_product')
                                                    <td class="text-center">
                                                        <span class="btn p-0 getDownloadFileUsingFileUrl"
                                                              data-bs-toggle="tooltip"
                                                              data-bs-title="{{ !is_null($variation->file_full_url['path']) ? translate('download') : translate('File_not_found') }}"
                                                              data-file-path="{{ $variation->file_full_url['path'] }}">
                                                            <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/download-green.svg') }}"
                                                                 alt="">
                                                        </span>
                                                    </td>
                                                @endif
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
            @endif

        </div>
        <div class="offcanvas-footer shadow-popup">
            <div class="d-flex justify-content-center gap-2 gap-sm-3 bg-white px-3 py-2">
                <button type="reset" class="btn btn-secondary w-100 px-2 fs-12-mobile" data-bs-dismiss="offcanvas">{{ translate('Cancel') }}</button>
                <a href="{{ route('admin.products.update', ['id' => $product['id'], 'product-gallery' => 1])}}" class="btn btn-primary w-100 px-2 fs-12-mobile">{{ translate('Use_this_product_info') }}</a>
            </div>
        </div>
    </div>
