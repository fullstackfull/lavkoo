@extends('layouts.front-end.app')

@section('title', $product['name'])

@push('css_or_js')
    @include(VIEW_FILE_NAMES['product_seo_meta_content_partials'], ['metaContentData' => $product?->seoInfo, 'productDetails' => $product])

    {{-- Adds the canonical, hreflang and Product structured data the partial above does not emit.
         Separate include so nothing already written there is duplicated. --}}
    @include('seo.product-supplement', ['productDetails' => $product])

    <link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/css/product-details.css') }}"/>
@endpush

@section('content')
    <div class="__inline-23">
        <div class="container my-4 rtl text-align-direction">
            <div class="row g-3 {{Session::get('direction') === "rtl" ? '__dir-rtl' : ''}}">
                <div class="col-lg-9">
                    <div class="h-100 d-flex flex-column gap-4 pb-2">
                        <div class="card card-body flex-grow-0">
                            <div class="row g-3">
                                <div class="col-lg-5 col-md-4">
                                    <div class="pd-img-wrap position-relative">
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
                                                    <div class="swiper-slide position-relative" data-color="{{ $colorCode }}">
                                                        <div class="easyzoom easyzoom--overlay is-ready">
                                                            <a href="{{ $imagePath }}">
                                                                <img class="rounded h-100 aspect-1" alt="" src="{{ $imagePath }}">
                                                            </a>
                                                        </div>
                                                    </div>
                                                @endforeach

                                                @if($product?->preview_file_full_url['path'])
                                                    <div class="product-preview-modal-text"
                                                         data-toggle="modal"
                                                         data-target="#product-preview-modal">
                                                        <span class="text-primary fw-bold py-2 user-select-none fs-14">
                                                            {{ translate('See_Preview') }}
                                                        </span>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>

                                        @if (getProductPriceByType(product: $product, type: 'discount', result: 'value') > 0)
                                        <div class="discount-badge-wrapper">
                                            <span class="fs-13 text-white bg-primary text-nowrap fw-bold d-block discount-badge">
                                                <span class="direction-ltr d-block">
                                                 -{{ getProductPriceByType(product: $product, type: 'discount', result: 'string') }}
                                                </span>
                                            </span>
                                        </div>
                                        @endif
                                        <div class="cz-product-gallery-icons">
                                            <div class="d-flex flex-column gap-12px pt-3">
                                                @if($product->product_type == "physical")
                                                <div class="bg-white btn-circle border" style="--size: 35px" data-toggle="tooltip" title="{{ translate('Physical_Product') }}" data-placement="left">
                                                    <img class="h-16px aspect-1 svg" src="{{theme_asset(path: "public/assets/front-end/img/icons/physical-product.svg")}}" alt="{{ translate('Physical_Product') }}">
                                                </div>
                                                @else
                                                    <div class="bg-white btn-circle border" style="--size: 35px" data-toggle="tooltip" title="{{ translate('Digital_Product') }}" data-placement="left">
                                                        <img class="h-16px aspect-1 svg" src="{{theme_asset(path: "public/assets/front-end/img/icons/digital-product.svg")}}" alt="{{ translate('Digital_Product') }}">
                                                    </div>
                                                @endif
                                                <button type="button" data-product-id="{{ $product['id'] }}"
                                                    class="btn __text-18px border position-static rounded-circle wishList-pos-btn d-sm-none product-action-add-wishlist">
                                                    <i class="fa {{($wishlistStatus == 1?'fa-heart':'fa-heart-o') }} wishlist_icon_{{ $product['id'] }} web-text-primary"
                                                    aria-hidden="true"></i>
                                                    <div class="wishlist-tooltip" x-placement="top">
                                                        <div class="arrow"></div>
                                                        <div class="inner">
                                                            <span class="add">{{ translate('added_to_wishlist') }}</span>
                                                            <span class="remove">{{ translate('removed_from_wishlist') }}</span>
                                                        </div>
                                                    </div>
                                                </button>

                                                <div class="share_dropdown_wrapper">
                                                    <button type="button" class="btn btn-outline-primary btn-circle p-0 share_btn" style="--size: 35px" tabindex="0">
                                                        <i class="fa fa-share-alt"></i>
                                                    </button>
                                                    <div class="share_dropdown bg-white d-flex gap-3 align-items-center flex-column">
                                                        <a href="#"  class="flex-shrink-0 btn btn-circle p-0 bg-facebook text-white share-on-social-media share_btn facebook" style="--size: 20px"   data-action="{{route('product',$product->slug)}}"    data-social-media-name="facebook.com/sharer/sharer.php?u=">
                                                            <i class="czi-facebook lh-1 fs-10"></i>
                                                        </a>
                                                        <a href="#" class="flex-shrink-0 btn btn-circle p-0 bg-twitter text-white share-on-social-media share_btn twitter" style="--size: 20px"    data-action="{{route('product',$product->slug)}}" data-social-media-name="twitter.com/intent/tweet?text=">
                                                            <i class="czi-twitter lh-1 fs-10"></i>
                                                        </a>
                                                        <a href="#" class="flex-shrink-0 btn btn-circle p-0 bg-linkedin text-white share-on-social-media share_btn linkedin" style="--size: 20px"  data-action="{{route('product',$product->slug)}}" data-social-media-name="linkedin.com/shareArticle?mini=true&url=">
                                                            <i class="czi-linkedin lh-1 fs-10"></i>
                                                        </a>
                                                        <a href="#" class="flex-shrink-0 btn btn-circle p-0 bg-whatsapp text-white share-on-social-media share_btn whatsapp" style="--size: 20px"  data-action="{{route('product',$product->slug)}}" data-social-media-name="api.whatsapp.com/send?text=">
                                                            <i class="fa fa-whatsapp lh-1 fs-10"></i>
                                                        </a>
                                                    </div>
                                                </div>
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
                                </div>

                                <div class="col-lg-7 col-md-8 mt-md-0 mt-sm-3 web-direction">
                                    <div class="details __h-100 product-cart-option-container p-0">
                                        {{-- Above the title: who makes it, whether it is in stock, and the
                                             two signals that decide a beauty purchase — authenticity and how
                                             much this price saves. Everything here is real store data except
                                             the viewer line, which the admin switches on in
                                             Business settings -> Product settings. --}}
                                        @php
                                            $__signals = app(\App\Services\Storefront\ProductPageSignalsService::class);
                                            $__brand = getWebConfig(name: 'product_brand') ? $product->brand : null;
                                            $__inStock = $product['product_type'] !== 'physical' || $product->current_stock > 0;
                                            $__off = $__signals->discountPercentage($product);
                                            $__authenticity = $__signals->authenticityBadge();
                                            $__viewers = $__signals->liveViewers($product);
                                            $__shortDescription = $__signals->shortDescription($product);
                                        @endphp

                                        <div class="pd-head">
                                            @if ($__brand)
                                                <a class="pd-brand" href="{{ \Illuminate\Support\Facades\Route::has('brand-products') && $__brand->slug
                                                        ? route('brand-products', ['slug' => $__brand->slug])
                                                        : route('products', ['brand_id' => $__brand->id]) }}">
                                                    @if ($__brand->image)
                                                        <img src="{{ getStorageImages(path: $__brand->image_full_url, type: 'brand') }}" alt="{{ $__brand->name }}">
                                                    @endif
                                                    <span>
                                                        <b>{{ $__brand->name }}</b>
                                                        <small>{{ translate('view_more') }}</small>
                                                    </span>
                                                </a>
                                            @endif
                                            <span class="pd-stock {{ $__inStock ? '' : 'is-out' }}">
                                                <i></i>{{ $__inStock ? translate('available_now') : translate('out_of_stock') }}
                                            </span>
                                        </div>

                                        @if ($__authenticity || $__off > 0)
                                            <div class="pd-badges">
                                                @if ($__authenticity)
                                                    <span class="pd-badge pd-badge--trust">{{ $__authenticity }}</span>
                                                @endif
                                                @if ($__off > 0)
                                                    <span class="pd-badge pd-badge--deal">
                                                        <i class="fi fi-sr-bolt"></i>{{ translate('limited_time_discount_do_not_miss_it') }}
                                                    </span>
                                                @endif
                                            </div>
                                        @endif

                                        <h2 class="mb-3 __inline-24">{{ $product->name }}</h2>
                                        <div class="d-flex flex-wrap align-items-center gap-3 mb-4 pro">
                                            @if($overallRating[0] !=0)
                                            <div class="star-rating me-2">
                                                @for($inc=1;$inc<=5;$inc++)
                                                    @if ($inc <= (int)$overallRating[0])
                                                        <i class="tio-star text-warning"></i>
                                                    @elseif ($overallRating[0] != 0 && $inc <= (int)$overallRating[0] + 1.1 && $overallRating[0] > ((int)$overallRating[0]))
                                                        <i class="tio-star-half text-warning"></i>
                                                    @else
                                                        <i class="tio-star-outlined text-warning"></i>
                                                    @endif
                                                @endfor
                                            </div>
                                                <span
                                                    class="d-inline-block  align-middle fs-14 text-muted">({{ $overallRating[0] }})</span>
                                                <span
                                                    class="font-weight-normal fs-14 font-for-tab d-inline-block font-size-sm text-body align-middle">
                                                   <a href="#reviews"
                                                      class="font-weight-normal fs-14 font-for-tab d-inline-block font-size-sm text-body align-middle"
                                                      data-toggle="tab"
                                                      role="tab">
                                                        <span class="web-text-primary fw-semibold">{{ $overallRating[1] }}</span> {{ translate('reviews') }}
                                                    </a>
                                                </span>
                                                <span class="border-middle-14px"></span>
                                               @endif
                                            <span
                                                class="font-weight-normal fs-14 font-for-tab d-inline-block font-size-sm text-body align-middle">
                                                <span class="web-text-primary fw-semibold fw-semibold">{{ $countOrder}}</span>
                                                    {{ translate('orders') }}
                                                </span>
                                            <span class="border-middle-14px"></span>
                                            <span
                                                class="font-weight-normal fs-14 font-for-tab d-inline-block font-size-sm text-body align-middle text-capitalize">
                                                <span class="web-text-primary fw-semibold countWishlist-{{ $product->id }}"> {{ $countWishlist}}</span> {{ translate('wish_listed') }}
                                            </span>
                                        </div>

                                        @if($product['product_type'] == 'digital')
                                            <div class="digital-product-authors mb-2">
                                                @if(count($productPublishingHouseInfo['data']) > 0)
                                                    <div class="d-flex align-items-center g-2 me-2">
                                                        <span class="text-capitalize digital-product-author-title">{{ translate('Publishing_House') }} :</span>
                                                        <div class="item-list">
                                                            @foreach($productPublishingHouseInfo['data'] as $publishingHouseName)
                                                                <a href="{{ route('products', ['publishing_house_id' => $publishingHouseName['id'], 'product_type' => 'digital', 'page'=>1]) }}"
                                                                   class="text-base">
                                                                    {{ $publishingHouseName['name'] }}
                                                                </a>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif

                                                @if(count($productAuthorsInfo['data']) > 0)
                                                    <div class="d-flex align-items-center g-2 me-2">
                                                        <span class="text-capitalize digital-product-author-title">{{ translate('Author') }} :</span>
                                                        <div class="item-list">
                                                            @foreach($productAuthorsInfo['data'] as $productAuthor)
                                                                <a href="{{ route('products',['author_id' => $productAuthor['id'], 'product_type' => 'digital', 'page' => 1]) }}"
                                                                   class="text-base">
                                                                    {{ $productAuthor['name'] }}
                                                                </a>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>
                                        @endif

                                        <form class="addToCartDynamicForm add-to-cart-details-form d-flex flex-column gap-4">

                                            <div class="">
                                                <h3 class="font-weight-normal text-accent d-flex align-items-end gap-2 mb-0">
                                                    @if(getProductPriceByType(product: $product, type: 'discount', result: 'value') > 0)
                                                        <del
                                                            class="product-total-unit-price align-middle text-muted fs-18 font-semibold">
                                                            {{ webCurrencyConverter(amount: $product->unit_price) }}
                                                        </del>
                                                    @endif
                                                    <span class="discounted-unit-price fs-24 font-bold">
                                                        {{ getProductPriceByType(product: $product, type: 'discounted_unit_price', result: 'string') }}
                                                    </span>
                                                    @if ($__off > 0)
                                                        <span class="pd-save">{{ translate('you_save') }} {{ $__off }}%</span>
                                                    @endif
                                                </h3>

                                                @if ($__viewers)
                                                    <p class="pd-viewers">
                                                        <i class="fi fi-rr-eye"></i>
                                                        {{ translate('there_are') }} <b>{{ $__viewers }}</b>
                                                        {{ translate('people_viewing_this_product_right_now') }}
                                                    </p>
                                                @endif

                                                @if ($__shortDescription)
                                                    <p class="pd-short">{{ $__shortDescription }}</p>
                                                    <a href="#overview" class="pd-readmore" data-toggle="tab" role="tab">{{ translate('read_more') }}</a>
                                                @endif

                                                {{-- B2B wholesale price: shown to a logged-in customer whose group offers a lower
                                                     price, so wholesale buyers see their price on the page — not only in the cart. --}}
                                     
                                      
                                            </div>

                                            @csrf
                                            <input type="hidden" name="id" value="{{ $product->id }}">
                                            <div
                                                class="position-relative">
                                                @if (count(json_decode($product->colors)) > 0)
                                                    <div class="d-flex align-items-center gap-3 my-0">
                                                        <div class="product-description-label m-0 __color-9B9B9B fs-14">
                                                            {{ translate('color') }}
                                                        </div>
                                                        <div>
                                                            <ul class="list-inline checkbox-color mb-0 p-0 flex-start gap-2">
                                                                @foreach (json_decode($product->colors) as $key => $color)
                                                                    <li>
                                                                        <input type="radio"
                                                                               id="{{ str_replace(' ', '', ($product->id. '-color-'. str_replace('#','',$color))) }}"
                                                                               name="color" value="{{ $color }}"
                                                                               @if($key == 0) checked @endif>
                                                                        <label style="background: {{ $color }};"
                                                                               class="focus-preview-image-by-color shadow-border"
                                                                               for="{{ str_replace(' ', '', ($product->id. '-color-'. str_replace('#','',$color))) }}"
                                                                               data-toggle="tooltip"
                                                                               data-key="{{ str_replace('#','',$color) }}"
                                                                               data-colorid="preview-box-{{ str_replace('#','',$color) }}"
                                                                               data-title="{{ getColorNameByCode(code: $color) }}">
                                                                            <span class="outline"></span></label>
                                                                    </li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>

                                            @php($extensionIndex=0)
                                            @if($product['product_type'] == 'digital' && $product['digital_product_file_types'] && count($product['digital_product_file_types']) > 0 && $product['digital_product_extensions'])
                                                @foreach($product['digital_product_extensions'] as $extensionKey => $extensionGroup)
                                                    <div class="row flex-start mx-0 align-items-center gap-3 flex-nowrap">
                                                        <div
                                                            class="product-description-label __color-9B9B9B fs-14 text-capitalize flex-shrink-0">
                                                            {{ translate($extensionKey) }}
                                                        </div>
                                                        <div>
                                                            @if(count($extensionGroup) > 0)
                                                                <div
                                                                    class="list-inline checkbox-alphanumeric checkbox-alphanumeric--style-1 mb-0 mx-1 flex-start ps-0">
                                                                    @foreach($extensionGroup as $index => $extension)
                                                                        <div class="user-select-none">
                                                                            <div class="for-mobile-capacity">
                                                                                <input type="radio" hidden
                                                                                       id="extension_{{ str_replace(' ', '-', $extension) }}"
                                                                                       name="variant_key"
                                                                                       value="{{ $extensionKey.'-'.preg_replace('/\s+/', '-', $extension) }}"
                                                                                    {{ $extensionIndex == 0 ? 'checked' : ''}}>
                                                                                <label
                                                                                    for="extension_{{ str_replace(' ', '-', $extension) }}"
                                                                                    class="__text-12px">
                                                                                    <span class="text-nowrap max-w-180 line--limit-1">{{ $extension }}</span>
                                                                                </label>
                                                                            </div>
                                                                        </div>
                                                                        @php($extensionIndex++)
                                                                    @endforeach
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </div>
                                                @endforeach
                                            @endif

                                            @foreach (json_decode($product->choice_options) as $key => $choice)
                                                <div class="row flex-start mx-0  gap-3 flex-nowrap">
                                                    <div
                                                        class="product-description-label fs-14 __color-9B9B9B text-capitalize flex-shrink-0">{{ $choice->title }}
                                                    </div>
                                                    <div>
                                                        <div
                                                            class="list-inline checkbox-alphanumeric checkbox-alphanumeric--style-1 mb-0 mx-1 flex-start row ps-0">
                                                            @foreach ($choice->options as $index => $option)
                                                                <div class="user-select-none">
                                                                    <div class="for-mobile-capacity">
                                                                        <input type="radio"
                                                                               id="{{ str_replace(' ', '', ($choice->name. '-'. $option)) }}"
                                                                               name="{{ $choice->name }}" value="{{ $option }}"
                                                                               @if($index == 0) checked @endif >
                                                                        <label class="__text-12px"
                                                                               for="{{ str_replace(' ', '', ($choice->name. '-'. $option)) }}">
                                                                                <span class="text-nowrap max-w-180 line--limit-1">{{ $option }}</span>
                                                                            </label>
                                                                    </div>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach

                                            <div class="">
                                                <div class="product-quantity d-flex flex-column __gap-15">
                                                    <div class="d-flex align-items-center gap-4">
                                                        <div class="product-description-label __color-9B9B9B fs-14">
                                                            {{ translate('qty') }}
                                                        </div>
                                                        <div
                                                            class="d-flex justify-content-between align-items-center quantity-box border rounded border-base web-text-primary w-130px h-40px overflow-hidden">
                                                            <span class="input-group-btn h-100">
                                                                <button class="btn btn-number __p-10 web-text-primary bg-ECF1F6 rounded-0 h-100 w-32px"
                                                                        type="button"
                                                                        data-type="minus" data-field="quantity"
                                                                        disabled="disabled">
                                                                    -
                                                                </button>
                                                            </span>
                                                            <input type="text" name="quantity"
                                                                   class="form-control input-number text-center product-details-cart-qty __inline-29 border-0 w-100 fs-12"
                                                                   placeholder="{{ translate('1') }}"
                                                                   value="{{ $initialProductConfig['quantity'] ?? 1 }}"
                                                                   data-producttype="{{ $product->product_type }}"
                                                                   min="{{ $product->minimum_order_qty ?? 1 }}"
                                                                   max="{{ $product['product_type'] == 'physical' ? $product->current_stock : 100}}">
                                                            <span class="input-group-btn h-100">
                                                                <button class="btn btn-number __p-10 web-text-primary bg-ECF1F6 rounded-0 h-100 w-32px"
                                                                        type="button"
                                                                        data-producttype="{{ $product->product_type }}"
                                                                        data-type="plus" data-field="quantity">
                                                                        +
                                                                </button>
                                                            </span>
                                                        </div>
                                                        <input type="hidden" class="product-generated-variation-code"
                                                               name="product_variation_code"
                                                               data-product-id="{{ $product['id'] }}">
                                                        <input type="hidden" value=""
                                                               class="product-exist-in-cart-list form-control w-50" name="key">
                                                    </div>
                                                    <div class="product-details-chosen-price-section">
                                                        <div
                                                            class="d-none d-sm-flex justify-content-start align-items-center me-2">
                                                            <div
                                                                class="product-description-label fs-20 text-dark font-bold text-capitalize">
                                                                <strong>{{ translate('total_price') }}</strong>:
                                                            </div>
                                                            &nbsp; <strong
                                                                class="text-base product-details-chosen-price-amount fs-20">
                                                                {{ webCurrencyConverter(amount: $initialProductConfig['total_quantity_price']) }}
                                                            </strong>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="__btn-grp product-add-and-buy-section-parent">

                                                <?php


                                                $isTemporaryClose = false;
                                                $isVacationMode = false;
                                                $authorType = $product->added_by == 'admin' ? 'inhouse' : ($product->added_by == 'seller' ? 'vendor' : '');

                                                if ($authorType === 'vendor') {
                                                    $isTemporaryClose = checkVendorAbility(type: $authorType, status: 'temporary_close', vendor: $product->seller->shop);
                                                    $isVacationMode = !$isTemporaryClose ? checkVendorAbility(type: $authorType, status: 'vacation_status', vendor: $product->seller->shop) : false;
                                                } else {
                                                    $isTemporaryClose = checkVendorAbility(type: $authorType, status: 'temporary_close');
                                                    $isVacationMode = !$isTemporaryClose ? checkVendorAbility(type: $authorType, status: 'vacation_status') : false;
                                                }
                                                ?>

                                                <div
                                                    class="product-add-and-buy-section gap-2 {!! $firstVariationQuantity <= 0 ? '' : 'd-flex' !!}"
                                                    {!! $firstVariationQuantity <= 0 ? 'style="display: none;"' : '' !!}>

                                                    @if($isTemporaryClose || $isVacationMode)
                                                        <button class="btn btn-secondary" type="button" disabled>
                                                            {{ translate('buy_now') }}
                                                        </button>
                                                        <button class="btn btn--primary string-limit" type="button" disabled>
                                                            {{ translate('add_to_cart') }}
                                                        </button>
                                                    @else
                                                        <button type="button"
                                                                class="btn btn-secondary element-center btn-gap-{{ Session::get('direction') === 'rtl' ? 'left' : 'right' }} product-buy-now-button"
                                                                data-form=".add-to-cart-details-form"
                                                                data-auth="{{ (getWebConfig(name: 'guest_checkout') == 1 || Auth::guard('customer')->check()) ? 'true' : 'false' }}"
                                                                data-route="{{ route('shop-cart') }}"
                                                        >
                                                            <span class="string-limit">{{ translate('buy_now') }}</span>
                                                        </button>
                                                        <button
                                                            class="btn btn--primary element-center product-add-to-cart-button"
                                                            type="button"
                                                            data-form=".add-to-cart-details-form"
                                                            data-update="{{ translate('update_cart') }}"
                                                            data-add="{{ translate('add_to_cart') }}"
                                                        >
                                                            <span class="string-limit">
                                                                {{ $initialProductConfig['first_variant_in_cart'] ? translate('update_cart') : translate('add_to_cart') }}
                                                            </span>
                                                        </button>
                                                    @endif
                                                </div>

                                                @if(($product['product_type'] == 'physical'))
                                                    <div
                                                        class="product-restock-request-section collapse" {!! $firstVariationQuantity <= 0 ? 'style="display: block;"' : '' !!}>
                                                        <button type="button"
                                                                class="btn request-restock-btn btn-outline-primary fw-semibold product-restock-request-button"
                                                                data-auth="{{ auth('customer')->check() }}"
                                                                data-form=".addToCartDynamicForm"
                                                                data-default="{{ translate('Request_Restock') }}"
                                                                data-requested="{{ translate('Request_Sent') }}"
                                                        >
                                                            {{ translate('Request_Restock') }}
                                                        </button>
                                                    </div>
                                                @endif
                                                <button type="button" data-product-id="{{ $product['id'] }}"
                                                        class="btn __text-18px border product-action-add-wishlist">
                                                    <i class="fa {{($wishlistStatus == 1?'fa-heart':'fa-heart-o') }} wishlist_icon_{{ $product['id'] }} web-text-primary"
                                                       aria-hidden="true"></i>
                                                    <span
                                                        class="fs-14 text-muted align-bottom countWishlist-{{ $product['id'] }}">{{ $countWishlist}}</span>
                                                    <div class="wishlist-tooltip" x-placement="top">
                                                        <div class="arrow"></div>
                                                        <div class="inner">
                                                            <span class="add">{{ translate('added_to_wishlist') }}</span>
                                                            <span class="remove">{{ translate('removed_from_wishlist') }}</span>
                                                        </div>
                                                    </div>
                                                </button>

                                                @if($product->added_by == 'admin')
                                                    @if(checkVendorAbility(type: 'inhouse', status: 'temporary_close') || checkVendorAbility(type: 'inhouse', status: 'vacation_status'))
                                                        <div class="alert alert-danger mt-2" role="alert">
                                                            {{ translate('this_shop_is_temporary_closed_or_on_vacation._You_cannot_add_product_to_cart_from_this_shop_for_now') }}
                                                        </div>
                                                    @endif
                                                @elseif($product->added_by == 'seller')
                                                    @if(checkVendorAbility(type: 'vendor', status: 'temporary_close', vendor: $product->seller->shop) || checkVendorAbility(type: 'vendor', status: 'vacation_status', vendor: $product->seller->shop))
                                                        <div class="alert alert-danger mt-2" role="alert">
                                                            {{ translate('this_shop_is_temporary_closed_or_on_vacation._You_cannot_add_product_to_cart_from_this_shop_for_now') }}
                                                        </div>
                                                    @endif
                                                @endif
                                            </div>

                                        </form>

                                        {{-- Companions for this product, right under the buy buttons where the
                                             decision is being made. --}}
                                        @include('web-views.products._bought-together', ['product' => $product])
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="rtl text-align-direction flex-grow-1">
                            <div class="__review-overview card card-body h-100">
                               <ul class="nav nav-tabs nav--tabs d-flex justify-content-center gap-2" role="tablist">
                                    <li class="nav-item">
                                        <a class="nav-link __inline-27 mb-0 tab_link active"
                                        data-toggle="tab"
                                        href="#overview"
                                        role="tab">
                                            {{ translate('overview') }}
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link __inline-27 mb-0 tab_link"
                                        data-toggle="tab"
                                        href="#reviews"
                                        role="tab">
                                            {{ translate('reviews') }}
                                        </a>
                                    </li>
                                </ul>


                                <div class="tab-content px-lg-3">
                                    <div class="tab-pane fade show active text-justify" id="overview" role="tabpanel">
                                        <div class="details-content-wrap ov-hidden show-more--content">
                                            <div class="row pt-2 specification">

                                                @if($product->video_url != null && (str_contains($product->video_url, "youtube.com/embed/")))
                                                    <div class="col-12 col-md-8 col-lg-6 mb-4">
                                                        <div class="embed-responsive embed-responsive-16by9 rounded">
                                                            <iframe class="embed-responsive-item" src="{{ $product->video_url }}"
                                                                    title="YouTube video player" frameborder="0"
                                                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                                                    referrerpolicy="strict-origin-when-cross-origin"
                                                                    allowfullscreen></iframe>
                                                        </div>
                                                    </div>
                                                @endif
                                                @if ($product['details'])
                                                    <h4 class="fs-16 fw-bold mb-3 px-1">{{ translate('Detail_Description') }}</h4>
                                                    <div
                                                        class="p-details-description text-body col-lg-12 col-md-12 fs-14 text-justify details-text-justify rich-editor-html-content" style="max-height: 525px">
                                                        {!! $product['details'] !!}
                                                    </div>
                                                @endif
                                            </div>
                                            @if (!$product['details'] && ($product->video_url == null || !(str_contains($product->video_url, "youtube.com/embed/"))))
                                                <div>
                                                    <div class="text-center text-capitalize py-5">
                                                        <img class="mw-90"
                                                             src="{{ theme_asset(path: 'public/assets/front-end/img/icons/nodata.svg') }}"
                                                             alt="">
                                                        <p class="text-capitalize mt-2">
                                                            <small>
                                                                {{ translate('product_details_not_found').'!' }}
                                                            </small>
                                                        </p>
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                        <div class="d-flex justify-content-center mt-2">
                                            <button class="btn btn-outline-primary text-capitalize px-4 py-2 see-more-details">{{ translate('see_more') }}</button>
                                        </div>
                                    </div>

                                    <div class="tab-pane fade" id="reviews" role="tabpanel">
                                        <div class="">
                                            @if(count($product->reviews)==0 && $productReviews->total() == 0)
                                                <div>
                                                    <div class="text-center text-capitalize">
                                                        <img class="mw-100"
                                                             src="{{ theme_asset(path: 'public/assets/front-end/img/icons/empty-review.svg') }}"
                                                             alt="">
                                                        <p class="text-capitalize">
                                                            <small>{{ translate('No_review_given_yet') }}!</small>
                                                        </p>
                                                    </div>
                                                </div>
                                            @else
                                                <div class="row pt-2 pb-3">
                                                    <div class="col-lg-4 col-md-5 ">
                                                        <div
                                                            class=" row d-flex justify-content-center align-items-center">
                                                            <div
                                                                class="col-12 d-flex justify-content-center align-items-center">
                                                                <h2 class="overall_review mb-2 __inline-28">
                                                                    {{ $overallRating[0] }}
                                                                </h2>
                                                            </div>
                                                            <div
                                                                class="d-flex justify-content-center align-items-center star-rating ">
                                                                @for($inc=1;$inc<=5;$inc++)
                                                                    @if ($inc <= (int)$overallRating[0])
                                                                        <i class="tio-star text-warning"></i>
                                                                    @elseif ($overallRating[0] != 0 && $inc <= (int)$overallRating[0] + 1.1 && $overallRating[0] > ((int)$overallRating[0]))
                                                                        <i class="tio-star-half text-warning"></i>
                                                                    @else
                                                                        <i class="tio-star-outlined text-warning"></i>
                                                                    @endif
                                                                @endfor
                                                            </div>
                                                            <div
                                                                class="col-12 d-flex justify-content-center align-items-center mt-2">
                                                                <span class="text-center">
                                                                    {{ $productReviews->total() }} {{ translate('ratings') }}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-lg-8 col-md-7 pt-sm-3 pt-md-0">
                                                        <div
                                                            class="d-flex align-items-center mb-2 font-size-sm">
                                                            <div
                                                                class="__rev-txt"><span
                                                                    class="d-inline-block align-middle text-body">{{ translate('excellent') }}</span>
                                                            </div>
                                                            <div class="w-0 flex-grow">
                                                                <div class="progress text-body __h-5px">
                                                                    <div class="progress-bar web--bg-primary"
                                                                         role="progressbar"
                                                                         style="width: <?php echo $widthRating = ($rating[0] != 0) ? ($rating[0] / $overallRating[1]) * 100 : (0); ?>%;"
                                                                         aria-valuenow="60" aria-valuemin="0"
                                                                         aria-valuemax="100"></div>
                                                                </div>
                                                            </div>
                                                            <div class="col-1 text-body">
                                                                <span
                                                                    class=" {{Session::get('direction') === "rtl" ? 'me-3 float-left' : 'ml-3 float-right'}} ">
                                                                    {{ $rating[0] }}
                                                                </span>
                                                            </div>
                                                        </div>

                                                        <div
                                                            class="d-flex align-items-center mb-2 text-body font-size-sm">
                                                            <div
                                                                class="__rev-txt"><span
                                                                    class="d-inline-block align-middle ">{{ translate('good') }}</span>
                                                            </div>
                                                            <div class="w-0 flex-grow">
                                                                <div class="progress __h-5px">
                                                                    <div class="progress-bar web--bg-primary" role="progressbar"
                                                                         style="width: <?php echo $widthRating = ($rating[1] != 0) ? ($rating[1] / $overallRating[1]) * 100 : (0); ?>%; background-color: #a7e453;"
                                                                         aria-valuenow="27" aria-valuemin="0"
                                                                         aria-valuemax="100"></div>
                                                                </div>
                                                            </div>
                                                            <div class="col-1">
                                                                <span
                                                                    class="{{Session::get('direction') === "rtl" ? 'me-3 float-left' : 'ml-3 float-right'}}">
                                                                        {{ $rating[1] }}
                                                                </span>
                                                            </div>
                                                        </div>

                                                        <div
                                                            class="d-flex align-items-center mb-2 text-body font-size-sm">
                                                            <div
                                                                class="__rev-txt"><span
                                                                    class="d-inline-block align-middle ">{{ translate('average') }}</span>
                                                            </div>
                                                            <div class="w-0 flex-grow">
                                                                <div class="progress __h-5px">
                                                                    <div class="progress-bar web--bg-primary" role="progressbar"
                                                                         style="width: <?php echo $widthRating = ($rating[2] != 0) ? ($rating[2] / $overallRating[1]) * 100 : (0); ?>%; background-color: #ffda75;"
                                                                         aria-valuenow="17" aria-valuemin="0"
                                                                         aria-valuemax="100"></div>
                                                                </div>
                                                            </div>
                                                            <div class="col-1">
                                                                <span
                                                                    class="{{Session::get('direction') === "rtl" ? 'me-3 float-left' : 'ml-3 float-right'}}">
                                                                    {{ $rating[2] }}
                                                                </span>
                                                            </div>
                                                        </div>

                                                        <div
                                                            class="d-flex align-items-center mb-2 text-body font-size-sm">
                                                            <div
                                                                class="__rev-txt "><span
                                                                    class="d-inline-block align-middle">{{ translate('below_Average') }}</span>
                                                            </div>
                                                            <div class="w-0 flex-grow">
                                                                <div class="progress __h-5px">
                                                                    <div class="progress-bar web--bg-primary" role="progressbar"
                                                                         style="width: <?php echo $widthRating = ($rating[3] != 0) ? ($rating[3] / $overallRating[1]) * 100 : (0); ?>%; background-color: #fea569;"
                                                                         aria-valuenow="9" aria-valuemin="0"
                                                                         aria-valuemax="100"></div>
                                                                </div>
                                                            </div>
                                                            <div class="col-1">
                                                                <span
                                                                    class="{{Session::get('direction') === "rtl" ? 'me-3 float-left' : 'ml-3 float-right'}}">
                                                                    {{ $rating[3] }}
                                                                </span>
                                                            </div>
                                                        </div>

                                                        <div
                                                            class="d-flex align-items-center text-body font-size-sm">
                                                            <div
                                                                class="__rev-txt"><span
                                                                    class="d-inline-block align-middle ">{{ translate('poor') }}</span>
                                                            </div>
                                                            <div class="w-0 flex-grow">
                                                                <div class="progress __h-5px">
                                                                    <div class="progress-bar web--bg-primary" role="progressbar"
                                                                         style="width: <?php echo $widthRating = ($rating[4] != 0) ? ($rating[4] / $overallRating[1]) * 100 : (0); ?>%;"
                                                                         aria-valuenow="4" aria-valuemin="0"
                                                                         aria-valuemax="100"></div>
                                                                </div>
                                                            </div>
                                                            <div class="col-1">
                                                                <span
                                                                    class="{{Session::get('direction') === "rtl" ? 'me-3 float-left' : 'ml-3 float-right'}}">
                                                                        {{ $rating[4] }}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="row pb-4 mb-3">
                                                    <div class="__inline-30">
                                                        <span
                                                            class="text-capitalize">{{ translate('Product_review') }}</span>
                                                    </div>
                                                </div>
                                            @endif

                                            <div class="row pb-4">
                                                <div class="col-12" id="product-review-list">
                                                    @include('web-views.partials._product-reviews')
                                                </div>

                                                @if(count($product->reviews) > 2)
                                                    <div class="col-12">
                                                        <div
                                                            class="card-footer d-flex justify-content-center align-items-center">
                                                            <button class="btn text-white view_more_button web--bg-primary">
                                                                {{ translate('view_more') }}
                                                            </button>
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3">
                    @php($companyReliability = getWebConfig('company_reliability'))
                    @if($companyReliability != null)
                        <div class="product-details-shipping-details">
                            @foreach ($companyReliability as $key=>$value)
                                @if ($value['status'] == 1 && !empty($value['title']))
                                    <div class="shipping-details-bottom-border">
                                        <div class="px-3 py-3">
                                            <img
                                                class="{{Session::get('direction') === "rtl" ? 'float-right ml-2' : 'mr-2'}} __img-20"
                                                src="{{ getStorageImages(path: imagePathProcessing(imageData: $value['image'],path: 'company-reliability'), type: 'source', source: 'public/assets/front-end/img'.'/'.$value['item'].'.png') }}"
                                                alt="">
                                            <span>{{ translate($value['title']) }}</span>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    @if(getWebConfig(name: 'business_mode')=='multi')
                        <div class="__inline-31">

                            @if($product->added_by=='seller')
                                @if(isset($product->seller->shop))
                                    <div class="row position-relative">
                                        <div class="col-12 position-relative">
                                            <a href="{{route('vendor-shop',['slug'=> $product?->seller?->shop->slug]) }}"
                                               class="d-block">
                                                <div class="d-flex __seller-author align-items-center">
                                                    <div>
                                                        <img class="__img-60 img-circle" alt=""
                                                             src="{{ getStorageImages(path: $product?->seller?->shop->image_full_url, type: 'shop') }}">
                                                    </div>
                                                    <div
                                                        class="ms-2 w-0 flex-grow">
                                                        <h2 class="fs-15 mb-2">
                                                            {{ $product->seller->shop->name }}
                                                        </h2>
                                                        @if(checkVendorAbility(type: 'vendor', status: 'temporary_close', vendor: $product->seller->shop))
                                                            <span class="badge-danger fs-12 px-3 rounded-full small">
                                                                <span>{{ translate('Temporary_OFF') }}</span>
                                                            </span>
                                                        @elseif(checkVendorAbility(type: 'vendor', status: 'vacation_status', vendor: $product->seller->shop))
                                                            <span class="badge-danger fs-12 px-3 rounded-full small">
                                                                <span>{{ translate('closed_Now') }}</span>
                                                            </span>
                                                        @endif
                                                    </div>
                                                </div>
                                            </a>
                                        </div>
                                        @include('web-views.products.partials._seller-stats-chat', [
                                            'chatDisabled' => checkVendorAbility(type: 'vendor', status: 'temporary_close', vendor: $product->seller->shop),
                                        ])
                                    </div>
                                @endif
                            @else
                                <div class="row position-relative d-flex justify-content-between">
                                    <div class="col-9">
                                        <a href="{{route('vendor-shop',['slug' => getInHouseShopConfig(key:'slug')]) }}" class="row d-flex align-items-center">
                                            <div>
                                                <img class="__inline-32" alt=""
                                                     src="{{ getStorageImages(path: getInHouseShopConfig(key:'image_full_url'), type: 'shop') }}">
                                            </div>
                                            <div>
                                                <div
                                                    class="{{ session('direction') === "rtl" ? 'right' : 'ml-2' }} get-view-by-onclick"
                                                    data-link="{{ route('vendor-shop',['slug' => getInHouseShopConfig(key:'slug')]) }}">
                                                    <h2 class="font-bold __text-16px mb-1">
                                                        {{ getInHouseShopConfig(key: 'name') }}
                                                    </h2>
                                                    @if(checkVendorAbility(type: 'inhouse', status: 'temporary_close'))
                                                        <span class="badge-danger fs-12 px-3 rounded-full small">
                                                            <span>{{ translate('Temporary_OFF') }}</span>
                                                        </span>
                                                    @elseif(checkVendorAbility(type: 'inhouse', status: 'vacation_status'))
                                                        <span class="badge-danger fs-12 px-3 rounded-full small">
                                                            <span>{{ translate('closed_Now') }}</span>
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="fs-12 {{ session('direction') === "rtl" ? 'right' : 'ml-2' }}">{{ translate('Seller_info') }}</div>
                                            </div>
                                        </a>
                                    </div>

                                    @include('web-views.products.partials._seller-stats-chat', [
                                        'chatDisabled' => checkVendorAbility(type: 'inhouse', status: 'temporary_close'),
                                    ])
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="pt-4 pb-3 d-flex justify-content-between align-items-center">
                        <h2 class="__text-16px font-bold text-capitalize mb-0">
                            @if(getWebConfig(name: 'business_mode') == 'multi')
                                {{ translate('more_from_the_store') }}
                            @else
                                {{ translate('you_may_also_like') }}
                            @endif
                        </h2>
                        @if($product->added_by == 'seller' && isset($product->seller->shop))
                        <a class="text-capitalize view-all-btn-text" href="{{ route('vendor-shop',['slug'=> $product->seller->shop->slug]) }}">
                            <span class="view-btn-text">{{ translate('View_All') }}</span>
                            <i class="czi-arrow-{{ session('direction') === "rtl" ? 'left' : 'right' }}"></i>
                        </a>
                        @else
                            <a class="text-capitalize view-all-btn-text" href="{{route('vendor-shop', ['slug'=> getInHouseShopConfig(key: 'slug')]) }}">
                                <span class="view-btn-text">{{ translate('View_All') }}</span>
                                <i class="czi-arrow-{{ session('direction') === "rtl" ? 'left' : 'right' }}"></i>
                            </a>
                        @endif
                    </div>
                    <div>
                        @foreach($moreProductFromSeller as $item)
                            @include('web-views.partials._seller-products-product-details',['product' => $item, 'decimal_point_settings' => $decimalPointSettings])
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        @if (count($relatedProducts)>0)
            <div class="container rtl text-align-direction">
                <div class="card __card border-0 mb-4">
                    <div class="card-body">
                        <div class="row flex-between align-items-center mb-2">
                            <div class="ms-1">
                                <h2 class="text-capitalize font-bold fs-16 mb-0">{{ translate('similar_products') }}</h2>
                            </div>
                            <div class="view_all d-flex justify-content-center align-items-center">
                                <div>
                                    @if(!empty($product?->category?->slug))
                                        <a class="text-capitalize view-all-btn-text me-1"
                                           href="{{ route('category-products', ['slug' => $product?->category?->slug]) }}">
                                            <span class="view-btn-text">{{ translate('View_All') }}</span>
                                            <i class="czi-arrow-{{ session('direction') === "rtl" ? 'left' : 'right' }}"></i>
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            @foreach($relatedProducts as $key => $relatedProduct)
                                <div class="col-xl-2 col-sm-3 col-6">
                                    @include('web-views.partials._inline-single-product-without-eye',['product'=>$relatedProduct,'decimal_point_settings'=>$decimalPointSettings])
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div class="modal fade rtl text-align-direction" id="show-modal-view" tabindex="-1" role="dialog"
             aria-labelledby="show-modal-image"
             aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-body flex justify-content-center">
                        <button class="btn btn-default __inline-33 dir-end-minus-7px"
                                data-dismiss="modal">
                            <i class="fa fa-close"></i>
                        </button>
                        <img class="element-center" id="attachment-view" src="" alt="">
                    </div>
                </div>
            </div>
        </div>

    </div>

    @include("web-views.products._product-details-sticky", ['productDetails' => $product])

    @if($product?->preview_file_full_url['path'])
        @include('web-views.partials._product-preview-modal', ['previewFileInfo' => $previewFileInfo])
    @endif

    @include('layouts.front-end.partials.modal._chatting',['seller'=>$product->seller, 'user_type'=>$product->added_by])

    <span id="route-review-list-product" data-url="{{ route('review-list-product') }}"></span>
    <span id="products-details-page-data" data-id="{{ $product['id'] }}"></span>
    <span id="all-msg-container" data-afterextend="{{translate('see_less')}}" data-seemore="{{translate('see_more')}}"></span>
@endsection

@push('script')
    <script>
        $(document).on('change', 'input[name="color"]', function() {
            const selectedColor = $(this).val();
            const colors = @json(json_decode($product->colors));
            const colorIndex = colors.indexOf(selectedColor);
            // Color-indexed slides only exist while the gallery is in
            // color-image mode; guard so a plain gallery never throws.
            const mainSwiper = document.querySelector('.quickviewSlider2')?.swiper;
            const thumbSwiper = document.querySelector('.quickviewSliderThumb2')?.swiper;
            if (colorIndex !== -1 && mainSwiper && thumbSwiper) {
                mainSwiper.slideTo(colorIndex);
                thumbSwiper.slideTo(colorIndex);
            }
        });
    </script>
    <script src="{{ theme_asset(path: 'public/assets/front-end/js/product-details.js') }}"></script>
    @php($shareThisPropertyId = getWebConfig(name: 'sharethis_property_id'))
    @if($shareThisPropertyId)
        {{-- Loaded only when the store configured its own ShareThis property —
             the stock template shipped the demo property id, sending every
             store's share analytics to the demo account. --}}
        <script type="text/javascript" async="async"
                src="https://platform-api.sharethis.com/js/sharethis.js#property={{ $shareThisPropertyId }}&product=sticky-share-buttons"></script>
    @endif

    <script>
        $(document).ready(function () {

            function applyShowMoreLogic() {
                $(".tab-pane.active").each(function () {
                    let content = $(this).find(".details-content-wrap");
                    let button  = $(this).find(".see-more-details");

                    if (!content.length || !button.length) return;

                    if (content[0].scrollHeight > 280) {
                        content.addClass(" active");
                        button.show();
                    } else {
                        content.removeClass(" active");
                        button.hide();
                    }
                });
            }

            applyShowMoreLogic();

            $(document).on("shown.bs.tab", ".tab_link", function () {
                applyShowMoreLogic();
            });

            $(document).on("click", ".see-more-details", function () {
                let tabPane = $(this).closest(".tab-pane");
                let content = tabPane.find(".details-content-wrap");
                let productDetailsDescription = tabPane.find(".p-details-description");

                content.toggleClass(" active");

                if (content.hasClass("active")) {
                    $(this).html($("#all-msg-container").data("seemore"));
                    productDetailsDescription.removeClass('overflow-y-auto');
                } else {
                    $(this).html($("#all-msg-container").data("afterextend"));
                    productDetailsDescription.addClass('overflow-y-auto');
                }
            });

        });
    </script>
@endpush
