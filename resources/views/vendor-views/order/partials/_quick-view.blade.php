<div class="modal-body">
   <div class="position-absolute top-0 inset-inline-end-0 p-3 z-2">
        <button type="button" class="btn lh-1 border-0 btn-circle bg-section2 shadow-none" data-dismiss="modal" aria-label="Close">
            <i class="fi fi-sr-cross d-flex"></i>
        </button>
    </div>
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

                @if ($product->barcode)
                    <div class="d-flex align-items-center gap-1 flex-grow-1">
                        <div class="fw-semibold text-dark">{{ translate('barcode') }}:</div>
                        <div class="fs-12 direction-ltr">{{ $product->barcode }}</div>
                    </div>
                @endif

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
        <div class="col-lg-7" id="product_quick_view_details">
            @include("vendor-views.order.partials._quick-view-details", ['product' => $product])
        </div>
    </div>
</div>
