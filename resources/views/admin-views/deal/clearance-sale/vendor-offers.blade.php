@php
    use Carbon\Carbon;
    use Illuminate\Support\Facades\Session;
@endphp
@extends('layouts.admin.app')

@section('title', translate('Vendor_Offers'))

@section('content')
    @php($direction = Session::get('direction'))
    <div class="content container-fluid">
        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/note.png') }}" alt="">
                {{ translate('clearance_sale') }}
            </h2>
        </div>

        @include('admin-views.deal.clearance-sale.partials.clearance-sale-inline-menu')

        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-2 align-items-center">
                    <div class="col-md-8 col-xl-9">
                        <h2>{{ translate('Show Clearance Offer in Home Page') }}</h2>
                        <p class="m-0">
                            {{ translate('You_can_highlight_all clearance offer products in home page to increase customer reach') }}
                        </p>
                    </div>
                    <div class="col-md-4 col-xl-3">
                        <div class="d-flex justify-content-between align-items-center border rounded px-3 py-2">
                            <h5 class="mb-0 fw-normal">{{ translate('Show Offer in home page ?') }}</h5>
                            <form action="{{ route('admin.deal.clearance-sale.update-vendor-offer-status') }}" data-from="clearance-sale"
                                  method="post" id="clearance-sale-vendor-offer-status-form" class="no-reload-form">
                                @csrf
                                <input type="hidden" name="show-offer-id" value="{{ isset($clearanceConfig) ? $clearanceConfig['id'] : null}}">
                                @php($showInHomepage = getWebConfig('stock_clearance_vendor_offer_in_homepage'))
                                <label class="switcher" for="clearance-sale-vendor-offer-status">
                                    <input
                                        class="switcher_input custom-modal-plugin"
                                        type="checkbox" value="1" name="homepage-status"
                                        id="clearance-sale-vendor-offer-status"
                                        {{  $showInHomepage == 1 ? 'checked':'' }}
                                        data-modal-type="input-change-form"
                                        data-modal-form="#clearance-sale-vendor-offer-status-form"
                                        data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/clearance-sale-on.png') }}"
                                        data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/clearance-sale-off.png') }}"
                                        data-on-title="{{ translate('Want_to_show_clearance_offer_in_homepage') }}"
                                        data-off-title="{{ translate('Want_to_hide_clearance_offer_in_homepage') }}"
                                        data-on-message="<p>{{ translate('if_enabled_this_product_will_be_available_on_the_website_and_customer_app') }}</p>"
                                        data-off-message="<p>{{ translate('if_disabled_this_product_will_be_hidden_from_the_website_and_customer_app') }}</p>"
                                        data-on-button-text="{{ translate('turn_on') }}"
                                        data-off-button-text="{{ translate('turn_off') }}">
                                    <span class="switcher_control"></span>
                                </label>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-body">
                <div class="border-bottom pb-3">
                    <h3>{{ translate('Add Vendor') }}</h3>
                    <p>
                        {{ translate('Alongside with your in-house product , you can highlight vendor’s product who has activate their clearance offer.') }}
                    </p>
                </div>
                <div class="mt-3">
                    <div class="position-relative dropdown">
                        <div class="search-form" data-bs-toggle="dropdown" aria-expanded="false">
                            <input type="text" class="form-control ps-5 search-vendor-for-clearance-sale" placeholder="{{ translate('Search_Vendors') }}">
                            <span
                                class="fi fi-rr-search position-absolute inset-inline-start-0 top-0 h-40 d-flex align-items-center ps-2"></span>
                        </div>
                        <div class="dropdown-menu select-clearance-vendor-search w-100 px-2">
                            <div class="d-flex flex-column max-h-200 overflow-y-auto overflow-x-hidden search-result-box">
                                @include('admin-views.deal.clearance-sale.partials._search-vendor', ['vendorList' => $vendorList])
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <h3 class="mb-3 d-flex gap-1 align-items-center">
                    {{ translate('Vendor_List') }}
                    <span class="badge text-dark bg-body-secondary fw-semibold rounded-50 fs-14">{{ $vendorList->count() > 0? count($vendorList) : null }}</span>
                </h3>
                @if($vendorList->count() > 0)
                    <div class="k-table-wrap">
                        <table
                            class="k-table">
                            <thead>
                            <tr>
                                <th>{{ translate('sl') }}</th>
                                <th>{{ translate('shop_info')}}</th>
                                <th>{{ translate('valid_until') }}</th>
                                <th class="text-center">{{ translate('total_products') }}</th>
                                <th class="text-center">{{ translate('status') }}</th>
                                <th class="text-center">{{ translate('action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                                @foreach($vendorList as $key => $vendor)
                                    <tr>
                                        <td>{{ $key + 1 }}</td>
                                        <td>
                                            <a href="{{ route('admin.vendors.view', ['id' => $vendor['user_id'], 'tab' => 'clearance_sale']) }}" class="text-dark text-hover-primary d-flex align-items-center gap-2">
                                                <img src="{{ getStorageImages(path:$vendor?->shop?->image_full_url , type: 'shop') }}"
                                                     class="rounded" alt="" width="50">
                                                <div class="max-w-200">
                                                    <h6 class="fs-14">
                                                        {{$vendor?->shop?->name}}
                                                    </h6>
                                                    <div class="fs-12 text-dark opacity-75 border-between wrap">
                                                        <span class="parent">
                                                             <span class="opacity-75">({{$vendor->review_count}} {{ translate('review') }})</span>
                                                         </span>
                                                        <span class="parent">
                                                             <span class="opacity-75"><i class="fi fi-sr-star text-warning"></i>{{number_format($vendor['average_rating'],1)}}</span>
                                                        </span>
                                                    </div>
                                                </div>
                                            </a>
                                        </td>
                                        <td>
                                            <span class="fs-14 text-dark fw-medium">{{ $vendor->duration_end_date->format('d F Y, h:i A') }}</span>
                                        </td>
                                        <td class="text-center"><span class="fs-14 text-dark fw-medium"></span>{{ $vendor->products_count }}</td>
                                        <td>
                                            <div class="d-flex justify-content-center">
                                                <form action="{{ route('admin.deal.clearance-sale.update-vendor-status') }}" method="post" data-from="vendor-status"
                                                      id="vendor-status{{ $vendor['id']}}-form" class="no-reload-form">
                                                    @csrf
                                                    <input type="hidden" name="id" value="{{ $vendor['id']}}">
                                                    <label class="switcher mx-auto" for="vendor-status{{ $vendor['id']}}">
                                                        <input
                                                            class="switcher_input custom-modal-plugin"
                                                            type="checkbox" value="1" name="status"
                                                            id="vendor-status{{ $vendor['id']}}"
                                                            {{ $vendor['show_in_homepage'] == 1 ? 'checked' : '' }}
                                                            data-modal-type="input-change-form"
                                                            data-modal-form="#vendor-status{{ $vendor['id']}}-form"
                                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/clearance-sale-on.png') }}"
                                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/clearance-sale-off.png') }}"
                                                            data-on-title="{{ translate('Want_to_show').' '.$vendor?->shop?->name.' '.translate('clearance_offer_in_homepage') }}"
                                                            data-off-title="{{ translate('Want_to_hide').' '.$vendor?->shop?->name.' '.translate('clearance_offer_in_homepage') }}"
                                                            data-on-message="<p>{{ translate('if_enabled_this_product_will_be_available_on_the_website_and_customer_app') }}</p>"
                                                            data-off-message="<p>{{ translate('if_disabled_this_product_will_be_hidden_from_the_website_and_customer_app') }}</p>"
                                                            data-on-button-text="{{ translate('turn_on') }}"
                                                            data-off-button-text="{{ translate('turn_off') }}">
                                                        <span class="switcher_control"></span>
                                                    </label>
                                                </form>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex justify-content-center gap-2">
                                                <a title="Delete" class="btn btn-outline-danger icon-btn delete-data" data-id="vendor-{{ $vendor['id']}}" href="javascript:">
                                                    <i class="fi fi-rr-trash"></i>
                                                </a>
                                                <a class="btn btn-outline-primary icon-btn" title="View"
                                                   href="{{ route('admin.vendors.view', ['id' => $vendor['user_id'], 'tab' => 'clearance_sale']) }}">
                                                    <i class="fi fi-sr-eye"></i>
                                                </a>
                                            </div>
                                            <form action="{{ route('admin.deal.clearance-sale.vendor-delete',[$vendor['id']]) }}"
                                                  method="post" id="vendor-{{ $vendor['id']}}">
                                                @csrf @method('delete')
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="p-4 bg-chat rounded text-center mt-3">
                        <div class="py-5">
                            <img src="{{ dynamicAsset('public/assets/new/back-end/img/empty-vendor.png') }}" width="58"
                                 alt="">
                            <div class="mx-auto my-3 max-w-360">
                                {{ translate('No vendors are added') }}
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

    </div>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin/deal.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin/clearance-sale-script.js') }}"></script>
@endpush
