@extends('layouts.admin.app')

@section('title', translate('Category'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex gap-10">
                <img src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/brand-setup.png') }}" alt="">
                {{ translate('Category_Setup') }}
            </h2>
        </div>

        <div class="row mt-20" id="cate-table">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body d-flex flex-column gap-20">
                        <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                            <h3 class="mb-0">
                                {{ translate('category_List') }}
                                <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">
                                    {{ $categories->total() }}
                                </span>
                            </h3>
                            <div class="d-flex flex-wrap gap-3 align-items-center">
                                <form action="{{ url()->current() }}" method="GET" class="flex-grow-1">
                                    <div class="input-group min-w-300">
                                        <input id="" type="search" name="searchValue" class="form-control"
                                               placeholder="{{ translate('search_by_category') }}"
                                               value="{{ requestString('searchValue') }}">
                                        <div class="input-group-append search-submit">
                                            <button type="submit">
                                                <i class="fi fi-rr-search"></i>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                                <div class="dropdown">
                                    <a type="button" class="btn btn-outline-primary"
                                       href="{{ route('admin.category.export', ['searchValue' => request('searchValue')]) }}">
                                        <i class="fi fi-sr-inbox-in"></i>
                                        <span class="fs-12">{{ translate('export') }}</span>
                                    </a>
                                </div>
                                <div>
                                    <button class="btn btn-primary" title="{{ translate('Add') }}"
                                            data-bs-toggle="offcanvas" href="#categoryAddOffcanvas"
                                    > + {{ translate('Add_Category') }}</button>
                                </div>
                            </div>
                        </div>

                        <div class="k-table-wrap">
                            <table class="k-table">
                                <thead>
                                <tr>
                                    <th>{{ translate('SL') }}</th>
                                    <th>{{ translate('category_name') }}</th>
                                    @if ($categoryWiseTax)
                                        <th>{{ translate('tax_rate') }}</th>
                                    @endif
                                    <th class="text-center">{{ translate('priority') }}</th>
                                    <th class="text-center">{{ translate('Home_Category_Status') }}</th>
                                    @if (getWebConfig(name: 'auction_feature_status'))
                                        <th class="text-center">{{ translate('Auction') }} {{ translate('Home_Status') }}</th>
                                    @endif
                                    <th class="text-center">{{ translate('action') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($categories as $key => $category)
                                    <tr>
                                        <td><span class="k-num">{{ $categories->firstItem() + $key }}</span></td>
                                        <td class="d-flex justify-content-start align-items-center gap-2">
                                            <div
                                                class="avatar-50 d-flex align-items-center rounded overflow-hidden">
                                                <img class="w-100 h-100 object-fit-cover" alt=""
                                                     src="{{ getStorageImages(path: $category->icon_full_url, type: 'backend-category') }}">
                                            </div>
                                            <div>
                                                <div class="fs-14 line-1 max-w-200">{{ $category['defaultname'] }}</div>
                                                <span class="fs-12 opacity-70">{{ translate('ID') }} #{{ $category['id'] }}</span>
                                            </div>
                                        </td>
                                        @if ($categoryWiseTax)
                                            <td>
                                                @forelse ($category?->taxVats as $key => $item)
                                                    <div class="d-flex gap-1">
                                                        {{ $item?->tax?->name }} :
                                                        <span>({{ $item?->tax?->tax_rate ?? 0 }}%)</span>
                                                    </div>
                                                @empty
                                                    <span>{{ translate('N/A') }}</span>
                                                @endforelse
                                            </td>
                                        @endif
                                        <td class="text-center">
                                            <span class="k-num">{{ $category['priority'] }}</span>
                                        </td>

                                        <td class="text-center">
                                            <form action="{{ route('admin.category.status') }}" method="post"
                                                  id="category-status{{ $category['id'] }}-form" class="no-reload-form">
                                                @csrf
                                                <input type="hidden" name="status_type" value="home_status">
                                                <input type="hidden" name="id" value="{{ $category['id'] }}">
                                                <label class="switcher mx-auto"
                                                       for="category-status{{ $category['id'] }}">
                                                    <input
                                                        class="switcher_input custom-modal-plugin"
                                                        type="checkbox" value="1" name="home_status"
                                                        id="category-status{{ $category['id'] }}"
                                                        {{ $category['home_status'] == 1 ? 'checked' : '' }}
                                                        data-modal-type="input-change-form"
                                                        data-modal-form="#category-status{{ $category['id'] }}-form"
                                                        data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/category-status-on.png') }}"
                                                        data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/category-status-off.png') }}"
                                                        data-on-title="{{ translate('Want_to_Turn_ON').' '.$category['defaultname'].' '. translate('status') }}"
                                                        data-off-title="{{ translate('Want_to_Turn_OFF').' '.$category['defaultname'].' '.translate('status') }}"
                                                        data-on-message="<p>{{ translate('if_enabled_this_category_it_will_be_visible_from_the_category_wise_product_section_in_the_website_and_customer_app_in_the_homepage') }}</p>"
                                                        data-off-message="<p>{{ translate('if_disabled_this_category_it_will_be_hidden_from_the_category_wise_product_section_in_the_website_and_customer_app_in_the_homepage') }}</p>"
                                                        data-on-button-text="{{ translate('turn_on') }}"
                                                        data-off-button-text="{{ translate('turn_off') }}">
                                                    <span class="switcher_control"></span>
                                                </label>
                                            </form>
                                        </td>
                                        @if (getWebConfig(name: 'auction_feature_status'))
                                            <td class="text-center">
                                                <form action="{{ route('admin.category.status') }}" method="post"
                                                      id="category-auction-status{{ $category['id'] }}-form" class="no-reload-form">
                                                    @csrf
                                                    <input type="hidden" name="status_type" value="auction_home_status">
                                                    <input type="hidden" name="id" value="{{ $category['id'] }}">
                                                    <label class="switcher mx-auto"
                                                           for="category-auction-status{{ $category['id'] }}">
                                                        <input
                                                            class="switcher_input custom-modal-plugin"
                                                            type="checkbox" value="1" name="auction_home_status"
                                                            id="category-auction-status{{ $category['id'] }}"
                                                            {{ $category['auction_home_status'] == 1 ? 'checked' : '' }}
                                                            data-modal-type="input-change-form"
                                                            data-modal-form="#category-auction-status{{ $category['id'] }}-form"
                                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/category-status-on.png') }}"
                                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/category-status-off.png') }}"
                                                            data-on-title="{{ translate('Want_to_Turn_ON').' '.$category['defaultname'].' '.translate('Auction').' '. translate('status') }}"
                                                            data-off-title="{{ translate('Want_to_Turn_OFF').' '.$category['defaultname'].' '.translate('Auction').' '.translate('status') }}"
                                                            data-on-message="<p>{{ translate('This category will be visible in the auction category wise section on the website.') }}</p>"
                                                            data-off-message="<p>{{ translate('This category will be hidden from the auction category wise section on the website.') }}</p>"
                                                            data-on-button-text="{{ translate('turn_on') }}"
                                                            data-off-button-text="{{ translate('turn_off') }}">
                                                        <span class="switcher_control"></span>
                                                    </label>
                                                </form>
                                            </td>
                                        @endif

                                        <td>
                                            <div class="k-table__actions">
                                                <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon edit" title="{{ translate('Edit') }}"
                                                   data-bs-toggle="offcanvas" href="#categoryEditOffcanvas-{{ $category['id'] }}">
                                                    <x-k.icon name="edit" :size="15" />
                                                </a>
                                                <span class="k-btn k-btn--ghost k-btn--sm k-btn--icon delete-category" role="button"
                                                      title="{{ translate('delete') }}"
                                                      data-product-count="{{count($category?->product)}}"
                                                      data-text="{{translate('there_were_').count($category?->product).translate('_products_under_this_category').'.'.translate('please_update_their_category_from_the_below_list_before_deleting_this_one').'.'}}"
                                                      id="{{ $category['id'] }}">
                                                    <x-k.icon name="trash" :size="15" />
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if(count($categories) == 0)
                            <x-k.empty icon="catalog" :title="translate('no_category_found')" />
                        @endif
                        <div class="k-pager">
                            <span class="k-pager__info">
                                @if ($categories->total() > 0)
                                    {{ translate('showing') }}
                                    <span class="k-num">{{ $categories->firstItem() }}–{{ $categories->lastItem() }}</span>
                                    {{ translate('of') }} <span class="k-num">{{ $categories->total() }}</span>
                                @endif
                            </span>
                            <div>{!! $categories->appends(request()->except('page'))->links() !!}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include("admin-views.category.offcanvas._category-add", ['categoryType' => 'category'])
    @foreach($categoriesWithTrans as $key => $category)
        @include("admin-views.category.offcanvas._category-edit", ['category' => $category])
    @endforeach

    <span id="route-admin-category-delete" data-url="{{ route('admin.category.delete') }}"></span>
    <span id="get-categories" data-categories="{{ json_encode($categories) }}"></span>

    <div class="modal fade" id="select-category-modal" tabindex="-1" aria-labelledby="toggle-modal" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg">
                <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                    <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                            data-bs-dismiss="modal" aria-label="Close"><i
                            class="tio-clear"></i></button>
                </div>
                <div class="modal-body px-4 px-sm-5 pt-0 pb-sm-5">
                    <div class="d-flex flex-column align-items-center text-center gap-2 mb-2">
                        <div
                            class="toggle-modal-img-box d-flex flex-column justify-content-center align-items-center mb-3 position-relative">
                            <img src="{{dynamicAsset('public/assets/new/back-end/img/icons/info.svg')}}" alt=""
                                 width="90"/>
                        </div>
                        <h5 class="modal-title mb-2 category-title-message category-title-message"></h5>
                    </div>
                    <form action="{{ route('admin.category.delete') }}" method="post"
                          class="product-category-update-form-submit">
                        @csrf
                        <input name="id" hidden="">
                        <div class="d-flex flex-column gap-2 mb-3">
                            <label class="title-color"
                                   for="exampleFormControlSelect1">{{ translate('select_Category') }}
                                <span class="text-danger">*</span>
                            </label>
                            <select name="category_id" class="form-control js-select2-custom category-option" required>
                            </select>
                        </div>
                        <div class="d-flex justify-content-center gap-3">
                            <button type="submit" class="btn btn-primary min-w-120">{{translate('update')}}</button>
                            <button type="button" class="btn btn-danger min-w-120" data-bs-dismiss="modal">
                                {{ translate('cancel') }}
                            </button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
    </div>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/products/products-management.js') }}"></script>
@endpush
