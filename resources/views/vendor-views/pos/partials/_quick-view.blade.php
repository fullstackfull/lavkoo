<div class="modal-body">
    <button class="radius-50 border-0 font-weight-bold text-black-50 position-absolute right-3 top-3 z-index-99"
        type="button" data-dismiss="modal" aria-label="Close">
        <span aria-hidden="true">&times;</span>
    </button>
    <div class="row gy-3">
        <div class="col-md-5">
            <div class="pd-img-wrap position-relative">
                <div class="d-flex gap-2 align-items-center quick-view-tag">
                    @if($product->product_type == 'digital')
                        <div class="bg-white border btn btn-circle" style="--size: 30px" data-toggle="tooltip" title="{{ translate('Digital_Product') }}" data-placement="right">
                            <img height="16" class="aspect-1 svg" src="{{dynamicAsset(path: "public/assets/front-end/img/icons/digital-product.svg")}}" alt="">
                        </div>
                    @else
                        <div class="bg-white border btn btn-circle" style="--size: 30px" data-toggle="tooltip" title="{{ translate('Physical_Product') }}" data-placement="right">
                            <img height="16" class="aspect-1 svg" src="{{dynamicAsset(path: "public/assets/front-end/img/icons/physical-product.svg")}}" alt="">
                        </div>
                    @endif
                </div>
                <div class="swiper-container quickviewSlider2 border rounded aspect-1">
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

                                $colorCode = $photo['color'] ?? '';
                            @endphp
                            <div class="swiper-slide position-relative rounded border" data-color="{{ $colorCode }}">
                                <div class="easyzoom easyzoom--overlay is-ready">
                                    <a href="{{ $imagePath }}">
                                        <img class="rounded h-100 aspect-1" alt="" src="{{ $imagePath }}">
                                    </a>
                                </div>
                            </div>
                        @endforeach

                    </div>
                </div>

                <div class="mt-3 user-select-none">
                    <div class="quickviewSliderThumb2 swiper-container position-relative active-border">
                        <div class="swiper-wrapper auto-item-width justify-content-start">
                            @foreach ($imageSources as $key => $photo)
                                @php
                                    $imagePath = isset($photo['image_name'])
                                        ? getStorageImages(path: $photo['image_name'], type: 'backend-product')
                                        : getStorageImages(path: $photo, type: 'backend-product');
                                @endphp
                                <div class="swiper-slide position-relative rounded border" role="group">
                                    <img class="aspect-1" alt="" src="{{ $imagePath }}">
                                </div>
                            @endforeach
                        </div>

                        <div class="swiper-button-next swiper-quickview-button-next"></div>
                        <div class="swiper-button-prev swiper-quickview-button-prev"></div>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap align-items-center row-gap-2 column-gap-3 fs-14 mt-4">

                <div class="d-flex align-items-center gap-1 flex-grow-1">
                    <div class="fw-semibold text-dark">{{ translate('SKU') }}:</div>
                    <div class="fs-12">{{ $product->code }}</div>
                </div>

                <div class="d-flex align-items-center gap-1 flex-grow-1">
                    <div class="fw-semibold text-dark">{{ translate('categories') }}: </div>
                    <div class="fs-12">{{ $product->category->name ?? translate('not_found') }}</div>
                </div>

                @if ($product->product_type == 'physical' && $product?->brand)
                    <div class="d-flex align-items-center gap-1 flex-grow-1">
                        <div class="fw-semibold text-dark">{{ translate('brand') }}:</div>
                        <div class="fs-12">{{ $product?->brand?->name ?? translate('not_found') }}</div>
                    </div>
                @endif

                @if ($product->product_type == 'digital')
                    @php
                        $selectedAuthorNames = collect($digitalProductAuthors)
                           ->whereIn('id', $productAuthorIds)
                           ->pluck('name')
                           ->toArray();
                        $selectedPublisherNames = collect($publishingHouseRepo)
                            ->whereIn('id', $productPublishingHouseIds)
                            ->pluck('name')
                            ->toArray();
                    @endphp
                    @if (!empty($selectedAuthorNames))
                        <div class="d-flex align-items-center gap-2">
                            <div class="font-weight-bold text-dark">{{ translate('Author') }}:</div>
                            <div>
                                {{ implode(', ', $selectedAuthorNames) }}
                            </div>
                        </div>
                    @endif
                    @if (!empty($selectedPublisherNames))
                        <div class="d-flex align-items-center gap-2">
                            <div class="font-weight-bold text-dark">{{ translate('Publisher') }}:</div>
                            <div>
                                {{ implode(', ', $selectedPublisherNames) }}
                            </div>
                        </div>
                    @endif
                @endif

                @if (count($product->tags) > 0)
                    <div class="d-flex align-items-center gap-1 flex-grow-1 flex-wrap">
                        <div class="fw-semibold text-dark">{{ translate('tag') }}:</div>
                        @foreach ($product->tags as $tag)
                            <span class="d-flex align-items-center gap-1">
                                <span class="fs-12 pt-1"><i class="fi fi-rr-tags"></i></span>
                                <span class="fs-14">{{ Str::limit($tag->tag, 15, '...') }}</span>
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
        <div class="col-md-7">
            <div>
                <form id="add-to-cart-form" class="add-to-cart-details-form">
                    @csrf
                    <div class="overflow-y-auto max-h-300 pb-2">
                        <div class="details">
                            <div class="d-flex flex-wrap gap-3 align-items-center mb-4">
                                @php
                                    $variations = json_decode($product->variation, true) ?? [];
                                    $hasVariations = is_array($variations) && count($variations) > 0;
                                    if ($hasVariations) {
                                        $firstVariation = $variations[0];
                                        $currentStock = $firstVariation['qty'] ?? 0;
                                        $currentPrice = $firstVariation['price'] ?? 0;
                                         $allVariationsOutOfStock = true;
                                        foreach ($variations as $v) {
                                            if (!empty($v['qty']) && $v['qty'] > 0) {
                                                $allVariationsOutOfStock = false;
                                                break;
                                            }
                                        }
                                    } else {
                                        $currentStock = max(0, $product->current_stock);
                                        $currentPrice = $product->unit_price;
                                    }
                                @endphp
                                @if($product->product_type === 'physical')
                                    <div class="d-flex gap-2 align-items-center">
                                        {{ translate('Stock') }} :
                                        <span class="text-dark fw-semibold current-stock-qty">
                                                {{ $currentStock }}
                                            </span>
                                    </div>
                                    <div class="d-flex gap-2 align-items-center text-success rounded-pill bg-success-light px-2 py-1 stock-status-in-quick-view">
                                        <i class="tio-checkmark-circle-outlined"></i>
                                        {{ $currentStock > 0 ? translate('In_Stock') : translate('out_of_stock.') }}
                                    </div>
                                @endif
                                @if ($product->discount > 0)
                                    <div
                                        class="d-flex gap-1 align-items-center text--primary rounded-pill bg-primary-light px-2 py-1">
                                        @if ($product->discount_type === 'percent')
                                            {{ $product->discount . ' % ' . translate('OFF') }}
                                        @else
                                            <span  class="set-discount-amount"></span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                            <h2 class="mb-3 product-title fs-20">{{ $product->name }}</h2>

                            @if ($product->reviews_count > 0)
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <i class="tio-star text-warning"></i>
                                    <span
                                        class="text-muted text-capitalize">({{ $product->reviews_count . ' ' . translate('customer_review') }})</span>
                                </div>
                            @endif

                            <div class="d-flex flex-wrap align-items-center gap-3 mb-2 text-dark">
                                <h2
                                    class="text--primary text-accent price-range-with-discount d-flex gap-2 align-items-center mb-0">
                                    @if (getProductPriceByType(product: $product, type: 'discount', result: 'value') > 0)
                                    <del class="product-total-unit-price align-middle text-ADB0B7 fs-20 fw-semibold">
                                        {{ webCurrencyConverter(amount: $product->unit_price) }}
                                    </del>
                                    @endif
                                    <span class="discounted-unit-price fs-30 fw-semibold">
                                        {{ getProductPriceByType(product: $product, type: 'discounted_unit_price', result: 'string') }}
                                    </span>
                                </h2>
                            </div>
                        </div>

                        <?php
                        $cart = false;
                        if (session()->has('cart')) {
                            foreach (session()->get('cart') as $key => $cartItem) {
                                if (is_array($cartItem) && $cartItem['id'] == $product['id']) {
                                    $cart = $cartItem;
                                }
                            }
                        }
                        ?>


                        <input type="hidden" name="id" value="{{ $product->id }}">
                        <div class="variant-change">
                            <div class="position-relative mb-4">
                                @if (count(json_decode($product->colors)) > 0)
                                    <div class="d-flex flex-wrap gap-3 align-items-center">
                                        <span class="text-9B9B9B">{{ translate('color') }}</span>

                                        <div class="color-select d-flex gap-2 flex-wrap" id="option1">
                                            @foreach (json_decode($product->colors) as $key => $color)
                                                <input class="btn-check action-color-change" type="radio"
                                                    id="{{ $product->id }}-color-{{ $key }}" name="color"
                                                    value="{{ $color }}"
                                                    @if ($key == 0) checked @endif autocomplete="off">
                                                <label id="label-{{ $product->id }}-color-{{ $key }}"
                                                    class="color-ball mb-0 {{ $key == 0 ? 'border-add' : '' }}"
                                                    style="background: {{ $color }};"
                                                    for="{{ $product->id }}-color-{{ $key }}"
                                                    data-toggle="tooltip">
                                                    <i class="tio-done"></i>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                                @php
                                    $qty = 0;
                                    if (!empty($product->variation)) {
                                        foreach (json_decode($product->variation) as $key => $variation) {
                                            $qty += $variation->qty;
                                        }
                                    }
                                @endphp
                            </div>
                            @foreach (json_decode($product->choice_options) as $key => $choice)
                                <div class="d-flex gap-3 flex-wrap align-items-center mb-3">
                                    <div class="my-2 w-43px">
                                        <span class="text-9B9B9B">{{ ucfirst($choice->title) }}</span>
                                    </div>
                                    <div class="d-flex gap-3 flex-wrap px-1">
                                        @foreach ($choice->options as $index => $option)
                                            <input class="btn-check" type="radio"
                                                id="{{ $choice->name }}-{{ $option }}" name="{{ $choice->name }}"
                                                value="{{ $option }}"
                                                @if ($index == 0) checked @endif autocomplete="off">
                                            <label class="btn btn-sm check-label border-0 mb-0 w-auto pos-check-label"
                                                for="{{ $choice->name }}-{{ $option }}">{{ $option }}</label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach

                            @php($extensionIndex = 0)
                            @if (
                                $product['product_type'] == 'digital' &&
                                    $product['digital_product_file_types'] &&
                                    count($product['digital_product_file_types']) > 0 &&
                                    $product['digital_product_extensions']
                            )
                                @foreach ($product['digital_product_extensions'] as $extensionKey => $extensionGroup)
                                    <div class="d-flex gap-3 flex-wrap align-items-center mb-3">
                                        <div class="my-2">
                                            <span class="text-9B9B9B">{{ translate($extensionKey) }}</span>
                                        </div>

                                        @if (count($extensionGroup) > 0)
                                            <div class="d-flex gap-2 flex-wrap">
                                                @foreach ($extensionGroup as $index => $extension)
                                                    <input class="btn-check" type="radio"
                                                        id="extension_{{ str_replace(' ', '-', $extension) }}"
                                                        name="variant_key"
                                                        value="{{ $extensionKey . '-' . preg_replace('/\s+/', '-', $extension) }}"
                                                        {{ $extensionIndex == 0 ? 'checked' : '' }} autocomplete="off">
                                                    <label
                                                        class="btn btn-sm check-label border bg-transparent mb-0 w-fit-content h-30 rounded-10 px-2 py-1 pos-check-label"
                                                        for="extension_{{ str_replace(' ', '-', $extension) }}">
                                                        {{ $extension }}
                                                    </label>
                                                    @php($extensionIndex++)
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-3 position-relative price-section mt-1">
                        <div class="alert alert--message flex-row alert-dismissible fade show pos-alert-message gap-2 d-none"
                            role="alert">
                            <img class="mb-1"
                                src="{{ dynamicAsset(path: 'public/assets/back-end/img/warning-icon.png') }}"
                                alt="{{ translate('warning') }}">
                            <div class="w-0">
                                <h6>{{ translate('warning') }}</h6>
                                <div class="product-stock-message"></div>
                            </div>
                            <a href="javascript:" class="align-items-center close-alert-message">
                                <i class="tio-clear"></i>
                            </a>
                        </div>
                        <div class="default-quantity-system d-none">
                            <div class="d-flex gap-3 align-items-center">
                               <span class="text-9B9B9B">{{ translate('qty') }}</span>
                                <div class="product-quantity d-flex align-items-center">
                                    <div class="d-flex align-items-center">
                                        <span class="product-quantity-group">
                                            <button type="button" class="btn-number bg-transparent"
                                                data-type="minus" data-field="quantity" disabled="disabled">
                                                <i class="tio-remove"></i>
                                            </button>
                                            <input type="text" name="quantity" id="quantity_input"
                                                class="form-control input-number text-center cart-qty-field"
                                                placeholder="1" value="1" min="1" max="{{$product['current_stock']}}">
                                            <button type="button"
                                                class="btn-number bg-transparent cart-qty-field-plus" data-type="plus"
                                                data-field="quantity">
                                                <i class="tio-add"></i>
                                            </button>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="in-cart-quantity-system d--none">
                            <div class="d-flex gap-3 align-items-center">
                                <span class="text-9B9B9B">{{ translate('qty') }}</span>
                                <div class="product-quantity d-flex align-items-center">
                                    <div class="d-flex align-items-center">
                                        <span class="product-quantity-group">
                                            <button type="button"
                                                class="btn-number bg-transparent in-cart-quantity-minus action-get-variant-for-already-in-cart"
                                                data-action="minus">
                                                <i class="tio-remove"></i>
                                            </button>
                                            <input type="text" name="quantity_in_cart"
                                                class="form-control text-center in-cart-quantity-field"
                                                placeholder="1" value="" min="1" max="{{$product['current_stock']}}">
                                            <button type="button"
                                                class="btn-number bg-transparent in-cart-quantity-plus action-get-variant-for-already-in-cart"
                                                data-action="plus">
                                                <i class="tio-add"></i>
                                            </button>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-1 title-color">
                            <div class="product-description-label text-dark font-weight-bold fs-12">
                                {{ translate('total_Price') }}:</div>
                            <div class="product-price c1">
                                <strong class="product-details-chosen-price-amount fs-16"></strong>
                            </div>
                        </div>

                    </div>
                    <div class="d-flex justify-content-center mt-4">
                        <button class="btn btn--primary btn-block quick-view-modal-add-cart-button action-add-to-cart"
                            type="button">
                            {{ ($hasVariations && $allVariationsOutOfStock) || ($currentStock < 1) ? translate('Out_of_Stock'): "Add_to_cart"}}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
