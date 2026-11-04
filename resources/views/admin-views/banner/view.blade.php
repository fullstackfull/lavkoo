@extends('layouts.admin.app')

@section('title', translate('banner'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
            <h2 class="h1 mb-1 text-capitalize d-flex align-items-center gap-2 flex-wrap">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/banner.png') }}" alt="">
                {{ translate('banner_Setup') }}
                <small>
                    <strong class="text-primary text-capitalize">
                        ({{ str_replace("_", " ", theme_root_path()) }})
                    </strong>
                </small>
            </h2>
        </div>

        <div class="row pb-4 d--none text-start" id="main-banner">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <form action="{{ route('admin.banner.store') }}" method="post" enctype="multipart/form-data"
                              class="banner_form form-advance-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
                            @csrf
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="h-100">
                                        <input type="hidden" id="id" name="id">
                                        <div class="form-group">
                                            <label for="name" class="form-label">
                                                {{ translate('banner_type') }}  <span class="text-danger">*</span>
                                            </label>
                                            <select class="custom-select" name="banner_type" required id="banner_type_select">
                                                <option value="" disabled>{{ translate('select_banner_type') }}</option>
                                                @foreach($bannerTypes as $key => $banner)
                                                    <option value="{{ $key }}">{{ $banner }}</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        {{-- Layout only applies to the grid banner types; the
                                             type select shows and hides this block. --}}
                                        <div class="form-group d-none" id="banner_layout_group">
                                            <div class="row g-3">
                                                <div class="col-md-7">
                                                    <label class="form-label">{{ translate('banner_layout') }}</label>
                                                    <select class="custom-select" name="layout">
                                                        <option value="full">{{ translate('full_width_row') }}</option>
                                                        <option value="half">{{ translate('half_width_beside_another') }}</option>
                                                        <option value="slider">{{ translate('inside_the_rotating_slider') }}</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-5">
                                                    <label class="form-label">{{ translate('priority') }}</label>
                                                    <input type="number" name="priority" class="form-control" value="0" min="0"
                                                           placeholder="0">
                                                </div>
                                            </div>
                                            <small class="text-muted">{{ translate('lower_numbers_come_first_in_the_grid') }}</small>
                                        </div>

                                        <div class="form-group" id="banner_resource_type">
                                            <label for="resource_id" class="form-label">
                                                {{ translate('resource_type') }}  <span class="text-danger">*</span>
                                            </label>
                                            <select class="custom-select action-display-data" name="resource_type" required>
                                                <option value="" disabled>{{ translate('select_resource_type') }}</option>
                                                <option value="product">{{ translate('product') }}</option>
                                                <option value="category">{{ translate('category') }}</option>
                                                <option value="shop">{{ translate('shop') }}</option>
                                                <option value="brand">{{ translate('brand') }}</option>
                                                <option value="custom">{{ translate('custom') }}</option>
                                            </select>
                                        </div>

                                        <div class="form-group mb-0" id="resource-product">
                                            <label for="product_id" class="form-label">
                                                {{ translate('product') }}
                                            </label>
                                            <select class="custom-select" name="product_id">
                                                @foreach($products as $product)
                                                    <option value="{{ $product['id'] }}">
                                                        {{ $product['name'] }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div class="form-group mb-0 d--none" id="resource-category">
                                            <label for="name" class="form-label">
                                                {{ translate('category') }}
                                            </label>
                                            <select class="custom-select" name="category_id">
                                                @foreach($categories as $category)
                                                    <option value="{{ $category['id'] }}">
                                                        {{ $category['label'] }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div class="form-group mb-0 d--none" id="resource-shop">
                                            <label for="shop_id" class="form-label">{{ translate('shop') }}</label>
                                            <select class="w-100 custom-select form-control" name="shop_id">
                                                @foreach($shops as $shop)
                                                    <option value="{{ $shop['id'] }}">{{ $shop['name'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div class="form-group mb-0 d--none" id="resource-brand">
                                            <label for="brand_id" class="form-label">
                                                {{ translate('brand') }}
                                            </label>
                                            <select class="custom-select" name="brand_id">
                                                @foreach($brands as $brand)
                                                    <option value="{{ $brand['id'] }}">{{ $brand['name'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div class="form-group mb-0 d--none" id="resource-custom-url">
                                            <label for="name" class="form-label">{{ translate('banner_URL') }} <span class="text-danger">*</span> </label>
                                            <input type="url" name="url" class="form-control" id="url"
                                                   placeholder="{{ translate('Enter_url') }}">
                                        </div>

                                    </div>
                                </div>
                                <div class="col-md-6 d-flex flex-column justify-content-center">
                                    <div class="d-flex justify-content-center align-items-center bg-section rounded-8 p-20 w-100 h-100">
                                        <div class="d-flex flex-column gap-30 w-100">
                                            <div class="text-center">
                                                <label for="" class="form-label fw-semibold mb-1">
                                                    {{ translate('banner_image') }}  <span class="text-danger">*</span>
                                                </label>
                                                <h4 class="mb-0"><span class="text-info-dark" id="theme_ratio"> ( {{ translate('ratio') }} 4:1 )</span></h4>
                                            </div>
                                            <div class="upload-file">
                                                <input type="file" name="image" class="upload-file__input single_file_input"
                                                       id="banner" accept="{{ getFileUploadFormats(skip: '.svg') }}" required  data-max-size="{{ getFileUploadMaxSize() }}"
                                                       data-required-msg="{{ translate('banner_image_is_required') }}"
                                                       value="">
                                                <div class="upload-file__wrapper ratio-4-1">
                                                    <div class="upload-file-textbox text-center">
                                                        <img width="34" height="34" class="svg"
                                                             src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}"
                                                             alt="image upload">
                                                        <h6 class="mt-1 fw-medium lh-base text-center">
                                                            <span class="text-info">
                                                                {{ translate('Click to upload') }}
                                                            </span>
                                                            <br>
                                                            {{ translate('or_drag_and_drop') }}
                                                        </h6>
                                                    </div>
                                                    <img class="upload-file-img" loading="lazy" src="" data-default-src=""
                                                         alt="">
                                                </div>
                                                <div class="overlay">
                                                    <div
                                                        class="d-flex gap-10 justify-content-center align-items-center h-100">
                                                        <button type="button" class="btn btn-outline-info icon-btn view_btn">
                                                            <i class="fi fi-sr-eye"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-outline-info icon-btn edit_btn">
                                                            <i class="fi fi-rr-camera"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <p class="fs-12 text-center max-w-360 m-auto">
                                                {{ getFileUploadFormats(skip: '.svg', asBladeMessage: true).' '. translate('Image_size'). ' : '. translate('Max').' '. getFileUploadMaxSize() . 'MB' }}
                                            </p>
                                            <p class="fs-12 text-center max-w-360 m-auto">
                                                {{ translate('banner_Image_ratio_is_not_same_for_all_sections_in_website.') }}
                                                {{ translate('please_review_the_ratio_before_upload') }}
                                            </p>

                                            {{-- Optional phone-shaped image served to the mobile apps; they
                                                 fall back to the image above when it is left empty. --}}
                                            <div class="text-center">
                                                <label for="banner-mobile" class="form-label fw-semibold mb-1">
                                                    {{ translate('mobile_app_image') }}
                                                    <span class="text-muted fs-12">({{ translate('optional') }})</span>
                                                </label>
                                                <h4 class="mb-0"><span class="text-info-dark"> ( {{ translate('ratio') }} 2:1 )</span></h4>
                                            </div>
                                            <div class="upload-file">
                                                <input type="file" name="mobile_image" class="upload-file__input single_file_input"
                                                       id="banner-mobile" accept="{{ getFileUploadFormats(skip: '.svg') }}"
                                                       data-max-size="{{ getFileUploadMaxSize() }}" value="">
                                                <div class="upload-file__wrapper ratio-2-1">
                                                    <div class="upload-file-textbox text-center">
                                                        <img width="34" height="34" class="svg"
                                                             src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}"
                                                             alt="image upload">
                                                        <h6 class="mt-1 fw-medium lh-base text-center">
                                                            <span class="text-info">{{ translate('Click to upload') }}</span>
                                                            <br>
                                                            {{ translate('or_drag_and_drop') }}
                                                        </h6>
                                                    </div>
                                                    <img class="upload-file-img" loading="lazy" src="" data-default-src="" alt="">
                                                </div>
                                                <div class="overlay">
                                                    <div class="d-flex gap-10 justify-content-center align-items-center h-100">
                                                        <button type="button" class="btn btn-outline-info icon-btn view_btn">
                                                            <i class="fi fi-sr-eye"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-outline-info icon-btn edit_btn">
                                                            <i class="fi fi-rr-camera"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <p class="fs-12 text-center max-w-360 m-auto">
                                                {{ translate('used_by_the_mobile_apps_where_a_wide_banner_would_crop_badly_on_a_phone') }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 d-flex justify-content-end flex-wrap gap-10">
                                    <button class="btn btn-secondary cancel px-4" type="reset">
                                        {{ translate('reset') }}
                                    </button>
                                    <button id="add" type="submit" class="btn btn-primary px-4">
                                        {{ translate('save') }}
                                    </button>
                                    <button id="update" class="btn btn-primary d--none text-white">
                                        {{ translate('update') }}
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- The theme's banners, ORGANIZED: one card per section, its images side by side with
             their shape, link state and a direct edit for each — the mosaic and "its four
             pictures" as one visible family instead of anonymous rows in the table below. --}}
        @if (!empty($themeGroups))
            <div class="row mb-3" id="theme-banner-groups">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h5 class="mb-0 text-capitalize">
                                <i class="fi fi-rr-brush"></i>
                                {{ translate('theme_banners_grouped_by_section') }}
                            </h5>
                            @if (Route::has('admin.theme.builder.index'))
                                <a href="{{ route('admin.theme.builder.index') }}" class="k-btn k-btn--secondary k-btn--sm">
                                    {{ translate('open_theme_builder') }}
                                </a>
                            @endif
                        </div>
                        <div class="card-body d-flex flex-column gap-3">
                            @foreach ($themeGroups as $group)
                                <div>
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                        <strong class="text-capitalize">{{ $group['label'] }}</strong>
                                        <span class="badge badge-soft-secondary">{{ translate($group['page']) }}</span>
                                        @if (!empty($group['display']) || !empty($group['layout']))
                                            <span class="badge badge-soft-primary">
                                                {{ translate($group['display'] ?: $group['layout']) }}
                                            </span>
                                        @endif
                                        <span class="badge {{ $group['status'] === 'published' ? 'badge-soft-success' : 'badge-soft-warning' }}">
                                            {{ translate($group['status']) }}
                                        </span>
                                        <span class="text-muted small">{{ count($group['cards']) }} {{ translate('images') }}</span>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2">
                                        @foreach ($group['cards'] as $card)
                                            @php $editable = !empty($card['banner_id']); @endphp
                                            <a href="{{ $editable ? route('admin.banner.update', ['id' => $card['banner_id']]) : (Route::has('admin.theme.builder.index') ? route('admin.theme.builder.index', ['page' => $group['page']]) : '#') }}"
                                               class="text-decoration-none border rounded p-1 d-flex flex-column gap-1"
                                               style="width:110px"
                                               title="{{ $card['title'] ?? '' }}">
                                                @php $frames = $card['frames'] ?? array_filter([$card['image'] ?? null]); @endphp
                                                <span class="position-relative d-block">
                                                    <img src="{{ $card['image'] ?: dynamicAsset(path: 'public/assets/back-end/img/400x400/img2.jpg') }}"
                                                         alt="" style="width:100%;height:64px;object-fit:cover;border-radius:6px"
                                                         class="{{ empty($card['published']) && $editable ? 'opacity-50' : '' }}">
                                                    {{-- A tile carrying several pictures looked identical to one carrying a
                                                         single picture; the count says so, and the strip below shows them. --}}
                                                    @if (count($frames) > 1)
                                                        <span class="badge badge-soft-dark position-absolute"
                                                              style="top:4px;inset-inline-end:4px">
                                                            <i class="fi fi-rr-copy"></i> {{ count($frames) }}
                                                        </span>
                                                    @endif
                                                </span>
                                                @if (count($frames) > 1)
                                                    <span class="d-flex gap-1">
                                                        @foreach ($frames as $frame)
                                                            <img src="{{ $frame }}" alt=""
                                                                 style="width:{{ floor(100 / count($frames)) }}%;height:18px;object-fit:cover;border-radius:3px">
                                                        @endforeach
                                                    </span>
                                                @endif
                                                <span class="d-flex flex-wrap gap-1">
                                                    @if (!empty($card['span']))
                                                        <span class="badge badge-soft-info">{{ translate($card['span'] . '_tile') }}</span>
                                                    @endif
                                                    @if ($editable)
                                                        <span class="badge badge-soft-success">{{ translate('edit') }}</span>
                                                    @else
                                                        <span class="badge badge-soft-secondary">{{ translate('in_builder') }}</span>
                                                    @endif
                                                    @if ($editable && empty($card['published']))
                                                        <span class="badge badge-soft-danger">{{ translate('unpublished') }}</span>
                                                    @endif
                                                </span>
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div class="row" id="banner-table">
            <div class="col-md-12">
                <x-k.data-view :title="translate('banner_table')" :count="$banners->total()"
                               searchName="searchValue" :searchValue="requestString('searchValue')"
                               :searchPlaceholder="translate('search_by_banner_type')">

                    <x-slot:actions>
                        {{-- The exact-type filter the page always had, now submitting itself —
                             it shares the searchValue param with the text search, so either
                             one narrows the same way the controller always expected. --}}
                        <form action="{{ url()->current() }}" method="GET">
                            <div class="select-wrapper">
                                <select class="form-control" name="searchValue" onchange="this.form.submit()"
                                        aria-label="{{ translate('banner_type') }}">
                                    <option value="">{{ translate('all') }}</option>
                                    @foreach($bannerTypes as $key => $banner)
                                        <option
                                            value="{{ $key }}" {{ request('searchValue') == $key ? 'selected':'' }}>{{ $banner }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </form>
                        <div id="banner-btn" class="d-flex flex-wrap gap-2">
                            <a href="{{ route('admin.banner.theme-layout') }}"
                               class="k-btn k-btn--secondary text-nowrap text-capitalize">
                                <x-k.icon name="grip" :size="15" />
                                {{ translate('theme_banners_as_displayed') }}
                            </a>
                            <a href="{{ route('admin.banner.placement-guide') }}"
                               class="k-btn k-btn--secondary text-nowrap text-capitalize">
                                <x-k.icon name="image" :size="15" />
                                {{ translate('placement_guide') }}
                            </a>
                            <button type="button" id="main-banner-add"
                                class="k-btn k-btn--primary text-nowrap text-capitalize">
                                <x-k.icon name="plus" :size="15" />
                                {{ translate('add_banner') }}
                            </button>
                        </div>
                    </x-slot:actions>

                    <table class="k-table">
                        <thead>
                        <tr>
                            <th>{{ translate('image') }}</th>
                            <th>{{ translate('banner_type') }}</th>
                            <th>{{ translate('where_it_appears') }}</th>
                            <th>{{ translate('resource_type') }}</th>
                            <th>{{ translate('published') }}</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($banners as $banner)
                            <tr id="data-{{ $banner->id}}">
                                <td>
                                    <img class="ratio-4-2 object-fit-cover border rounded" width="80" alt=""
                                         loading="lazy"
                                         src="{{ getStorageImages(path: $banner->photo_full_url , type: 'backend-banner') }}">
                                </td>
                                <td>{{ translate(str_replace('_',' ',$banner->banner_type)) }}</td>
                                <td>
                                    @php
                                        $__placement = app(\App\Services\BannerService::class)->getPlacementTag($banner->banner_type);
                                        $__resourceName = null;
                                        if ($banner->resource_type === 'category' && $banner->resource_id) {
                                            $__resourceName = $resourceNames['category'][$banner->resource_id] ?? null;
                                        } elseif ($banner->resource_type === 'brand' && $banner->resource_id) {
                                            $__resourceName = $resourceNames['brand'][$banner->resource_id] ?? null;
                                        }
                                    @endphp
                                    @if ($__placement)
                                        <span class="badge badge-soft-primary text-wrap text-start">
                                            <i class="fi fi-rr-marker"></i>
                                            {{ $__placement }}@if ($__resourceName) — {{ $__resourceName }}@endif
                                        </span>
                                    @endif
                                    @php
                                        $__themePlaces = array_merge(
                                            ($themeUsage['ids'][$banner->id] ?? []),
                                            ($themeUsage['types'][$banner->banner_type] ?? [])
                                        );
                                    @endphp
                                    @if (count($__themePlaces))
                                        <div class="d-flex flex-column gap-1 mt-1">
                                            @foreach (array_slice(array_unique($__themePlaces), 0, 3) as $__place)
                                                <a href="{{ route('admin.banner.theme-layout') }}"
                                                   class="badge badge-soft-info text-decoration-none text-wrap text-start">
                                                    <i class="fi fi-rr-brush"></i>
                                                    {{ $__place }}
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td>{{ translate(str_replace('_',' ',$banner->resource_type)) }}</td>
                                <td>
                                <form action="{{ route('admin.banner.status') }}" method="post"
                                      id="banner-status{{ $banner['id'] }}-form" class="no-reload-form reload-true">
                                    @csrf
                                    <input type="hidden" name="id" value="{{ $banner['id'] }}">
                                    <label class="switcher " for="banner-status{{ $banner['id'] }}">
                                        <input
                                            class="switcher_input custom-modal-plugin"
                                            type="checkbox" value="1" name="status"
                                            id="banner-status{{ $banner['id'] }}"
                                            {{ $banner['published'] == 1 ? 'checked' : '' }}
                                            data-modal-type="input-change-form"
                                            data-modal-form="#banner-status{{ $banner['id'] }}-form"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/banner-status-on.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/banner-status-off.png') }}"
                                            data-on-title="{{ translate('Want_to_Turn_ON').' '.translate(str_replace('_',' ',$banner->banner_type)).' '.translate('status') }}"
                                            data-off-title="{{ translate('Want_to_Turn_OFF').' '.translate(str_replace('_',' ',$banner->banner_type)).' '.translate('status') }}"
                                            data-on-message="<p>{{ translate('if_enabled_this_banner_will_be_available_on_the_website_and_customer_app') }}</p>"
                                            data-off-message="<p>{{ translate('if_disabled_this_banner_will_be_hidden_from_the_website_and_customer_app') }}</p>"
                                            data-on-button-text="{{ translate('turn_on') }}"
                                            data-off-button-text="{{ translate('turn_off') }}">
                                        <span class="switcher_control"></span>
                                    </label>
                                </form>
                                        </td>
                                <td>
                                    <div class="k-table__actions">
                                        <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                           title="{{ translate('edit') }}"
                                           href="{{ route('admin.banner.update',[$banner['id']]) }}">
                                            <x-k.icon name="edit" :size="15" />
                                        </a>
                                        <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon banner-delete-button"
                                           title="{{ translate('delete') }}"
                                           id="{{ $banner['id'] }}">
                                            <x-k.icon name="trash" :size="15" />
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>

                    @if(count($banners)==0)
                        <x-k.empty icon="image" :title="translate('no_banner_found')"
                                   :text="request('searchValue') ? translate('no_banner_matches_your_filter') : null" />
                    @endif

                    @if ($banners->total() > 0)
                        <x-slot:pager>
                            <span class="k-pager__info">
                                {{ translate('showing') }}
                                <span class="k-num">{{ $banners->firstItem() }}–{{ $banners->lastItem() }}</span>
                                {{ translate('of') }} <span class="k-num">{{ $banners->total() }}</span>
                            </span>
                            <div>{!! $banners->appends(request()->except('page'))->links() !!}</div>
                        </x-slot:pager>
                    @endif
                </x-k.data-view>
            </div>
        </div>
    </div>

    <span id="route-admin-banner-store" data-url="{{ route('admin.banner.store') }}"></span>
    <span id="route-admin-banner-delete" data-url="{{ route('admin.banner.delete') }}"></span>


@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/promotion/banner.js') }}"></script>
    <script>
        "use strict";

        $(document).on('ready', function () {
            getThemeWiseRatio();
        });
        let elementBannerTypeSelect = $('#banner_type_select');

        function getThemeWiseRatio() {
            let banner_type = elementBannerTypeSelect.val();
            let theme = '{{ theme_root_path() }}';
            {{-- Fed from BannerService so the form and the server agree on which types
                 take a layout; the JS used to carry its own copy of the list. --}}
            window.bannerGridTypes = {!! json_encode(\App\Services\BannerService::GRID_TYPES) !!};
            let theme_ratio = {!! json_encode(THEME_RATIO) !!};
            let get_ratio = theme_ratio[theme][banner_type];
            $('#theme_ratio').text(get_ratio);
        }

        {{-- Category/Brand banners MUST point at their resource or the save is rejected — which
             read as "the brand banner does not work". Lock the resource select to the required
             value the moment such a type is picked, so the mistake cannot be made. --}}
        let requiredResourceByType = {!! json_encode(\App\Services\BannerService::REQUIRED_RESOURCE_TYPES) !!};

        function lockResourceForBannerType() {
            let required = requiredResourceByType[elementBannerTypeSelect.val()] || null;
            let resourceSelect = $('select[name="resource_type"]');
            resourceSelect.find('option').prop('disabled', false);
            if (required) {
                resourceSelect.val(required).trigger('change');
                resourceSelect.find('option').each(function () {
                    if ($(this).val() !== required) $(this).prop('disabled', true);
                });
            }
        }

        elementBannerTypeSelect.on('change', function () {
            getThemeWiseRatio();
            lockResourceForBannerType();
        });

        $(document).on('ready', function () {
            lockResourceForBannerType();
        });
    </script>
@endpush
