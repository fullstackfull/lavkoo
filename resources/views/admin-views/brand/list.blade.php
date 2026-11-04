@extends('layouts.admin.app')

@section('title', translate('brand_List'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/brand.png') }}" alt="">
                {{ translate('brand_Setup') }}
            </h2>
        </div>

        <div class="row mt-20">
            <div class="col-md-12">
                <x-k.data-view :title="translate('brand_list')" :count="$brands->total()"
                               searchName="searchValue" :searchValue="requestString('searchValue')"
                               :searchPlaceholder="translate('search_by_brand_name')">

                    <x-slot:actions>
                        <a class="k-btn k-btn--secondary"
                           href="{{ route('admin.brand.export', ['searchValue' => request('searchValue')]) }}">
                            <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                        </a>
                        <button class="k-btn k-btn--primary" title="{{ translate('Add') }}"
                                data-bs-toggle="offcanvas" href="#brandAddOffcanvas">
                            <x-k.icon name="plus" :size="15" /> {{ translate('Add_New') }}
                        </button>
                    </x-slot:actions>

                    <table class="k-table">
                        <thead>
                        <tr>
                            <th>{{ translate('SL') }}</th>
                            <th>{{ translate('brand_name') }}</th>
                            <th>{{ translate('Image_alt_text') }}</th>
                            <th class="k-table__num">{{ translate('total_Product') }}</th>
                            <th class="k-table__num">{{ translate('total_Order') }}</th>
                            <th>{{ translate('status') }}</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @if(count($brands) > 0)
                            @foreach ($brands as $key => $brand)
                                <tr>
                                    <td><span class="k-num">{{ $brands->firstItem() + $key }}</span></td>
                                    <td>
                                        <div class="k-row">
                                            <img alt="" width="40" height="40"
                                                 style="border-radius:8px;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)"
                                                 src="{{ getStorageImages(path: $brand->image_full_url, type: 'backend-brand') }}">
                                            <span class="k-truncate" style="max-inline-size:200px" data-bs-toggle="tooltip"
                                                  data-bs-placement="right" aria-label="{{ $brand['defaultname'] }}"
                                                  data-bs-title="{{ $brand['defaultname'] }}">{{ $brand['defaultname'] }}</span>
                                        </div>
                                    </td>
                                    <td>{{$brand['image_alt_text'] ?? '-'}}</td>
                                    <td class="k-table__num"><span class="k-num">{{ $brand['brand_all_products_count'] }}</span></td>
                                    <td class="k-table__num"><span class="k-num">{{ $brand['brandAllProducts']->sum('order_details_count') }}</span></td>
                                    <td>
                                        <form action="{{ route('admin.brand.status-update') }}" method="post"
                                            id="brand-status{{ $brand['id'] }}-form" class="no-reload-form">
                                            @csrf
                                            <input type="hidden" name="id" value="{{ $brand['id'] }}">
                                            <label class="switcher mx-auto" for="brand-status{{ $brand['id'] }}">
                                                <input class="switcher_input custom-modal-plugin" type="checkbox"
                                                    value="1" name="status"
                                                    id="brand-status{{ $brand['id'] }}"
                                                    {{ $brand['status'] == 1 ? 'checked' : '' }}
                                                    data-modal-type="input-change-form" data-reload="true"
                                                    data-modal-form="#brand-status{{ $brand['id'] }}-form"
                                                    data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/brand-status-on.png') }}"
                                                    data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/brand-status-off.png') }}"
                                                    data-on-title = "{{ translate('Want_to_Turn_ON') . ' ' . $brand['defaultname'] . ' ' . translate('status') }}"
                                                    data-off-title = "{{ translate('Want_to_Turn_OFF') . ' ' . $brand['defaultname'] . ' ' . translate('status') }}"
                                                    data-on-message = "<p>{{ translate('if_enabled_this_brand_will_be_available_on_the_website_and_customer_app') }}</p>"
                                                    data-off-message = "<p>{{ translate('if_disabled_this_brand_will_be_hidden_from_the_website_and_customer_app') }}</p>"
                                                    data-on-button-text="{{ translate('turn_on') }}"
                                                    data-off-button-text="{{ translate('turn_off') }}">
                                                <span class="switcher_control"></span>
                                            </label>
                                        </form>
                                    </td>
                                    <td>
                                        <div class="k-table__actions">
                                            <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{ translate('View') }}"
                                               data-bs-toggle="offcanvas" href="#brandViewOffcanvas-{{ $brand['id'] }}">
                                                <x-k.icon name="eye" :size="15" />
                                            </a>
                                            <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                               title="{{ translate('edit') }}"
                                               data-bs-toggle="offcanvas" href="#brandEditOffcanvas-{{ $brand['id'] }}">
                                                <x-k.icon name="edit" :size="15" />
                                            </a>
                                            <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon" role="button" title="{{ translate('delete') }}"
                                               data-bs-toggle="modal" data-bs-target="#deleteModal-{{$brand['id']}}">
                                                <x-k.icon name="trash" :size="15" />
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        @endif
                        </tbody>
                    </table>

                    @if (count($brands) == 0)
                        <x-k.empty icon="store" :title="translate('no_brand_found')"
                                   :text="request('searchValue') ? translate('no_brand_matches_your_search') : null" />
                    @endif

                    @if ($brands->total() > 0)
                        <x-slot:pager>
                            <span class="k-pager__info">
                                {{ translate('showing') }}
                                <span class="k-num">{{ $brands->firstItem() }}–{{ $brands->lastItem() }}</span>
                                {{ translate('of') }} <span class="k-num">{{ $brands->total() }}</span>
                            </span>
                            <div>{!! $brands->appends(request()->except('page'))->links() !!}</div>
                        </x-slot:pager>
                    @endif
                </x-k.data-view>
            </div>
        </div>
    </div>
    <span id="route-admin-brand-delete" data-url="{{ route('admin.brand.delete') }}"></span>
    <span id="route-admin-brand-status-update" data-url="{{ route('admin.brand.status-update') }}"></span>
    <span id="get-brands" data-brands="{{ json_encode($brands) }}"></span>
    @include("admin-views.brand.partials._select-brand-delete-modal", ['brands' => $brands])
    @if(count($brands) > 0)
        @foreach ($brands as $key => $brand)
            @include("admin-views.brand.offcanvas._brand-view",['brand' => $brand])
            @include("admin-views.brand.offcanvas._brand-edit", ['brand' => $brand])
           @include("admin-views.brand.partials._delete-modal", ['brand' => $brand])
        @endforeach
    @endif
    @include("admin-views.brand.offcanvas._brand-add")

@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/products/products-management.js') }}"></script>
@endpush
