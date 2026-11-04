@extends('layouts.admin.app')

@section('title', translate('Product_Gallery'))

@section('content')
    <div class="content container-fluid">
        <div>
            <div class="d-flex flex-wrap gap-3 align-items-center justify-content-end mb-20">
                <h2 class="h1 mb-0 d-flex gap-2 align-items-center flex-grow-1">
                    {{ translate('Product_Gallery') }}
                    <span
                        class="badge text-dark bg-body-secondary fw-semibold rounded-50">{{ $products->total() }}</span>
                </h2>
                <div class="d-flex gap-3 align-items-center gallery-search-filter">
                    <div class="flex-grow-1 min-w-300 min-w-100-mobile">
                        <form action="{{ route('admin.products.product-gallery') }}">
                            <div class="input-group">
                                <input type="hidden" name="brand_id" value="{{ requestString('brand_id') }}">
                                <input type="hidden" name="category_id" value="{{ requestString('category_id') }}">
                                <input type="hidden" name="vendor_id" value="{{ requestString('vendor_id') }}">
                                <input type="search" name="searchValue" class="form-control"
                                       placeholder="{{ translate('search_by_product_name') }}"
                                       aria-label="Search orders" value="{{ requestString('searchValue') }}">
                                <div class="input-group-append search-submit">
                                    <button type="submit">
                                        <i class="fi fi-rr-search"></i>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="position-relative">
                        <buton type="btn"
                               @if(!empty(request('filter_sort_by')) || !empty(request('filter_product_types')) || !empty(request('product_status')) || !empty(request('filter_shop_ids')) || !empty(request('filter_brand_ids')) || !empty(request('filter_category_ids')))
                                   class="btn btn-primary px-4"
                               @else
                                   class="btn btn-outline-primary px-4"
                               @endif
                               data-bs-toggle="offcanvas" data-bs-target="#offcanvasProductGalleryFilter">
                            <i class="fi fi-sr-settings-sliders d-flex"></i> {{ translate('Filter') }}
                        </buton>
                        @if(!empty(request('filter_sort_by')) || !empty(request('filter_product_types')) || !empty(request('product_status')) || !empty(request('filter_shop_ids')) || !empty(request('filter_brand_ids')) || !empty(request('filter_category_ids')))
                            <div
                                class="position-absolute top-n1 inset-inline-end-n1 btn-circle bg-danger border border-white border-2"
                                style="--size: 12px;"></div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 rounded d-flex gap-2 align-items-center mb-20">
                <i class="fi fi-sr-lightbulb-on text-info"></i>
                <span>{{ translate('you_can_use_any_product_information_to_create_a_new_product.') }}</span>
            </div>
            <div class="row g-3">
                @foreach ($products as $product)
                    <div class="col-xl-6">
                        <div class="card card-body p-3 h-100 product-gallery-item"
                             id="product-gallery-item-{{ $product->id }}">
                            <div class="d-flex gap-4 gap-sm-3 flex-column flex-sm-row">
                                <div class="d-flex flex-column w-130 min-w-100-mobile">
                                    <div class="pd-img-wrap position-relative">
                                        <div class="w-100 d-flex">
                                            <div
                                                class="quickviewSlider2 swiper-container border rounded aspect-1 inline-size-100 max-w-130 mx-auto position-relative"
                                                id="quickviewSlider2-{{ $product->id }}">
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
                                                        <div
                                                            class="swiper-slide position-relative rounded border aspect-1">
                                                            <div class="easyzoom easyzoom--overlay is-ready">
                                                                <a href="{{ $imagePath }}">
                                                                    <img class="h-100 aspect-1 rounded min-w-130" alt=""
                                                                         src="{{ $imagePath }}">
                                                                </a>
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mt-3 user-select-none">
                                            <div
                                                class="quickviewSliderThumb2 swiper-container position-relative active-border"
                                                id="quickviewSliderThumb2-{{ $product->id }}">
                                                <div class="swiper-wrapper auto-item-width justify-content-start">
                                                    @foreach ($imageSources as $key => $photo)
                                                        @php
                                                            $imagePath = isset($photo['image_name'])
                                                                ? getStorageImages(path: $photo['image_name'], type: 'backend-product')
                                                                : getStorageImages(path: $photo, type: 'backend-product');
                                                        @endphp
                                                        <div
                                                            class="swiper-slide position-relative rounded border aspect-1"
                                                            role="group" style="--size: 40px;">
                                                            <img class="aspect-1" alt="" src="{{ $imagePath }}">
                                                        </div>
                                                    @endforeach
                                                </div>

                                                <div class="swiper-button-next"
                                                     id="swiper-quickview-button-next-{{ $product->id }}"
                                                     style="--size: 20px;"></div>
                                                <div class="swiper-button-prev"
                                                     id="swiper-quickview-button-prev-{{ $product->id }}"
                                                     style="--size: 20px;"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex flex-column justify-content-between gap-3">
                                    <div>
                                        <h3 class="text-capitalize mb-3 line-1">{{ $product['name'] }}</h3>
                                        <h4 class="text-capitalize mb-2">{{ translate('General_Information') }}</h4>
                                        <table class="fs-12">
                                            <tbody>
                                            <tr>
                                                <td class="text-nowrap">{{ translate('Product_Type') }}</td>
                                                <td class="px-2">:</td>
                                                <td class="text-dark fw-medium overflow-wrap-anywhere">
                                                    {{ translate($product->product_type) }}
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="text-nowrap">{{ translate('Category') }}</td>
                                                <td class="px-2">:</td>
                                                <td class="text-dark fw-medium overflow-wrap-anywhere">
                                                    {{ isset($product->category) ? $product->category->default_name : translate('category_not_available') }}
                                                </td>
                                            </tr>

                                            @if (!empty($product['variation']) && count(json_decode($product['variation'])) > 0)
                                                <tr>
                                                    <td class="text-nowrap">{{ translate('Variation') }}</td>
                                                    <td class="px-2">:</td>
                                                    <td class="text-dark fw-medium overflow-wrap-anywhere">{{count(json_decode($product['variation']))}}</td>
                                                </tr>
                                            @endif
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="d-flex gap-2 gap-sm-3 align-items-center">
                                        <button type="button" class="btn btn-outline-primary fs-12-mobile px-3"
                                                data-bs-toggle="offcanvas"
                                                data-bs-target="#offcanvasProductGalleryDetails{{ $product['id'] }}"
                                        >
                                            {{ translate('View_Details') }}
                                        </button>
                                        <a class="btn btn-primary fs-12-mobile px-3"
                                           href="{{ route('admin.products.update', ['id' => $product['id'], 'product-gallery' => 1]) }}">
                                            {{ translate('use_this_product_info') }}
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
                @if (count($products) <= 0)
                    <div class="col-12">
                        <div class="card card-body">
                            @include(
                                'layouts.admin.partials._empty-state',
                                ['text' => 'no_product_found'],
                                ['image' => 'default']
                            )
                        </div>
                    </div>
                @endif
            </div>
            <div class="mt-4">
                <div class="px-4 d-flex justify-content-lg-end">
                    {{ $products->links() }}
                </div>
            </div>
        </div>
    </div>

    @include('admin-views.product.partials.offcanvas._product-gallery-filter-offcanvas')

    @foreach ($products as $product)
        @include('admin-views.product.partials.offcanvas._product-gallery-details-offcanvas', ['product' => $product])
    @endforeach

    <span id="get-product-gallery-route" data-action="{{ route('admin.products.product-gallery') }}"
          data-brand-id="{{ requestString('brand_id') }}" data-category-id="{{ requestString('category_id') }}"
          data-vendor-id="{{ requestString('vendor_id') }}">
    </span>
@endsection

@push('script')
    <script>
        $(document).ready(function () {
            'use strict';

            $('.product-gallery-item').each(function () {
                let $galleryItem = $(this);
                let productId = $galleryItem.attr('id').split('-').pop();

                $galleryItem.find(".easyzoom").each(function () {
                    $(this).easyZoom();
                });

                if (document.querySelector("#quickviewSlider2-" + productId) && document.querySelector("#quickviewSliderThumb2-" + productId)) {
                    let thumbsSwiper = new Swiper("#quickviewSliderThumb2-" + productId, {
                        spaceBetween: 10,
                        slidesPerView: 'auto',
                        watchSlidesProgress: true,
                        navigation: {
                            nextEl: "#swiper-quickview-button-next-" + productId,
                            prevEl: "#swiper-quickview-button-prev-" + productId,
                        },
                    });

                    new Swiper("#quickviewSlider2-" + productId, {
                        slidesPerView: 1,
                        spaceBetween: 5,
                        loop: false,
                        thumbs: {swiper: thumbsSwiper},
                    });
                }

                if (document.querySelector("#offcanvasQuickviewSlider2-" + productId) && document.querySelector("#offcanvasQuickviewSliderThumb2-" + productId)) {
                    let offcanvasThumbsSwiper = new Swiper("#offcanvasQuickviewSliderThumb2-" + productId, {
                        spaceBetween: 10,
                        slidesPerView: 'auto',
                        watchSlidesProgress: true,
                        navigation: {
                            nextEl: "#offcanvas-swiper-button-next-" + productId,
                            prevEl: "#offcanvas-swiper-button-prev-" + productId,
                        },
                    });

                    new Swiper("#offcanvasQuickviewSlider2-" + productId, {
                        slidesPerView: 1,
                        spaceBetween: 5,
                        loop: false,
                        thumbs: {swiper: offcanvasThumbsSwiper},
                    });
                }
            });
        });

        $(document).on("click", ".collapse-tag-count", function () {
            let container = $(this).closest(".collapse-tag-div");

            container.find(".extra-tag").removeClass("d-none");
            $(this).hide();
        });

    </script>

@endpush
