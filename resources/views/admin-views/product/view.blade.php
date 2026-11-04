@extends('layouts.admin.app')

@section('title', translate('product_Preview'))

@section('content')
    <div class="content container-fluid text-start">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div class="flex-grow-1">
                <h2 class="h1 text-capitalize mb-1">
                    {{ translate('product_details') }}
                </h2>
                <p class="mb-0">
                    {{ translate('Created_At') }} {{ date('d M, Y', strtotime($product['created_at'])) }}
                </p>
            </div>
            <div class="d-flex align-items-stretch justify-content-end flex-wrap gap-3">
                @if ($product['added_by'] == 'seller' && ($product['request_status'] == 0 || $product['request_status'] == 1))
                <button class="btn btn-danger min-w-120" data-bs-toggle="modal" data-bs-target="#publishNoteModal">
                    <i class="fi fi-sr-cross-circle"></i>
                    {{ translate('denied') }}
                </button>
                @endif
                @if ($product['request_status'] == 0)
                <button class="btn btn-success min-w-120 update-status" data-id="{{ $product['id'] }}"
                    data-redirect-route="{{ route('admin.products.list', ['vendor', 'status' => $product['request_status']]) }}"
                    data-message="{{ translate('want_to_approve_this_product_request') . '?' }}" data-status="1">
                    <i class="fi fi-sr-check-circle"></i>
                    {{ translate('approve') }}
                </button>
                @endif
                @if ($product['added_by'] == 'seller' && $product['request_status'] == 2)
                <div class="bg-danger bg-opacity-10 fs-12 px-2 py-1 rounded max-w-500">
                    <span>
                        <span class="text-danger"> {{ translate('Denied_Note') . ':' }}</span>
                        {{ translate($product['denied_note']) }}
                    </span>
                </div>
                @endif
                    <button type="button"
                            class="btn border text-danger bg-white icon-btn delete-data"
                            data-id="product-{{ $product['id'] }}"
                            style="--size: 45px;">
                        <i class="fi fi-rr-trash d-flex"></i>
                    </button>
                    <form action="{{ route('admin.products.delete', [$product['id']]) }}"
                          method="post" id="product-{{ $product['id'] }}" class="d-none">
                        @csrf
                        @method('delete')
                    </form>
                    <form action="{{ route('admin.products.status-update') }}" method="post"
                          id="product-status{{ $product['id'] }}-form"
                          class="admin-product-status-form">
                        @csrf
                        <input type="hidden" name="id" value="{{ $product['id'] }}">
                        <label class="form-control mb-0 d-flex gap-3 align-items-center h-100 w-max-content">
                        <span class="fw-semibold">{{ translate('status') }}</span>
                        <label class="switcher">
                            <label class="switcher mx-auto"
                                   for="products-status-update-{{ $product['id'] }}">
                                <input class="switcher_input custom-modal-plugin" type="checkbox"
                                       value="1" name="status"
                                       id="products-status-update-{{ $product['id'] }}"
                                       {{ $product['status'] == 1 ? 'checked' : '' }}
                                       data-modal-type="input-change-form"
                                       data-modal-form="#product-status{{ $product['id'] }}-form"
                                       data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/product-status-on.png') }}"
                                       data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/product-status-off.png') }}"
                                       data-on-title="{{ translate('Want_to_Turn_ON') . ' ' . str_replace("'",'`',$product['name']) . ' ' . translate('status') }}"
                                       data-off-title="{{ translate('Want_to_Turn_OFF') . ' ' . str_replace("'",'`',$product['name']) . ' ' . translate('status') }}"
                                       data-on-message="<p>{{ translate('if_enabled_this_product_will_be_available_on_the_website_and_customer_app') }}</p>"
                                       data-off-message="<p>{{ translate('if_disabled_this_product_will_be_hidden_from_the_website_and_customer_app') }}</p>">
                                <span class="switcher_control"></span>
                            </label>
                        </label>
                    </label>
                    </form>
                @php
                    $buttonText = $product['request_status'] == 1 ? translate('Edit') : translate('Edit_&_Approved');
                @endphp
                <a href="{{ route('admin.products.update', [$product['id']]) }}" class="btn btn-primary min-w-120"><i class="fi fi-sr-pencil"></i>{{$buttonText}}</a>
            </div>
        </div>
        <div class="row g-4">
            <div class="col-12">
                <div class="card card-top-bg-element">
                    <div class="card-body">
                        <div>
                            <div
                                class="d-flex flex-column flex-xl-row gap-4 h-100">
                                <div class="d-flex flex-column w-xl-280">
                                    <div class="pd-img-wrap position-relative">
                                        <div class="w-100 d-flex justify-content-center">
                                            <div class="swiper-container quickviewSlider2 border rounded aspect-1 inline-size-100 max-w-280 position-relative">
                                                <div class="position-absolute bottom-0 w-100 z-3">
                                                    <div class="p-3 d-flex justify-content-center align-items-center">
                                                        @if ($productActive && $isActive)
                                                            <a href="{{ route('product', $product['slug']) }}"
                                                               class="btn bg-primary--light text-primary p-2 rounded-50 rounded-pill px-3" target="_blank">
                                                                <i class="fi fi-rr-globe"></i>
                                                                <span class="fs-12">{{ translate('View in Website') }}</span>
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
                                                        <div class="swiper-slide position-relative rounded border aspect-1">
                                                            <div class="position-absolute start-0 top-0 w-100 h-100 z-3 product-slide-overlay">
                                                                <div class="d-flex justify-content-center align-items-center h-100">
                                                                    <div class="p-3 d-flex justify-content-center align-items-center gap-3">
                                                                        <a data-lightbox="products-details-image" href="{{ $imagePath }}" class="btn btn-outline-success btn-outline-success-dark bg-white icon-btn">
                                                                            <i class="fi fi-sr-eye"></i>
                                                                        </a>
                                                                        <button class="btn btn-outline-info bg-white icon-btn" data-bs-toggle="offcanvas" data-bs-target="#offcanvasEditImages">
                                                                            <i class="fi fi-sr-pencil"></i>
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="">
                                                                <a href="{{ $imagePath }}">
                                                                    <img class="h-100 aspect-1 rounded min-w-xl-280" alt="" src="{{ $imagePath }}">
                                                                </a>
                                                            </div>
                                                        </div>
                                                    @endforeach
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
                                                        <div class="swiper-slide position-relative rounded border aspect-1" role="group">
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
                                            <div class="mt-4 bg-section h-100 p-3 rounded d-flex justify-content-center align-items-center text-center">
                                                <div class="">
                                                    <h4 class="fs-14 mb-1 line-1" title="{{ $product->seller->shop->name  }}">{{ $product->seller->shop->name  }}</h4>
                                                    @php
                                                        $countTotalSellerProducts = count($totalSellerProducts ?? []);
                                                    @endphp
                                                    @if($countTotalSellerProducts > 0)
                                                        <p class="fs-12 mb-2">
                                                            {{ $countTotalSellerProducts }}
                                                            {{ $countTotalSellerProducts > 1 ? translate('products') : translate('product') }}
                                                        </p>
                                                    @else
                                                        <p class="fs-12 mb-2">0 {{ translate('product') }}</p>
                                                    @endif
                                                    <a href="{{ route('vendor-shop',['slug' => $product->seller->shop->slug]) }}" target="_blank" type="button" class="btn btn-primary rounded-pill" >
                                                        {{ translate('Visit_Shop') }}
                                                    </a>
                                                </div>
                                            </div>
                                        @endif
                                    @else
                                        <div class="mt-4 bg-section h-100 p-3 rounded d-flex justify-content-center align-items-center text-center">
                                            <div class="">
                                                <h4 class="fs-14 mb-1 line-1" title="{{ getInHouseShopConfig(key: 'name') }}">
                                                    {{ getInHouseShopConfig(key: 'name') }}
                                                </h4>
                                                @php
                                                    $countTotalAdminProducts = count($totalAdminProducts ?? []);
                                                @endphp
                                                @if($countTotalAdminProducts > 0)
                                                    <p class="fs-12 mb-2">
                                                        {{ $countTotalAdminProducts }}
                                                        {{ $countTotalAdminProducts > 1 ? translate('products') : translate('product') }}
                                                    </p>
                                                @else
                                                    <p class="fs-12 mb-2">0 {{ translate('product') }}</p>
                                                @endif
                                                <a href="{{ route('vendor-shop',['slug'=> getInHouseShopConfig(key:'slug')]) }}" target="_blank" type="button" class="btn btn-primary rounded-pill">
                                                    {{ translate('Visit_Shop') }}
                                                </a>
                                            </div>
                                        </div>
                                    @endif


                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-block flex-grow-1 w-max-md-100">
                                        @php($languages = getWebConfig(name: 'pnc_language'))
                                        @php($defaultLanguage = 'en')
                                        @php($defaultLanguage = $languages[0])
                                        <div class="row align-items-baseline g-2">
                                            <div class="col-xxl-8">
                                                <div class="position-relative nav--tab-wrapper mb-2">
                                                    <ul class="nav nav-pills nav--tab lang_tab" id="pills-tab" role="tablist">
                                                        @php($isFirstLanguage=1)
                                                        @foreach ($languages as $language)
                                                            <li class="nav-item py-0 {{ $isFirstLanguage == 1 ? 'ps-0' : '' }}" role="presentation">
                                                                @php($isFirstLanguage=0)
                                                                <a class="nav-link action-for-lang-tab {{ $language == $defaultLanguage ? 'active' : '' }}"
                                                                    href="javascript:" data-bs-toggle="pill" role="tab"
                                                                    data-target-tab="#{{ $language }}-page-form"
                                                                    data-target-group=".language-wise-details"
                                                                    id="{{ $language }}-link">
                                                                    {{ ucfirst(getLanguageName($language)) . '(' . strtoupper($language) . ')' }}
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
                                            </div>
                                            <div class="col-xxl-4">
                                                <div class="d-flex flex-wrap gap-2 align-items-center justify-content-end">
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
                                                        class="d-flex gap-3 flex-nowrap lh-1 badge text-bg-info bg-section shadow-sm px-3 fs-14 fw-bold justify-content-sm-end h-30 align-items-center">
                                                        <div
                                                            class="review-hover position-relative cursor-pointer d-flex gap-2 align-items-center">
                                                            <i class="fi fi-sr-star text-warning rotate-45deg" style="--bs-warning-rgb: 254, 133, 81;"></i>
                                                            <span>
                                                                {{ count($product->rating) > 0 ? number_format($product->rating[0]->average, 2, '.', ' ') : 0 }}
                                                            </span>
                                                            <div class="review-details-popup">
                                                                <h6 class="mb-2">{{ translate('rating') }}</h6>
                                                                <div class="">
                                                                    <ul class="list-unstyled list-unstyled-py-2 d-flex flex-column gap-2 mb-0">
                                                                        @php($total = $product->reviews->count())

                                                                        <li class="d-flex align-items-center fs-12">
                                                                            @php($five = getRatingCount($product['id'], 5))
                                                                            <span
                                                                                class="{{ Session::get('direction') === 'rtl' ? 'ml-3' : 'me-3' }}">
                                                                                {{ translate('5') }} {{ translate('star') }}
                                                                            </span>
                                                                            <div class="progress flex-grow-1">
                                                                                <div class="progress-bar" role="progressbar"
                                                                                    style="width: {{ $total == 0 ? 0 : ($five / $total) * 100 }}%;"
                                                                                    aria-valuenow="{{ $total == 0 ? 0 : ($five / $total) * 100 }}"
                                                                                    aria-valuemin="0" aria-valuemax="100">
                                                                                </div>
                                                                            </div>
                                                                            <span
                                                                                class="{{ Session::get('direction') === 'rtl' ? 'me-3' : 'ml-3' }}">{{ $five }}</span>
                                                                        </li>

                                                                        <li class="d-flex align-items-center fs-12">
                                                                            @php($four = getRatingCount($product['id'], 4))
                                                                            <span
                                                                                class="{{ Session::get('direction') === 'rtl' ? 'ml-3' : 'me-3' }}">{{ translate('4') }}
                                                                                {{ translate('star') }}</span>
                                                                            <div class="progress flex-grow-1">
                                                                                <div class="progress-bar" role="progressbar"
                                                                                    style="width: {{ $total == 0 ? 0 : ($four / $total) * 100 }}%;"
                                                                                    aria-valuenow="{{ $total == 0 ? 0 : ($four / $total) * 100 }}"
                                                                                    aria-valuemin="0" aria-valuemax="100"></div>
                                                                            </div>
                                                                            <span
                                                                                class="{{ Session::get('direction') === 'rtl' ? 'me-3' : 'ml-3' }}">{{ $four }}</span>
                                                                        </li>

                                                                        <li class="d-flex align-items-center fs-12">
                                                                            @php($three = getRatingCount($product['id'], 3))
                                                                            <span
                                                                                class="{{ Session::get('direction') === 'rtl' ? 'ml-3' : 'me-3' }}">{{ translate('3') }}
                                                                                {{ translate('star') }}</span>
                                                                            <div class="progress flex-grow-1">
                                                                                <div class="progress-bar" role="progressbar"
                                                                                    style="width: {{ $total == 0 ? 0 : ($three / $total) * 100 }}%;"
                                                                                    aria-valuenow="{{ $total == 0 ? 0 : ($three / $total) * 100 }}"
                                                                                    aria-valuemin="0" aria-valuemax="100"></div>
                                                                            </div>
                                                                            <span
                                                                                class="{{ Session::get('direction') === 'rtl' ? 'me-3' : 'ml-3' }}">{{ $three }}</span>
                                                                        </li>

                                                                        <li class="d-flex align-items-center fs-12">
                                                                            @php($two = getRatingCount($product['id'], 2))
                                                                            <span
                                                                                class="{{ Session::get('direction') === 'rtl' ? 'ml-3' : 'me-3' }}">{{ translate('2') }}
                                                                                {{ translate('star') }}</span>
                                                                            <div class="progress flex-grow-1">
                                                                                <div class="progress-bar" role="progressbar"
                                                                                    style="width: {{ $total == 0 ? 0 : ($two / $total) * 100 }}%;"
                                                                                    aria-valuenow="{{ $total == 0 ? 0 : ($two / $total) * 100 }}"
                                                                                    aria-valuemin="0" aria-valuemax="100"></div>
                                                                            </div>
                                                                            <span
                                                                                class="{{ Session::get('direction') === 'rtl' ? 'me-3' : 'ml-3' }}">{{ $two }}</span>
                                                                        </li>

                                                                        <li class="d-flex align-items-center fs-12">
                                                                            @php($one = getRatingCount($product['id'], 1))
                                                                            <span
                                                                                class="{{ Session::get('direction') === 'rtl' ? 'ml-3' : 'me-3' }}">{{ translate('1') }}
                                                                                {{ translate('star') }}</span>
                                                                            <div class="progress flex-grow-1">
                                                                                <div class="progress-bar" role="progressbar"
                                                                                    style="width: {{ $total == 0 ? 0 : ($one / $total) * 100 }}%;"
                                                                                    aria-valuenow="{{ $total == 0 ? 0 : ($one / $total) * 100 }}"
                                                                                    aria-valuemin="0" aria-valuemax="100"></div>
                                                                            </div>
                                                                            <span
                                                                                class="{{ Session::get('direction') === 'rtl' ? 'me-3' : 'ml-3' }}">{{ $one }}</span>
                                                                        </li>

                                                                    </ul>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <span class="border-start py-2" style="border-color: var(--border-dark)"></span>
                                                        <span class="text-dark">
                                                            {{ formatCompactNumber(value: $product->reviews->whereNotNull('comment')->count()) }}
                                                            <span class="fw-normal text-body">{{ translate('reviews') }}</span>
                                                        </span>
                                                    </div>
                                                    </div>
                                                </div>
                                            </div>

                                        <div class="tab-content mt-3" id="pills-tabContent">
                                            @foreach ($languages as $language)
                                                <?php
                                                if (count($product['translations'])) {
                                                    $translate = [];
                                                    foreach ($product['translations'] as $translation) {
                                                        if ($translation->locale == $language && $translation->key == 'name') {
                                                            $translate[$language]['name'] = $translation->value;
                                                        }
                                                        if ($translation->locale == $language && $translation->key == 'description') {
                                                            $translate[$language]['description'] = $translation->value;
                                                        }
                                                    }
                                                }
                                                ?>
                                                <div class="tab-pane fade {{ $language == $defaultLanguage ? 'show active' : '' }} language-wise-details"
                                                     id="{{ $language }}-page-form" aria-labelledby="{{ $language }}-link">
                                                    <div class="d-flex gap-2 align-items-center mb-2">
                                                        <h2 class="h1 mb-0 text-dark">
                                                            {{ $translate[$language]['name'] ?? $product['name'] }}
                                                        </h2>
                                                    </div>
                                                    <div class="position-relative bg-section rounded-10 p-2">
                                                        <div class="bg-white rounded p-3 text_editor_wrapper">
                                                            <div class="h-350 overflow-hidden">
                                                                <div class="rich-editor-html-content">
                                                                    {!! $translate[$language]['description'] ?? $product['details'] !!}
                                                                </div>
                                                            </div>
                                                            <div class="position-absolute w-100 blurry-div">
                                                                <div class="d-flex justify-content-center align-items-center h-100 p-3">
                                                                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="offcanvas" data-bs-target="#offcanvasProductDetails">
                                                                        {{ translate('See_More') }}
                                                                    </button>
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
                                    <div class="fw-normal text-capitalize fs-12 mb-1">{{ translate('Total_Qty_Sold') }} :</div>
                                    <h3 class="text-dark fs-18">{{ $product['total_qty_sold'] }}</h3>
                                </div>
                                <div class="d-flex flex-column">
                                    <div class="fw-normal text-capitalize fs-12 mb-1">{{ translate('Total_Order_Amount') }} :</div>
                                    <h3 class="text-dark fs-18">
                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $product['priceSum'] - $product['discountSum'])) }}
                                    </h3>
                                </div>
                            </div>

                            <div class="row gy-3 flex-grow-1">
                                <div class="col-sm-6 col-xl-4">
                                    <h4 class="mb-3 text-capitalize">{{ translate('general_information') }}</h4>

                                    <div class="pair-list">
                                        @if ($product?->product_type == 'physical')
                                            @if (isset($product->brand) && !empty($product->brand->default_name))
                                                <div>
                                                    <span class="key">{{ translate('brand') }}</span>
                                                    <span>:</span>
                                                    <span class="value">{{ $product->brand->default_name }}</span>
                                                </div>
                                            @endif
                                        @endif

                                        @if (isset($product->category) && !empty($product->category->default_name))
                                            <div>
                                                <span class="key">{{ translate('category') }}</span>
                                                <span>:</span>
                                                <span class="value">
                                                    {{ $product->category->default_name }}
                                                </span>
                                            </div>
                                        @endif

                                        <div>
                                            <span class="key text-capitalize">{{ translate('product_type') }}</span>
                                            <span>:</span>
                                            <span class="value">{{ translate($product->product_type) }}</span>
                                        </div>
                                        @if ($product->product_type == 'physical')
                                            <div>
                                                <span class="key text-capitalize">{{ translate('product_unit') }}</span>
                                                <span>:</span>
                                                <span class="value">{{ $product['unit'] }}</span>
                                            </div>
                                            <div>
                                                <span class="key">{{ translate('current_Stock') }}</span>
                                                <span>:</span>
                                                <span class="value">{{ $product->current_stock }}</span>
                                            </div>
                                        @endif
                                        <div>
                                            <span class="key">{{ translate('product_SKU') }}</span>
                                            <span>:</span>
                                            <span class="value">{{ $product->code }}</span>
                                        </div>
                                        @if ($product->barcode)
                                            <div>
                                                <span class="key">{{ translate('product_barcode') }}</span>
                                                <span>:</span>
                                                <span class="value direction-ltr">{{ $product->barcode }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                                <div class="col-sm-6 col-xl-4">
                                    <h4 class="mb-3 text-capitalize">{{ translate('price_information') }}</h4>

                                    <div class="pair-list">
                                        <div>
                                            <span class="key text-capitalize">{{ translate('unit_price') }}</span>
                                            <span>:</span>
                                            <span class="value">
                                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $product->unit_price), currencyCode: getCurrencyCode()) }}
                                            </span>
                                        </div>

                                        @if ($product->product_type == 'physical')
                                            <div>
                                                <span class="key text-capitalize">{{ translate('shipping_cost') }}</span>
                                                <span>:</span>
                                                <span class="value">
                                                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $product->shipping_cost)) }}
                                                    @if ($product->multiply_qty == 1)
                                                        ({{ translate('multiply_with_quantity') }})
                                                    @endif
                                                </span>
                                            </div>
                                        @endif

                                        @if (getProductPriceByType(product: $product, type: 'discount', result: 'value') > 0)
                                            <div>
                                                <span class="key text-capitalize">{{ translate('discount') }}</span>
                                                <span>:</span>
                                                @if ($product?->clearanceSale?->discount_type == 'percentage' ? '-' : '')
                                                @endif
                                                {{ getProductPriceByType(product: $product, type: 'discount', result: 'string') }}
                                            </div>
                                        @endif

                                        @if ($productWiseTax)
                                            @foreach ($product?->taxVats as $key => $taxVat)
                                                <div>
                                                    <span class="key">{{ $taxVat->tax->name }}</span>
                                                    <span>:</span>
                                                    <span class="value">
                                                        {{ $taxVat->tax->tax_rate }}%
                                                    </span>
                                                </div>
                                            @endforeach
                                        @endif
                                    </div>
                                </div>
                                @if (count($product->tags) > 0)
                                    <div class="col-sm-6 col-xl-4">
                                        <h4 class="mb-3">{{ translate('tags') }}</h4>
                                        <div class="pair-list">
                                            <div>
                                                <span class="value">
                                                    @foreach ($product->tags as $key => $tag)
                                                        {{ $tag['tag'] }}
                                                        @if ($key === count($product->tags) - 1)
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

            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header bg-primary bg-opacity-10">
                        <h3 class="text-dark mb-0">{{ translate('product_SEO_&_meta_data') }}</h3>
                    </div>
                    <div class="card-body">
                        <div>
                            <h4 class="mb-3 text-capitalize">
                                {{ $product?->seoInfo?->title ?? ($product->meta_title ?? translate('meta_title_not_found') . ' ' . '!') }}
                            </h4>
                        </div>
                        <p class="text-capitalize">
                            {{ $product?->seoInfo?->description ?? ($product->meta_description ?? translate('meta_description_not_found') . ' ' . '!') }}
                        </p>
                        @if ($product?->seoInfo?->image_full_url['path'] || $product->meta_image_full_url['path'])
                            <div class="d-flex flex-wrap gap-2">
                                <a
                                    href="{{ getStorageImages(path: $product?->seoInfo?->image_full_url['path'] ? $product?->seoInfo?->image_full_url : $product->meta_image_full_url, type: 'backend-product') }}"
                                    class="h-100px w-180 float-start overflow-hidden"
                                    data-lightbox="meta-thumbnail">
                                    <img class="object-fit-cover rounded h-100"
                                        src="{{ getStorageImages(path: $product?->seoInfo?->image_full_url['path'] ? $product?->seoInfo?->image_full_url : $product->meta_image_full_url, type: 'backend-product') }}"
                                        alt="{{ translate('meta_image') }}">
                                </a>
                                <div class="modal fade imgViewModal" id="imgViewModal-seo-{{ $product->id }}"
                                    tabindex="-1" aria-labelledby="imgViewModal-seo-{{ $product->id }}Label"
                                    role="dialog" aria-modal="true">
                                    <div class="modal-dialog modal-lg modal-dialog-centered">
                                        <div class="modal-content bg-transparent border-0">
                                            <div class="modal-body pt-0">
                                                <div class="imgView-slider owl-theme owl-carousel" dir="ltr">
                                                    <div class="imgView-item">
                                                        <div class="d-flex justify-content-between align-items-end">
                                                            <a href="{{ getStorageImages(path: $product?->seoInfo?->image_full_url['path'] ? $product?->seoInfo?->image_full_url : $product->meta_image_full_url, type: 'backend-product') }}"
                                                                class="d-flex align-items-center gap-2 mb-2">
                                                            </a>
                                                            <button type="button"
                                                                class="btn btn-close p-1 border-0 fs-10"
                                                                data-bs-dismiss="modal" aria-label="Close">
                                                            </button>
                                                        </div>
                                                        <div class="image-wrapper">
                                                            <div class="position-relative">
                                                                <div class="image-wrapper">
                                                                    <img class="image" alt=""
                                                                        src="{{ getStorageImages(path: $product?->seoInfo?->image_full_url['path'] ? $product?->seoInfo?->image_full_url : $product->meta_image_full_url, type: 'backend-product') }}">
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header bg-primary bg-opacity-10">
                        <h3 class="text-dark mb-0">{{ translate('product_video') }}</h3>
                    </div>
                    <div class="card-body">
                        <div>
                            <h4 class="mb-3 text-capitalize">
                                {{ $product['video_provider'] . ' ' . translate('video_link') }}
                            </h4>
                        </div>
                        @if ($product['video_url'])
                            <a href="{{ str_contains($product->video_url, 'https://') || str_contains($product->video_url, 'http://') ? $product['video_url'] : 'javascript:' }}"
                                target="_blank"
                                class="text-primary text-underline {{ str_contains($product->video_url, 'https://') || str_contains($product->video_url, 'http://') ? '' : 'cursor-default' }}">
                                {{ $product['video_url'] }}
                            </a>
                            <div class="d-flex flex-wrap gap-3 align-items-center mt-4">
                                <a href="#video-view-modal" data-bs-toggle="modal">
                                    <div class="position-relative h-100px w-180">
                                        <img class="object-fit-cover rounded-10 h-100" src="{{ dynamicAsset(path: 'public/assets/back-end/img/video-preview-demo.png') }}" alt="">
                                        <div class="position-absolute top-50 start-50 translate-middle">
                                            <button type="button" class="btn text-C2A7A7 bg-white btn-circle">
                                                <i class="fi fi-sr-play"></i>
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
                    <div class="k-table-wrap">
                        <table class="k-table">
                            <thead>
                                <tr>
                                    <th>{{ translate('SL') }}</th>
                                    <th>{{ translate('Review_ID') }}</th>
                                    <th>{{ translate('reviewer') }}</th>
                                    <th>{{ translate('rating') }}</th>
                                    <th>{{ translate('review') }}</th>
                                    <th>{{ translate('Reply') }}</th>
                                    <th class="text-center">{{ translate('date') }}</th>
                                    <th class="text-center">{{ translate('Status') }}</th>
                                    <th class="text-center">{{ translate('action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($reviews as $key => $review)
                                    @if (isset($review->customer))
                                        <tr>
                                            <td>{{ $reviews->firstItem() + $key }}</td>
                                            <td class="text-center">
                                                {{ $review->id }}
                                            </td>
                                            <td>
                                                <a class="d-flex gap-2 align-items-center"
                                                    href="{{ route('admin.customer.view', [$review['customer_id']]) }}">
                                                    <div class="avatar rounded">
                                                        <img class="avatar-img aspect-1"
                                                            src="{{ getStorageImages(path: $review->customer->image_full_url, type: 'backend-profile') }}"
                                                            alt="">
                                                    </div>
                                                    <div>
                                                        <span class="d-block h5 text-hover-primary mb-0">
                                                            {{ $review->customer['f_name'] . ' ' . $review->customer['l_name'] }}
                                                            <i class="fi fi-sr-shield-trust text-primary" data-bs-toggle="tooltip"
                                                                data-bs-placement="top" data-bs-title="Verified Customer"></i>
                                                        </span>
                                                        <span class="d-block text-body">
                                                            {{ $review->customer->email ?? '' }}
                                                        </span>
                                                    </div>
                                                </a>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2 text-primary">
                                                    <i class="fi fi-sr-star"></i>
                                                    <span>{{ $review->rating }}</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="text-wrap max-w-400 min-w-200">
                                                    <p class="line-2 max-w-250 word-break" data-bs-toggle="tooltip" @if($review['comment']) data-bs-title="{{ $review['comment'] }}" @endif>
                                                        {{ $review['comment'] }}
                                                    </p>
                                                    @if (count($review->attachment_full_url) > 0)
                                                        @foreach ($review->attachment_full_url as $img)
                                                            <a class="aspect-1 float-start overflow-hidden"
                                                                href="{{ getStorageImages(path: $img, type: 'backend-product') }}"
                                                                data-lightbox="review-gallery{{ $review['id'] }}">
                                                                <img class="p-2" width="60" height="60"
                                                                    src="{{ getStorageImages(path: $img, type: 'backend-product') }}"
                                                                    alt="{{ translate('review_image') }}">
                                                            </a>
                                                        @endforeach
                                                    @endif
                                                </div>
                                            </td>
                                            <td>
                                                <div class="text-wrap max-w-400 min-w-200">
                                                    <p class="line-2 max-w-250 word-break" data-bs-toggle="tooltip" @if($review?->reply?->reply_text) data-bs-title="{{ $review?->reply?->reply_text }}" @endif>
                                                        {{ $review?->reply?->reply_text ?? '-' }}
                                                    </p>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                {{ date('d M Y H:i:s', strtotime($review['created_at'])) }}
                                            </td>
                                            <td>
                                                <form action="{{ route('admin.reviews.status') }}" method="POST"
                                                    id="reviews-status{{ $review['id'] }}-form">
                                                    @csrf
                                                    <input name="id" value="{{ $review['id'] }}" hidden>
                                                    <label class="switcher mx-auto" for="reviews-status{{ $review['id'] }}">
                                                        <input class="switcher_input custom-modal-plugin" type="checkbox"
                                                            value="1" name="status"
                                                            id="reviews-status{{ $review['id'] }}"
                                                            {{ $review['status'] == 1 ? 'checked' : '' }}
                                                            data-modal-type="input-change-form"
                                                            data-modal-form="#reviews-status{{ $review['id'] }}-form"
                                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/customer-reviews-on.png') }}"
                                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/customer-reviews-off.png') }}"
                                                            data-on-title="{{ translate('Want_to_Turn_ON_Customer_Reviews') }}"
                                                            data-off-title="{{ translate('Want_to_Turn_OFF_Customer_Reviews') }}"
                                                            data-on-message="<p>{{ translate('if_enabled_anyone_can_see_this_review_on_the_user_website_and_customer_app') }}</p>"
                                                            data-off-message="<p>{{ translate('if_disabled_this_review_will_be_hidden_from_the_user_website_and_customer_app') }}</p>"
                                                            data-on-button-text="{{ translate('turn_on') }}"
                                                            data-off-button-text="{{ translate('turn_off') }}">
                                                        <span class="switcher_control"></span>
                                                    </label>
                                                </form>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-2 justify-content-center">
                                                    <div data-bs-toggle="modal"
                                                        data-bs-target="#review-view-for-{{ $review['id'] }}">
                                                        <a class="btn btn-outline-info icon-btn"
                                                            data-bs-title="{{ translate('View') }}" data-bs-toggle="tooltip">
                                                            <i class="fi fi-rr-eye"></i>
                                                        </a>
                                                    </div>

                                                    @if (isset($review->product) && $review?->product?->added_by == 'admin')
                                                        <div data-bs-toggle="modal"
                                                            data-bs-target="#review-update-for-{{ $review['id'] }}">
                                                            @if ($review?->reply)
                                                                <a class="btn btn-outline-primary icon-btn"
                                                                    data-bs-title="{{ translate('Update_Review') }}"
                                                                    data-bs-toggle="tooltip">
                                                                    <i class="fi fi-sr-pencil"></i>
                                                                </a>
                                                            @else
                                                                <div class="btn btn-outline-primary icon-btn"
                                                                    data-bs-title="{{ translate('Review_Reply') }}"
                                                                    data-bs-toggle="tooltip">
                                                                    <i class="fi fi-sr-reply-all"></i>
                                                                </div>
                                                            @endif
                                                        </div>
                                                    @elseif($review?->product?->added_by == 'seller')
                                                        <div>
                                                            <a class="btn btn-outline-primary icon-btn"
                                                                data-bs-title="{{ translate('Admin_can_not_reply_to_vendor_product_review') }}"
                                                                data-bs-toggle="tooltip">
                                                                @if ($review?->reply)
                                                                    <i class="fi fi-sr-pencil"></i>
                                                                @else
                                                                    <i class="fi fi-sr-reply-all"></i>
                                                                @endif
                                                            </a>
                                                        </div>
                                                    @else
                                                        <div>
                                                            <a class="btn btn-outline-primary icon-btn"
                                                                data-bs-title="{{ translate('product_not_found') }}"
                                                                data-bs-toggle="tooltip">
                                                                @if ($review?->reply)
                                                                    <i class="fi fi-sr-pencil"></i>
                                                                @else
                                                                    <i class="fi fi-sr-reply-all"></i>
                                                                @endif
                                                            </a>
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

                    @foreach ($reviews as $key => $review)
                        @if (isset($review->customer))
                            <div class="modal fade" id="review-update-for-{{ $review['id'] }}" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header border-0 d-flex justify-content-end">
                                            <button type="button" class="btn btn-circle border-0 fs-12 text-body bg-section2 shadow-none"
                                                    style="--size: 2rem;" data-bs-dismiss="modal" aria-label="Close">
                                                <i class="fi fi-sr-cross"></i>
                                            </button>
                                        </div>
                                        <form method="POST" action="{{ route('admin.reviews.add-review-reply') }}">
                                            @csrf
                                            <div class="modal-body pt-0">
                                                <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                                                    @if(isset($review->product))
                                                        <img src="{{ getStorageImages(path: $review->product->thumbnail_full_url, type: 'backend-product') }}"
                                                             width="60" class="rounded aspect-1 border" alt="{{ translate('Product') }}">
                                                        <div class="flex-grow-1">
                                                            <h5 class="mb-1 fs-14 text-dark">{{ $review->product['name'] }}</h5>
                                                            @if($review['order_id'])
                                                                <span class="fs-12 text-muted">{{ translate('Order_ID') }} #{{ $review['order_id'] }}</span>
                                                            @endif
                                                        </div>
                                                    @else
                                                        <span class="text-dark">{{ translate('product_not_found') }}</span>
                                                    @endif
                                                </div>

                                                <div class="bg-section p-3 rounded border mb-3">
                                                    <div class="d-flex gap-2 align-items-center mb-2">
                                                        <img class="h-30 aspect-1 rounded-circle"
                                                             src="{{ getStorageImages(path: $review->customer?->image_full_url, type: 'backend-profile') }}"
                                                             alt="{{ translate('Customer') }}">
                                                        <span class="fs-14 fw-medium text-dark">
                                                    {{ $review->customer?->f_name ?? translate('Customer') }}
                                                </span>
                                                    </div>
                                                    <p class="mb-0 fs-14">{{ $review['comment'] ?? translate('No comment found') }}</p>
                                                </div>

                                                @if(count($review->attachment_full_url) > 0)
                                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                                        @foreach ($review->attachment_full_url as $img)
                                                            <a href="{{ getStorageImages(path: $img, type: 'backend-basic') }}"
                                                               data-lightbox="review-gallery-modal{{ $review['id'] }}">
                                                                <img width="45" class="rounded aspect-1 border"
                                                                     src="{{ getStorageImages(path: $img, type: 'backend-basic') }}"
                                                                     alt="{{ translate('review_image') }}">
                                                            </a>
                                                        @endforeach
                                                    </div>
                                                @endif

                                                <label class="form-label fw-bold">
                                                    {{ translate('Reply') }}
                                                </label>
                                                <input type="hidden" name="review_id" value="{{ $review['id'] }}">
                                                <textarea class="form-control text-area-max-min" rows="3" name="reply_text"
                                                          placeholder="{{ translate('Write_the_reply_of_the_product_review') }}...">{{ $review?->reply?->reply_text ?? '' }}</textarea>

                                                <div class="text-end mt-4">
                                                    <button type="submit" class="btn btn-primary">
                                                        {{ $review?->reply?->reply_text ? translate('Update') : translate('submit') }}
                                                    </button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endif

                        @include("admin-views.reviews._review-modal", ['review' => $review])
                    @endforeach

                    <div class="table-responsive mt-4">
                        <div class="px-4 d-flex justify-content-lg-end">
                            {!! $reviews->links() !!}
                        </div>
                    </div>

                    @if (count($reviews) == 0)
                        @include(
                            'layouts.admin.partials._empty-state',
                            ['text' => 'no_review_found'],
                            ['image' => 'default']
                        )
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

    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasProductDetails"
         aria-labelledby="offcanvasProductDetailsLabel" style="--bs-offcanvas-width: 750px;">
        <div class="offcanvas-header d-block p-0">
            <div class="bg-body mb-1 d-flex justify-content-between align-items-center gap-3 p-3">
                @foreach ($languages as $language)
                    <h3 id="product-name-heading-{{ $language }}" class="mb-0 product-name-heading-title {{ $language == $defaultLanguage ? '' : 'd-none' }}">
                        {{ $translate[$defaultLanguage]['name'] ?? $product['name'] }}
                    </h3>
                @endforeach
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
            </div>

            <div class="position-relative nav--tab-wrapper">
                <ul class="nav nav-pills nav--tab lang_tab" id="offcanvas-pills-tab" role="tablist">
                    @foreach ($languages as $language)
                        <li class="nav-item" role="presentation">
                            <a class="nav-link action-for-lang-tab-offcanvas {{ $language == $defaultLanguage ? 'active' : '' }}"
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
        </div>

        <div class="offcanvas-body">
            <div class="tab-content" id="offcanvas-pills-tabContent">
                @foreach ($languages as $language)
                    <div class="tab-pane fade language-wise-offcanvas-details {{ $language == $defaultLanguage ? 'show active' : '' }}"
                         id="{{ $language }}-offcanvas-form"
                         aria-labelledby="{{ $language }}-link" role="tabpanel">
                        <div>
                            <div class="rich-editor-html-content">
                                {!! $translate[$language]['description'] ?? $product['details'] !!}
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="modal fade" id="publishNoteModal" tabindex="-1" aria-labelledby="exampleModalLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header border-0 d-flex justify-content-between align-items-center gap-3">
                    <h3 class="modal-title" id="exampleModalLabel">
                        {{ translate('deined_note') }}
                    </h3>
                    <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                        data-bs-dismiss="modal" aria-label="Close">
                    </button>
                </div>
                <form class="form-group" action="{{ route('admin.products.deny', ['id' => $product['id']]) }}"
                    method="post" id="product-status-denied">
                    @csrf
                    <div class="modal-body">
                        <textarea class="form-control text-area-max-min" name="denied_note" rows="3" data-maxlength="100"></textarea>
                        <div class="d-flex justify-content-end">
                            <span class="text-body-light"></span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary"
                            data-bs-dismiss="modal">{{ translate('close') }}
                        </button>
                        <button type="button" class="btn btn-primary form-submit"
                            data-message="{{ translate('want_to_reject_this_product_request') }}"
                            data-redirect-route="{{ route('admin.products.list', ['vendor', 'status' => $product['request_status']]) }}"
                            data-form-id="product-status-denied">{{ translate('submit') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <span id="get-update-status-route" data-action="{{ route('admin.products.approve-status') }}"></span>

    @include('admin-views.product.partials.modal._video-view-modal')
    @include('admin-views.product.partials.offcanvas._edit-images-offcanvas', ['product' => $product])

@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin/product-view.js') }}"></script>
    <script>
        'use strict';

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

        $('.nav-link.action-for-lang-tab').on('click', function () {
            setTimeout(() => {
                $('.text_editor_wrapper:visible').each(function () {
                    updateBlurryDiv($(this));
                });
            }, 300);
        });

    </script>

@endpush
