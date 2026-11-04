@extends('layouts.vendor.app')

@section('title', translate('product_Preview'))

@section('content')
    <div class="content container-fluid text-start">
        <div class="d-flex align-items-stretch justify-content-end flex-wrap gap-3 mb-3">
            <div class="flex-grow-1">
                <h2 class="h1 text-capitalize mb-1">
                    {{ translate('product_details') }}
                </h2>
                <p class="mb-0">
                    {{ translate('Created_At') }} {{ date('d M, Y', strtotime($product['created_at'])) }}
                </p>
            </div>
            <div class="d-flex align-items-stretch justify-content-end flex-wrap gap-3">
                @if ($product['added_by'] == 'seller' && $product['request_status'] == 2)
                    <div
                        class="alert-danger text-body fw-normal fs-12 px-2 py-1 rounded d-flex justify-content-center align-items-center max-w-500 single-line-text-break">
                    <span>
                        <span class="text-danger"> {{ translate('Denied_Note') . ':' }}</span>
                        {{ translate($product['denied_note']) }}
                    </span>
                    </div>
                @endif

                <button type="button" class="btn border text-danger bg-white icon-btn delete-data" style="--size: 40px;"
                        title="{{ translate('delete') }}" data-id="product-{{ $product['id']}}">
                    <i class="fi fi-rr-trash d-flex"></i>
                </button>
                <form action="{{ route('vendor.products.delete',[$product['id']]) }}"
                      method="post" id="product-{{ $product['id']}}">
                    @csrf @method('delete')
                </form>

                <form action="{{ route('vendor.products.status-update') }}"
                      method="post"
                      data-from="product-status"
                      id="product-status{{ $product['id']}}-form"
                      class="admin-product-status-form">

                    @csrf
                    <input type="hidden" name="id" value="{{ $product['id']}}">

                    <label class="form-control mb-0 d-flex gap-3 align-items-center w-max-content">
                        <span class="fw-semibold">{{ translate('status') }}</span>

                        <label class="switcher">
                            <input type="checkbox"
                                   class="switcher_input toggle-switch-message"
                                   name="status"
                                   id="product-status{{ $product['id'] }}"
                                   value="1"
                                   {{ $product['status'] == 1 ? 'checked' : '' }}
                                   data-modal-id="toggle-status-modal"
                                   data-toggle-id="product-status{{ $product['id'] }}"
                                   data-on-image="product-status-on.png"
                                   data-off-image="product-status-off.png"
                                   data-on-title="{{ translate('Want_to_Turn_ON').' '.str_replace("'",'`',$product['name']).' '.translate('status') }}"
                                   data-off-title="{{ translate('Want_to_Turn_OFF').' '.str_replace("'",'`',$product['name']).' '.translate('status') }}"
                                   data-on-message="<p>{{ translate('if_enabled_this_product_will_be_available_on_the_website_and_customer_app') }}</p>"
                                   data-off-message="<p>{{ translate('if_disabled_this_product_will_be_hidden_from_the_website_and_customer_app') }}</p>"
                            >
                            <span class="switcher_control"></span>
                        </label>
                    </label>

                </form>

                <a href="{{ route('vendor.products.update', [$product['id']]) }}"
                   class="btn btn--primary min-w-120 h-40">
                    <i class="fi fi-sr-pencil"></i>{{ translate('Edit') }}
                </a>
            </div>
        </div>
        <div class="row g-2">
            <div class="col-12">
                <div class="card card-top-bg-element">
                    <div class="card-body">
                        <div>
                            <div class="d-flex flex-column flex-xl-row gap-4 h-100">
                                <div class="d-flex flex-column w-xl-280">
                                    <div class="pd-img-wrap position-relative">
                                        <div class="w-100 d-flex justify-content-center">
                                            <div
                                                class="swiper-container quickviewSlider2 border rounded aspect-1 inline-size-100 max-w-280 position-relative">
                                                <div class="position-absolute bottom-0 w-100 z-3">
                                                    <div class="p-3 d-flex justify-content-center align-items-center">
                                                        @if ($productActive && $isActive)
                                                            <a href="{{ route('product', $product['slug']) }}"
                                                               class="btn bg-primary--light text-primary p-2 rounded-50 rounded-pill px-3"
                                                               target="_blank">
                                                                <i class="fi fi-rr-globe"></i>
                                                                <span
                                                                    class="fs-12">{{ translate('View in Website') }}</span>
                                                            </a>
                                                        @endif
                                                    </div>
                                                </div>
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
                                                            <div
                                                                class="position-absolute start-0 top-0 w-100 h-100 z-3 product-slide-overlay">
                                                                <div
                                                                    class="d-flex justify-content-center align-items-center h-100">
                                                                    <div
                                                                        class="p-3 d-flex justify-content-center align-items-center gap-3">
                                                                        <a data-lightbox="products-details-image"
                                                                           href="{{ $imagePath }}"
                                                                           class="btn btn-outline-success text-success bg-white icon-btn">
                                                                            <i class="fi fi-sr-eye"></i>
                                                                        </a>
                                                                        <button
                                                                            class="btn btn-outline--primary text--primary bg-white icon-btn"
                                                                            data-toggle="offcanvas"
                                                                            data-target="#offcanvasEditImages">
                                                                            <i class="fi fi-sr-pencil"></i>
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="">
                                                                <a href="{{ $imagePath }}">
                                                                    <img class="h-100 aspect-1 rounded min-w-xl-280"
                                                                         alt="" src="{{ $imagePath }}">
                                                                </a>
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mt-3 user-select-none">
                                            <div
                                                class="quickviewSliderThumb2 swiper-container position-relative active-border">
                                                <div class="swiper-wrapper auto-item-width justify-content-start">
                                                    @foreach ($imageSources as $key => $photo)
                                                        @php
                                                            $imagePath = isset($photo['image_name'])
                                                                ? getStorageImages(path: $photo['image_name'], type: 'backend-product')
                                                                : getStorageImages(path: $photo, type: 'backend-product');
                                                        @endphp
                                                        <div class="swiper-slide position-relative rounded border"
                                                             role="group">
                                                            <img class="aspect-1" alt="" src="{{ $imagePath }}">
                                                        </div>
                                                    @endforeach
                                                </div>

                                                <div class="swiper-button-next swiper-quickview-button-next"></div>
                                                <div class="swiper-button-prev swiper-quickview-button-prev"></div>
                                            </div>
                                        </div>
                                    </div>
                                    @if($product->added_by=='seller')
                                        @if(isset($product->seller->shop))
                                            <div
                                                class="mt-4 bg-section h-100 p-3 rounded d-flex justify-content-center align-items-center text-center">
                                                <div class="">
                                                    <h4 class="fs-14 mb-1 line-1"
                                                        title="{{ $product->seller->shop->name  }}">{{ $product->seller->shop->name  }}</h4>
                                                    @if(count($totalSellerProducts ?? []) > 0)
                                                        <p class="fs-12 mb-2">
                                                            {{ count($totalSellerProducts ?? []) }}
                                                            {{ count($totalSellerProducts ?? []) > 1 ? translate('products') : translate('product') }}
                                                        </p>
                                                    @else
                                                        <p class="fs-12 mb-2">0 {{ translate('product') }}</p>
                                                    @endif
                                                    <a href="{{ route('vendor-shop',['slug' => $product->seller->shop->slug]) }}"
                                                       target="_blank" type="button"
                                                       class="btn btn-primary rounded-pill">
                                                        {{ translate('Visit_Vendor') }}
                                                    </a>
                                                </div>
                                            </div>
                                        @endif
                                    @endif
                                </div>

                                <div class="flex-grow-1">
                                    <div class="d-block flex-grow-1 w-max-md-100">
                                        <div class="d-flex flex-wrap justify-content-between align-items-center">
                                            @php($languages = getWebConfig(name:'pnc_language'))
                                            @php($defaultLanguage = 'en')
                                            @php($defaultLanguage = $languages[0])
                                            <ul class="nav nav-tabs w-fit-content mb-2">
                                                @php($isFirstLanguage=1)
                                                @foreach($languages as $language)
                                                    <li class="nav-item text-capitalize {{ $isFirstLanguage == 1 ? '' : 'px-3' }}">
                                                        @php($isFirstLanguage=0)
                                                        <a class="nav-link action-for-lang-tab lang-link px-0 {{$language == $defaultLanguage? 'active':''}}"
                                                           href="javascript:"
                                                           id="{{$language}}-link">{{ getLanguageName($language).'('.strtoupper($language).')' }}
                                                        </a>
                                                    </li>
                                                @endforeach
                                            </ul>
                                            <div class="d-flex flex-column gap-2">
                                                @if ($product['added_by'] == 'seller' && $product['request_status'] == 2)
                                                    <div class="d-flex justify-content-sm-end flex-wrap gap-2 pb-4">
                                                        <div>
                                                            <span>{{ translate('status') . ' : ' }}</span>
                                                            <span
                                                                class="badge text-bg-danger badge-danger">{{ translate('denied') }}</span>
                                                        </div>
                                                    </div>
                                                @endif
                                                <div
                                                    class="d-flex gap-3 flex-nowrap justify-content-sm-end align-items-center lh-1 fz-14 fw-bold badge bg-section shadow-sm px-3 height-30px ">
                                                    <div
                                                        class="review-hover position-relative cursor-pointer d-flex gap-2 align-items-center">
                                                        <i class="fi fi-sr-star text-warning rotate-45deg"
                                                           style="--bs-warning-rgb: 254, 133, 81;"></i>
                                                        <span>
                                                            {{ count($product->rating)>0 ? number_format($product->rating[0]->average, 2, '.', ' '):0 }}
                                                        </span>
                                                        <div class="review-details-popup">
                                                            <h6 class="mb-2">{{ translate('rating') }}</h6>
                                                            <div class="">
                                                                <ul class="list-unstyled list-unstyled-py-2 mb-0">
                                                                    @php($total = $product->reviews->count())

                                                                    <li class="d-flex align-items-center font-size-sm">
                                                                        @php($five = getRatingCount($product['id'], 5))
                                                                        <span
                                                                            class="{{ Session::get('direction') === "rtl" ? 'ml-3' : 'me-3' }}">
                                                                        {{ translate('5') }} {{ translate('star') }}
                                                                    </span>
                                                                        <div class="progress flex-grow-1">
                                                                            <div class="progress-bar" role="progressbar"
                                                                                 style="width: {{ $total == 0 ? 0 : ($five/$total)*100 }}%;"
                                                                                 aria-valuenow="{{ $total == 0 ? 0 : ($five/$total)*100 }}"
                                                                                 aria-valuemin="0"
                                                                                 aria-valuemax="100"></div>
                                                                        </div>
                                                                        <span
                                                                            class="{{ Session::get('direction') === "rtl" ? 'me-3' : 'ml-3' }}">{{ $five }}</span>
                                                                    </li>

                                                                    <li class="d-flex align-items-center font-size-sm">
                                                                        @php($four=getRatingCount($product['id'],4))
                                                                        <span
                                                                            class="{{ Session::get('direction') === "rtl" ? 'ml-3' : 'me-3' }}">{{ translate('4') }} {{ translate('star') }}</span>
                                                                        <div class="progress flex-grow-1">
                                                                            <div class="progress-bar" role="progressbar"
                                                                                 style="width: {{ $total == 0 ? 0 : ($four/$total)*100}}%;"
                                                                                 aria-valuenow="{{ $total == 0 ? 0 : ($four/$total)*100}}"
                                                                                 aria-valuemin="0"
                                                                                 aria-valuemax="100"></div>
                                                                        </div>
                                                                        <span
                                                                            class="{{ Session::get('direction') === "rtl" ? 'me-3' : 'ml-3' }}">{{ $four }}</span>
                                                                    </li>

                                                                    <li class="d-flex align-items-center font-size-sm">
                                                                        @php($three=getRatingCount($product['id'],3))
                                                                        <span
                                                                            class="{{ Session::get('direction') === "rtl" ? 'ml-3' : 'me-3'}}">{{ translate('3') }} {{ translate('star') }}</span>
                                                                        <div class="progress flex-grow-1">
                                                                            <div class="progress-bar" role="progressbar"
                                                                                 style="width: {{ $total == 0 ? 0 : ($three/$total)*100 }}%;"
                                                                                 aria-valuenow="{{ $total == 0 ? 0 : ($three/$total)*100 }}"
                                                                                 aria-valuemin="0"
                                                                                 aria-valuemax="100"></div>
                                                                        </div>
                                                                        <span
                                                                            class="{{ Session::get('direction') === "rtl" ? 'me-3' : 'ml-3'}}">{{ $three }}</span>
                                                                    </li>

                                                                    <li class="d-flex align-items-center font-size-sm">
                                                                        @php($two=getRatingCount($product['id'],2))
                                                                        <span
                                                                            class="{{ Session::get('direction') === "rtl" ? 'ml-3' : 'me-3'}}">{{ translate('2') }} {{ translate('star') }}</span>
                                                                        <div class="progress flex-grow-1">
                                                                            <div class="progress-bar" role="progressbar"
                                                                                 style="width: {{ $total == 0 ? 0 : ($two/$total)*100}}%;"
                                                                                 aria-valuenow="{{ $total == 0 ? 0 : ($two/$total)*100}}"
                                                                                 aria-valuemin="0"
                                                                                 aria-valuemax="100"></div>
                                                                        </div>
                                                                        <span
                                                                            class="{{ Session::get('direction') === "rtl" ? 'me-3' : 'ml-3'}}">{{ $two }}</span>
                                                                    </li>

                                                                    <li class="d-flex align-items-center font-size-sm">
                                                                        @php($one=getRatingCount($product['id'],1))
                                                                        <span
                                                                            class="{{ Session::get('direction') === "rtl" ? 'ml-3' : 'me-3'}}">{{ translate('1') }} {{ translate('star') }}</span>
                                                                        <div class="progress flex-grow-1">
                                                                            <div class="progress-bar" role="progressbar"
                                                                                 style="width: {{ $total == 0 ? 0 : ($one/$total)*100}}%;"
                                                                                 aria-valuenow="{{ $total == 0 ? 0 : ($one/$total)*100}}"
                                                                                 aria-valuemin="0"
                                                                                 aria-valuemax="100"></div>
                                                                        </div>
                                                                        <span
                                                                            class="{{ Session::get('direction') === "rtl" ? 'me-3' : 'ml-3'}}">{{ $one }}</span>
                                                                    </li>

                                                                </ul>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <span class="border-left py-2"
                                                          style="border-color: var(--border-dark)"></span>
                                                    <span class="text-dark">
                                                        {{ formatCompactNumber(value: $product->reviews->whereNotNull('comment')->count()) }}
                                                        <span
                                                            class="fw-normal text-body">{{ translate('reviews') }}</span>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="d-block mt-2">
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
                                                <div
                                                    class="{{ $language == $defaultLanguage ? '' : 'd-none' }} lang-form"
                                                    id="{{ $language}}-form">
                                                    <div class="d-flex">
                                                        <h2 class="mb-2 pb-1 text-gulf-blue">{{ $translate[$language]['name']??$product['name']}}</h2>
                                                    </div>
                                                    <div class="position-relative bg-section rounded-10 p-2">
                                                        <div class="bg-white rounded p-3 text_editor_wrapper">
                                                            <div class="h-350 overflow-hidden">
                                                                <div class="rich-editor-html-content">
                                                                    {!! $translate[$language]['description'] ?? $product['details'] !!}
                                                                </div>
                                                            </div>
                                                            <div class="position-absolute w-100 blurry-div">
                                                                <div
                                                                    class="d-flex justify-content-center align-items-center h-100 p-3">
                                                                    <button type="button"
                                                                            class="btn btn-outline-primary"
                                                                            data-toggle="offcanvas"
                                                                            data-target="#offcanvasProductDetails">{{ translate('See_More') }}</button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>

                        <hr>

                        <div class="d-flex gap-3 flex-column flex-lg-row overflow-wrap-anywhere">
                            <div class="bg-section rounded p-2 w-100-mobile min-w-180">
                                <div class="d-flex flex-column mb-1">
                                    <div class="fw-normal text-capitalize fs-12 mb-1">{{ translate('Total_Qty_Sold') }}
                                        :
                                    </div>
                                    <h3 class="text-dark fs-18">{{ $product['total_qty_sold'] }}</h3>
                                </div>
                                <div class="d-flex flex-column">
                                    <div
                                        class="fw-normal text-capitalize fs-12 mb-1">{{ translate('Total_Order_Amount') }}
                                        :
                                    </div>
                                    <h3 class="text-dark fs-18">
                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $product['priceSum'] - $product['discountSum'])) }}
                                    </h3>
                                </div>
                            </div>

                            <div class="row gy-3 flex-grow-1">
                                <div class="col-sm-6 col-xl-4">
                                    <h4 class="mb-3 fs-14 text-capitalize">{{ translate('general_information') }}</h4>
                                    <div class="pair-list">
                                        @if($product?->product_type == 'physical' && isset($product->brand))
                                            <div>
                                                <span
                                                    class="key text-nowrap fs-12 fs-12">{{ translate('brand') }}</span>
                                                <span>:</span>
                                                <span class="value text-dark fs-12">
                                                {{ $product?->brand?->default_name ?? translate('brand_not_found') }}
                                            </span>
                                            </div>
                                        @endif

                                        <div>
                                            <span class="key text-nowrap fs-12">{{ translate('category') }}</span>
                                            <span>:</span>
                                            <span class="value fs-12 text-dark">
                                                {{isset($product->category) ? $product->category->default_name : translate('category_not_found') }}
                                            </span>
                                        </div>

                                        <div>
                                            <span class="key text-nowrap fs-12">{{ translate('product_type') }}</span>
                                            <span>:</span>
                                            <span
                                                class="value fs-12 text-dark">{{ translate($product->product_type) }}</span>
                                        </div>
                                        @if($product->product_type == 'physical')
                                            <div>
                                                <span
                                                    class="key text-nowrap fs-12 text-capitalize">{{ translate('product_unit') }}</span>
                                                <span>:</span>
                                                <span class="value fs-12 text-dark">{{ $product['unit']}}</span>
                                            </div>
                                            <div>
                                                <span
                                                    class="key text-nowrap fs-12">{{ translate('current_Stock') }}</span>
                                                <span>:</span>
                                                <span class="value fs-12 text-dark">{{ $product->current_stock}}</span>
                                            </div>
                                        @endif
                                        <div>
                                            <span class="key text-nowrap fs-12">{{ translate('product_SKU') }}</span>
                                            <span>:</span>
                                            <span class="value fs-12 text-dark">{{ $product->code}}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6 col-xl-4">
                                    <h4 class="mb-3 fs-14 text-capitalize">{{ translate('price_information') }}</h4>
                                    <div class="pair-list">
                                        <div>
                                            <span class="key text-wrap fs-12 text-capitalize">
                                                {{ translate('unit_price') }}
                                            </span>
                                            <span>:</span>
                                            <span class="value fs-12 text-dark">
                                                {{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $product->unit_price), currencyCode: getCurrencyCode()) }}
                                            </span>
                                        </div>

                                        @if($product->product_type == 'physical')
                                            <div>
                                                <span class="key text-wrap fs-12 text-capitalize">
                                                    {{ translate('shipping_cost') }}
                                                </span>
                                                <span>:</span>
                                                <span class="value fs-12 text-dark">
                                                    {{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $product->shipping_cost)) }}
                                                    @if ($product->multiply_qty == 1)
                                                        ({{ translate('multiply_with_quantity') }})
                                                    @endif
                                                </span>
                                            </div>
                                        @endif
                                        @if($product->discount > 0)
                                            <div>
                                                <span class="key text-wrap fs-12">
                                                    {{ translate('discount') }}
                                                </span>
                                                <span>:</span>
                                                @if ($product->discount_type == 'percent')
                                                    <span class="value fs-12 text-dark">{{ $product->discount }}%</span>
                                                @else
                                                    <span class="value fs-12 text-dark">
                                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $product->discount), currencyCode: getCurrencyCode()) }}
                                                    </span>
                                                @endif
                                            </div>
                                        @endif

                                        @if ($productWiseTax)
                                            @foreach ($product?->taxVats as $key => $taxVat)
                                                <div>
                                                    <span class="key text-wrap fs-12">{{ $taxVat->tax->name }}</span>
                                                    <span>:</span>
                                                    <span class="value fs-12 text-dark">
                                                        {{ $taxVat->tax->tax_rate }}%
                                                    </span>
                                                </div>
                                            @endforeach
                                        @endif
                                    </div>
                                </div>
                                @if(count($product->tags)>0)
                                    <div class="col-sm-6 col-xl-4">
                                        <h4 class="mb-3 fs-14">{{ translate('tags') }}</h4>
                                        <div class="pair-list">
                                            <div>
                                                <span class="value fs-12 text-dark">
                                                    @foreach ($product->tags as $key=>$tag)
                                                        {{ $tag['tag'] }}
                                                        @if ($key === (count($product->tags)-1))
                                                            @break
                                                        @endif
                                                        ,
                                                    @endforeach
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                @endif

                            </div>
                        </div>
                    </div>


                </div>
            </div>
            @if(!empty($product['variation']) && count(json_decode($product['variation'])) >0)
                <div class="col-md-12">
                    <div class="card border-0">
                        <div class="card-body p-0">
                            <div class="k-table-wrap">
                                <table
                                    class="k-table">
                                    <thead>
                                    <tr>
                                        <th class="text-center">{{ translate('SKU') }}</th>
                                        <th class="text-center text-capitalize">{{ translate('variation_wise_price') }}</th>
                                        <th class="text-center">{{ translate('stock') }}</th>
                                        <th></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach(json_decode($product['variation']) as $key=>$value)
                                        <tr>
                                            <td class="text-center">
                                                <span class="py-1">{{$value->sku}}</span>
                                            </td>
                                            <td class="text-center">
                                                <span class="py-1">
                                                    {{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $value->price), currencyCode: getCurrencyCode())}}
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <span class="py-1">{{($value->qty)}}</span>
                                            </td>
                                            <td></td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            @if(!empty($product->digitalVariation) && count($product->digitalVariation) > 0)
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
                                        @if($product->digital_product_type == 'ready_product')
                                            <th class="text-center">{{ translate('Action') }}</th>
                                        @endif
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($product->digitalVariation as $key=> $variation)
                                        <tr>
                                            <td class="text-center">
                                                {{ $key+1 }}
                                            </td>
                                            <td class="text-center text-capitalize">
                                                <span class="py-1">{{ $variation->variant_key ?? '-' }}</span>
                                            </td>
                                            <td class="text-center">
                                                <span class="py-1">{{$variation->sku}}</span>
                                            </td>
                                            <td class="text-center">
                                                <span class="py-1">
                                                    {{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $variation->price), currencyCode: getCurrencyCode())}}
                                                </span>
                                            </td>

                                            @if($product->digital_product_type == 'ready_product')
                                                <td class="text-center">
                                                    <span class="btn p-0 getDownloadFileUsingFileUrl"
                                                          data-toggle="tooltip"
                                                          title="{{ !is_null($variation->file_full_url['path']) ? translate('download') : translate('File_not_found') }}"
                                                          data-file-path="{{ $variation->file_full_url['path'] }}"
                                                          download>
                                                        <img
                                                            src="{{ asset(path: 'public/assets/back-end/img/icons/download-green.svg') }}"
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

            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header bg-white">
                        <h5 class="card-title text-capitalize">{{translate('product_SEO_&_meta_data')}}</h5>
                    </div>
                    <div class="card-body">
                        <div>
                            <h6 class="mb-3 text-capitalize">
                                {{ $product?->seoInfo?->title ?? ( $product->meta_title ?? translate('meta_title_not_found').' '.'!')}}
                            </h6>
                        </div>
                        <p class="text-capitalize">
                            {{ $product?->seoInfo?->description ?? ($product->meta_description ?? translate('meta_description_not_found').' '.'!')}}
                        </p>
                        @if($product?->seoInfo?->image_full_url['path'] || $product->meta_image_full_url['path'])
                            <div class="d-flex flex-wrap gap-2">
                                <a class="h-100px w-180 float-start overflow-hidden"
                                   href="{{ getStorageImages(path:$product?->seoInfo?->image_full_url['path'] ? $product?->seoInfo?->image_full_url : $product->meta_image_full_url,type: 'backend-basic') }}"
                                   data-lightbox="meta-thumbnail">
                                    <img class="object-fit-cover rounded h-100"
                                         src="{{ getStorageImages(path:$product?->seoInfo?->image_full_url['path'] ? $product?->seoInfo?->image_full_url : $product->meta_image_full_url,type: 'backend-basic') }}"
                                         alt="{{translate('meta_image')}}">
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header bg-white">
                        <h5 class="card-title text-capitalize">{{translate('product_video')}}</h5>
                    </div>
                    <div class="card-body">
                        <div>
                            <h6 class="mb-3 text-capitalize">
                                {{$product['video_provider'].' '.translate('video_link')}}
                            </h6>
                        </div>
                        @if ($product['video_url'])
                            <a href="{{ str_contains($product->video_url, 'https://') || str_contains($product->video_url, 'http://') ? $product['video_url'] : 'javascript:' }}"
                               target="_blank"
                               class="text--primary text-underline {{ str_contains($product->video_url, 'https://') || str_contains($product->video_url, 'http://') ? '' : 'cursor-default' }}">
                                {{ $product['video_url'] }}
                            </a>
                            <div class="d-flex flex-wrap gap-3 align-items-center mt-4">
                                <a href="#video-view-modal" data-toggle="modal">
                                    <div class="position-relative h-100px w-180">
                                        <img class="object-fit-cover rounded-10 h-100"
                                             src="{{ dynamicAsset(path: 'public/assets/back-end/img/video-preview-demo.png') }}"
                                             alt="">
                                        <div
                                            class="position-absolute top-0 left-0 h-100 w-100 d-flex justify-content-center align-items-center">
                                            <button type="button" class="btn text-C2A7A7 bg-white btn-circle">
                                                <i class="fi fi-sr-play d-flex"></i>
                                            </button>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        @else
                            <span>{{ translate('no_data_to_show') . ' ' . '!' }}</span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    @php($vendorReviewReplyStatus = getWebConfig('vendor_review_reply_status') ?? 0)
                    <div class="k-table-wrap">
                        <table
                            class="k-table">
                            <thead>
                            <tr>
                                <th>{{ translate('SL') }}</th>
                                <th>{{ translate('Review_ID') }}</th>
                                <th>{{ translate('reviewer') }}</th>
                                <th>{{ translate('rating') }}</th>
                                <th>{{ translate('review') }}</th>
                                @if($vendorReviewReplyStatus)
                                    <th>{{ translate('Reply') }}</th>
                                @endif
                                <th class="text-center">{{ translate('date') }}</th>
                                <th class="text-center">{{ translate('Status') }}</th>
                                <th class="text-center">{{ translate('action') }}</th>
                            </tr>
                            </thead>

                            <tbody>
                            @foreach($reviews as $key=>$review)
                                @if(isset($review->customer))
                                    <tr>
                                        <td>{{ $reviews->firstItem()+$key}}</td>
                                        <td class="text-center">
                                            {{ $review->id }}
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar rounded">
                                                    <img class="avatar-img"
                                                         src="{{ getStorageImages(path:$review->customer->image_full_url,type: 'backend-profile') }}"
                                                         alt="">
                                                </div>
                                                <div class="{{ Session::get('direction') === "rtl" ? 'me-3' : 'ml-3'}}">
                                                    <span class="d-block h5 text-hover-primary mb-0">
                                                        {{ $review->customer['f_name']." ".$review->customer['l_name']}}
                                                        <i class="tio-verified text--primary" data-toggle="tooltip"
                                                           data-placement="top" title="Verified Customer"></i>
                                                    </span>
                                                    <span class="d-block font-size-sm text-body">
                                                        {{ $review->customer->email ?? "" }}
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2 text--primary">
                                                <i class="tio-star"></i>
                                                <span>{{ $review->rating }}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="text-wrap max-w-400 min-w-200">
                                                <div
                                                    @if($review->comment && strlen($review->comment) > 35)
                                                        data-toggle="tooltip" data-placement="top"
                                                    title="{{ $review->comment }}"
                                                    @endif
                                                >
                                                    {{ $review->comment ? Str::limit($review->comment, 35) : translate('no_comment_found') }}
                                                </div>
                                                @if(count($review->attachment_full_url) > 0)
                                                    @foreach ($review->attachment_full_url as $img)
                                                        <a class="aspect-1 float-start overflow-hidden"
                                                           href="{{ getStorageImages(path: $img,type: 'backend-basic') }}"
                                                           data-lightbox="review-gallery{{ $review['id'] }}">
                                                            <img class="p-2" width="60" height="60"
                                                                 src="{{ getStorageImages(path: $img,type: 'backend-basic') }}"
                                                                 alt="{{translate('review_image')}}">
                                                        </a>
                                                    @endforeach
                                                @endif
                                            </div>
                                        </td>
                                        @if($vendorReviewReplyStatus)
                                            <td>

                                                    <?php $replyText = $review?->reply?->reply_text; ?>
                                                <div class="text-wrap max-w-400 min-w-200"
                                                     @if($replyText && strlen($replyText) > 35)
                                                         data-toggle="tooltip" data-placement="top"
                                                     title="{{ $replyText }}"
                                                    @endif>
                                                    {{ $replyText ? Str::limit($replyText, 35) : '-' }}
                                                </div>
                                            </td>
                                        @endif
                                        <td class="text-center">
                                            {{ date('d M Y H:i:s', strtotime($review['created_at'])) }}
                                        </td>
                                        <td>
                                            <form
                                                action="{{ route('vendor.reviews.update-status', [$review['id'], $review->status ? 0 : 1]) }}"
                                                method="get" id="reviews-status{{ $review['id']}}-form">
                                                <label class="switcher mx-auto">
                                                    <input type="checkbox" class="switcher_input toggle-switch-message"
                                                           name="status"
                                                           id="reviews-status{{ $review['id'] }}" value="1"
                                                           {{ $review['status'] == 1 ? 'checked' : '' }}
                                                           data-modal-id="toggle-status-modal"
                                                           data-toggle-id="reviews-status{{ $review['id'] }}"
                                                           data-on-image="customer-reviews-on.png"
                                                           data-off-image="customer-reviews-off.png"
                                                           data-on-title="{{ translate('Want_to_Turn_ON_Customer_Reviews') }}"
                                                           data-off-title="{{ translate('Want_to_Turn_OFF_Customer_Reviews') }}"
                                                           data-on-message="<p>{{ translate('if_enabled_anyone_can_see_this_review_on_the_user_website_and_customer_app') }}</p>"
                                                           data-off-message="<p>{{ translate('if_disabled_this_review_will_be_hidden_from_the_user_website_and_customer_app') }}</p>">
                                                    <span class="switcher_control"></span>
                                                </label>
                                            </form>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-2 justify-content-center">
                                                <div data-toggle="modal"
                                                     data-target="#review-view-for-{{ $review['id'] }}">
                                                    <a class="btn btn-outline-info btn-sm square-btn"
                                                       title="{{ translate('View') }}" data-toggle="tooltip">
                                                        <i class="tio-invisible"></i>
                                                    </a>
                                                </div>

                                                @if($vendorReviewReplyStatus)
                                                    <div data-toggle="modal"
                                                         data-target="#review-update-for-{{ $review['id'] }}">
                                                        @if($review?->reply)
                                                            <a class="btn btn-outline-primary btn-sm square-btn"
                                                               title="{{ translate('Update_Review') }}"
                                                               data-toggle="tooltip">
                                                                <i class="tio-edit"></i>
                                                            </a>
                                                        @else
                                                            <div class="btn btn-outline--primary btn-sm square-btn"
                                                                 title="{{ translate('Review_Reply') }}"
                                                                 data-toggle="tooltip">
                                                                <i class="tio-reply-all"></i>
                                                            </div>
                                                        @endif
                                                    </div>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    @foreach($reviews as $key => $review)

                        @if(isset($review->customer))
                            <div class="modal fade" id="review-update-for-{{ $review['id'] }}" tabindex="-1"
                                 aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <button type="button" class="close text-BFBFBF" data-dismiss="modal">
                                                <i class="tio-clear-circle"></i>
                                            </button>
                                        </div>

                                        <form method="POST" action="{{ route('vendor.reviews.add-review-reply') }}">
                                            @csrf
                                            <div class="modal-body pt-0">

                                                <div class="d-flex flex-wrap gap-3 mb-3">
                                                    <img src="{{ getStorageImages(path: $product->thumbnail_full_url, type: 'backend-product') }}"
                                                         width="120" class="rounded aspect-1 border" alt="">
                                                    <div class="w-0 flex-grow-1 font-weight-semibold">
                                                        @if($review['order_id'])
                                                            <div class="mb-2">
                                                                {{ translate('Order_ID') }} # {{ $review['order_id'] }}
                                                            </div>
                                                        @endif
                                                        <h4>{{ $translate[$language]['name'] ?? $product['name'] }}</h4>
                                                    </div>
                                                </div>

                                                <label class="input-label text--title font-weight-bold">
                                                    {{ translate('Review') }}
                                                </label>

                                                <div class="__bg-F3F5F9 p-3 rounded border mb-2 short_text_wrapper">
                                                <span class="short_text"
                                                      data-maxlength="120"
                                                      data-see-more-text="{{ translate('see_more') }}"
                                                      data-see-less-text="{{ translate('see_less') }}">
                                                    {{ $review['comment'] }}
                                                </span>

                                                    @if($review['comment'] && Str::length($review['comment']) > 120)
                                                        <span class="see_more_btn text-primary cursor-pointer ms-1">
                                                        {{ translate('see_more') }}
                                                    </span>
                                                    @endif
                                                </div>

                                                @if(count($review->attachment_full_url) > 0)
                                                    <div class="d-flex flex-wrap gap-2">
                                                        @foreach ($review->attachment_full_url as $img)
                                                            <a href="{{ getStorageImages(path: $img,type: 'backend-basic') }}"
                                                               data-lightbox="review-gallery-modal{{ $review['id'] }}">
                                                                <img width="45"
                                                                     class="rounded aspect-1 border"
                                                                     src="{{ getStorageImages(path: $img,type: 'backend-basic') }}"
                                                                     alt="{{translate('review_image')}}">
                                                            </a>
                                                        @endforeach
                                                    </div>
                                                @endif

                                                <label class="input-label text--title font-weight-bold pt-4">
                                                    {{ translate('Reply') }}
                                                </label>

                                                <input type="hidden" name="review_id" value="{{ $review['id'] }}">

                                                <textarea class="form-control text-area-max-min"
                                                          rows="3"
                                                          name="reply_text"
                                                          placeholder="{{ translate('Write_the_reply_of_the_product_review') }}...">{{ $review?->reply?->reply_text ?? '' }}</textarea>

                                                <div class="text-end mt-4">
                                                    <button type="submit" class="btn btn--primary">
                                                        @if($review?->reply?->reply_text)
                                                            {{ translate('Update') }}
                                                        @else
                                                            {{ translate('submit') }}
                                                        @endif
                                                    </button>
                                                </div>

                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endif

                        <div class="modal fade" id="review-view-for-{{ $review['id'] }}" tabindex="-1"
                             aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <button type="button" class="close text-BFBFBF" data-dismiss="modal">
                                            <i class="tio-clear-circle"></i>
                                        </button>
                                    </div>

                                    <div class="modal-body pt-0">
                                        <div class="d-flex flex-wrap align-items-center gap-3 mb-3 text-center border-bottom">
                                            <div class="w-0 flex-grow-1 font-weight-semibold">
                                                <div class="mb-2">
                                                    {{ translate('Review_ID') }} # {{ $review['id'] }}
                                                </div>
                                                @if($review['order_id'])
                                                    <div class="mb-2">
                                                        {{ translate('Order_ID') }} # {{ $review['order_id'] }}
                                                    </div>
                                                @endif
                                            </div>
                                        </div>

                                        <h2 class="text-center">
                                            <span class="text--primary">{{ $review['rating'].'.0' }}</span>
                                            <span class="fz-16 text-muted">/5</span>
                                        </h2>

                                        <div class="d-flex align-items-center gap-1 text--primary justify-content-center fz-14 mb-4">
                                            @for($i = 1; $i <= 5; $i++)
                                                @if($i <= $review['rating'])
                                                    <i class="tio-star"></i>
                                                @else
                                                    <i class="tio-star-outlined"></i>
                                                @endif
                                            @endfor
                                        </div>
                                        <label class="input-label text--title font-weight-bold">
                                            {{ translate('Review') }}
                                        </label>
                                        <div class="__bg-F3F5F9 p-3 rounded border mb-2 short_text_wrapper">
                                        <span class="short_text"
                                              data-maxlength="120"
                                              data-see-more-text="{{ translate('see_more') }}"
                                              data-see-less-text="{{ translate('see_less') }}">
                                            {{ $review['comment'] }}
                                        </span>

                                            @if($review['comment'] && Str::length($review['comment']) > 120)
                                                <span class="see_more_btn text-primary cursor-pointer ms-1">
                                                {{ translate('see_more') }}
                                            </span>
                                            @endif
                                        </div>
                                        @if(count($review->attachment_full_url) > 0)
                                            <div class="d-flex flex-wrap gap-2">
                                                @foreach ($review->attachment_full_url as $img)
                                                    <a href="{{ getStorageImages(path: $img,type: 'backend-basic') }}"
                                                       data-lightbox="review-gallery-modal{{ $review['id'] }}">
                                                        <img width="45"
                                                             class="rounded aspect-1 border"
                                                             src="{{ getStorageImages(path: $img,type: 'backend-basic') }}"
                                                             alt="{{translate('review_image')}}">
                                                    </a>
                                                @endforeach
                                            </div>
                                        @endif

                                        @if($review?->reply?->reply_text)
                                            <label class="input-label text--title font-weight-bold pt-4">
                                                {{ translate('Reply') }}
                                            </label>

                                        <?php $replyText = $review?->reply?->reply_text;?>
                                            <div class="__bg-F3F5F9 p-3 rounded border mb-2 short_text_wrapper">
                                            <span class="short_text"
                                                  data-maxlength="120"
                                                  data-see-more-text="{{ translate('see_more') }}"
                                                  data-see-less-text="{{ translate('see_less') }}">
                                                {{ $replyText }}
                                            </span>
                                                @if(Str::length($replyText) > 120)
                                                    <span class="see_more_btn text-primary cursor-pointer ms-1">
                                                    {{ translate('see_more') }}
                                                </span>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                    @endforeach

                    <div class="table-responsive mt-4">
                        <div class="px-4 d-flex justify-content-lg-end">
                            {!! $reviews->links() !!}
                        </div>
                    </div>

                    @if(count($reviews)==0)
                        @include('layouts.vendor.partials._empty-state',['text'=>'no_review_found'],['image'=>'default'])
                    @endif
                </div>
            </div>
        </div>
    </div>
    <?php
    $languages = getWebConfig(name: 'pnc_language');
    $defaultLanguage = $languages[0];

    $translate = [];
    if (count($product['translations'])) {
        foreach ($product['translations'] as $translation) {
            if ($translation->key == 'name') {
                $translate[$translation->locale]['name'] = $translation->value;
            }
            if ($translation->key == 'description') {
                $translate[$translation->locale]['description'] = $translation->value;
            }
        }
    }
    ?>

    <div class="offcanvas-sidebar guide-offcanvas" id="offcanvasProductDetails" style="--bs-offcanvas-width: 750px;">
        <div class="offcanvas-overlay" data-dismiss="offcanvas"></div>
        <div class="offcanvas-content bg-white shadow d-flex flex-column">
            <div class="offcanvas-header border-0">
                <div class="bg-light d-flex justify-content-between align-items-center gap-3 p-3 mb-1">
                    @foreach ($languages as $language)
                        <h3 id="product-name-heading-{{ $language }}"
                            class="mb-0 product-name-heading-title {{ $language == $defaultLanguage ? '' : 'd-none' }}">
                            {{ $translate[$defaultLanguage]['name'] ?? $product['name'] }}
                        </h3>
                    @endforeach
                    <button type="button" class="close" data-dismiss="offcanvas" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <ul class="nav nav-tabs nav--tab lang_tab" id="offcanvas-pills-tab" role="tablist">
                    @foreach ($languages as $language)
                        <li class="nav-item" role="presentation">
                            <a class="nav-link action-for-lang-tab-offcanvas ms-1 {{ $language == $defaultLanguage ? 'active' : '' }}"
                               href="javascript:"
                               data-name="{{ $translate[$language]['name'] ?? $product['name'] }}"
                               data-name-field="#product-name-heading-{{ $language }}"
                               data-target-tab="#{{ $language }}-offcanvas-form"
                               data-target-group=".language-wise-offcanvas-details"
                               data-bs-toggle="pill" role="tab" id="{{ $language }}-link">
                                {{ ucwords(getLanguageName($language)) . ' (' . strtoupper($language) . ')' }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="offcanvas-body p-3 overflow-auto flex-grow-1">
                <div class="tab-content" id="pills-tabContent">
                    @foreach ($languages as $language)
                        <div
                            class="tab-pane fade language-wise-offcanvas-details {{ $language == $defaultLanguage ? 'show active' : '' }}"
                            id="{{ $language }}-offcanvas-form"
                            aria-labelledby="{{ $language }}-link"
                            role="tabpanel">
                            <div class="rich-editor-html-content">
                                {!! $translate[$language]['description'] ?? $product['details'] !!}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    @include('vendor-views.product.partials.modal._video-view-modal')
    @include('vendor-views.product.partials.offcanvas._edit-images-offcanvas',['product' => $product])

@endsection
@push('script')
    <script
        src="{{ dynamicAsset(path: 'public/assets/new/back-end/libs/spartan-multi-image-picker/spartan-multi-image-picker-min.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/new/back-end/js/multiple-image-upload.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/new/back-end/js/single-image-upload.js') }}"></script>
    <script>
        'use strict';
        $(".lang-link").click(function (e) {
            e.preventDefault();
            $('.lang-link').removeClass('active');
            $(".lang-form").addClass('d-none');
            $(this).addClass('active');
            let formId = this.id;
            let lang = formId.split("-")[0];
            $("#" + lang + "-form").removeClass('d-none');
        });

        $(".action-for-lang-tab").click(function (e) {
            e.preventDefault();
            $('.action-for-lang-tab').removeClass('active');
            $(this).addClass('active');
            $($(this).data('target-group')).removeClass('show').removeClass('active');
            $($(this).data('target-tab')).addClass('show').addClass('active');
        });

        $(".action-for-lang-tab-offcanvas").click(function (e) {
            e.preventDefault();
            $('.action-for-lang-tab-offcanvas').removeClass('active');
            $(this).addClass('active');
            $($(this).data('target-group')).removeClass('show').removeClass('active');
            $($(this).data('target-tab')).addClass('show').addClass('active');

            const productName = $(this).data('name');
            const productNameField = $(this).data('name-field');
            $('.product-name-heading-title').addClass('d-none');
            if (productName) {
                $(productNameField).text(productName).removeClass('d-none');
            }
        });

        $(document).ready(function () {
            let activeLanguage = '{{ $defaultLanguage }}';

            function updateBlurryDiv($wrapper) {
                let $textEditorContent = $wrapper.find('.rich-editor-html-content');
                let $blurryDiv = $wrapper.find('.blurry-div');

                if ($textEditorContent[0] && $textEditorContent[0].scrollHeight > 300) {
                    $blurryDiv.show();
                } else {
                    $blurryDiv.hide();
                }
            }

            $('.text_editor_wrapper').each(function () {
                updateBlurryDiv($(this));
            });


            $('.lang-link').on('click', function () {
                activeLanguage = this.id.replace('-link', '');

                setTimeout(() => {
                    $('.text_editor_wrapper:visible').each(function () {
                        updateBlurryDiv($(this));
                    });
                }, 300);
            });
            $(document).on('click', '.action-for-lang-tab', function () {
                activeLanguage = this.id.replace('-link', '');
            });
            $(document).on('click', '[data-toggle="offcanvas"][data-target="#offcanvasProductDetails"]', function () {
                setTimeout(function () {
                    if (activeLanguage) {
                        $('#offcanvas-pills-tab .nav-link').removeClass('active');
                        $('.language-wise-offcanvas-details').removeClass('show active');
                        const $offcanvasTab = $(`#offcanvas-pills-tab #${activeLanguage}-link`);
                        const $offcanvasPane = $(`#${activeLanguage}-offcanvas-form`);
                        if ($offcanvasTab.length && $offcanvasPane.length) {
                            $offcanvasTab.addClass('active');
                            $offcanvasPane.addClass('show active');
                            const productName = $offcanvasTab.data('name');
                            if (productName) {
                                $('#product-name-heading').text(productName);
                            }
                        }
                    }
                }, 100);
            });

        });
    </script>
@endpush
