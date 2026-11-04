@extends('layouts.admin.app')

@section('title', translate('deal_Of_The_Day'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex gap-2">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/deal_of_the_day.png') }}" alt="">
                {{ translate('deal_of_the_day') }}
            </h2>
        </div>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <form action="{{route('admin.deal.day') }}"
                              class="text-start form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate"
                              method="post">
                            @csrf
                            @php($language = getWebConfig(name:'pnc_language'))
                            @php($defaultLanguage = 'en')
                            @php($defaultLanguage = $language[0])

                            <div class="position-relative nav--tab-wrapper mb-4">
                                <ul class="nav nav-pills nav--tab lang_tab" id="pills-tab" role="tablist">
                                    @foreach($language as $lang)
                                        <li class="nav-item px-0" role="presentation">
                                            <a class="nav-link px-2  text-capitalize {{ $lang == $defaultLanguage? 'active':'' }}" id="{{ $lang }}-link" data-bs-toggle="pill" href="#{{ $lang }}-form" role="tab" aria-selected="true">
                                                {{ ucfirst(getLanguageName($lang)) . '(' . strtoupper($lang) . ')' }}
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
                            <div class="form-group">
                                <div class="tab-content" id="pills-tabContent">
                                    @foreach($language as $lang)
                                        <div class="tab-pane fade {{ $lang == $defaultLanguage ? 'show active':'' }}" id="{{ $lang }}-form" role="tabpanel" aria-labelledby="{{ $lang }}-form-tab">
                                            <div class="col-md-12">
                                                <label for="name" class="form-label">
                                                    {{ translate('title') }} ({{strtoupper($lang) }})
                                                    @if($lang == $defaultLanguage)<span class="text-danger">*</span>@endif
                                                </label>
                                                <input type="text" name="title[]" class="form-control" id="title"
                                                       data-required-msg="{{ translate('title_is_required') }}"
                                                       placeholder="{{ translate('ex').' '.':'.' '.translate('Daily Spotlight Deal') }}"
                                                    {{ $lang == $defaultLanguage? 'required':'' }}>
                                            </div>
                                        </div>
                                        <input type="hidden" name="lang[]" value="{{ $lang }}" id="lang">
                                    @endforeach
                                </div>
                                <div class="row">
                                    <div class="col-md-12 mt-3">
                                        <label for="name" class="form-label">{{ translate('products') }} <span class="text-danger">*</span></label>
                                        <input type="text" class="product_id" name="product_id" hidden>
                                        <div class="dropdown select-product-search w-100">
                                            <button class="form-select bg-transparent shadow-none text-start dropdown-toggle line-1 word-break select-product-button"
                                                    data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" type="button">
                                                {{ translate('select_product') }}
                                            </button>
                                            <div class="dropdown-menu w-100 px-2">
                                                <div class="search-form mb-3">
                                                    <button type="button" class="btn"><i class="fi fi-rr-search"></i>
                                                    </button>
                                                    <input type="text"
                                                           class="js-form-search form-control search-bar-input search-all-type-product"
                                                           placeholder="{{ translate('search menu').'...' }}">
                                                </div>
                                                <div
                                                    class="d-flex flex-column gap-3 max-h-40vh overflow-y-auto overflow-x-hidden search-result-box">
                                                    @include('admin-views.partials._search-product',['products'=>$products])
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end gap-3 flex-wrap">
                                <button type="reset" id="reset"
                                        class="btn btn-secondary px-5 reset-button">{{ translate('reset') }}</button>
                                <button type="submit" class="btn btn-primary px-5">{{ translate('submit') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <div class="row mt-20">
            <div class="col-md-12">
                <x-k.data-view :title="translate('deal_of_the_day')" :count="$deals->total()"
                               searchName="searchValue" :searchValue="requestString('searchValue')"
                               :searchPlaceholder="translate('search_by_Title')">

                    <table class="k-table">
                        <thead>
                        <tr>
                            <th>{{ translate('title') }}</th>
                            <th>{{ translate('product_info') }}</th>
                            <th>{{ translate('status') }}</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($deals as $deal)
                            <tr>
                                <td>
                                    <span class="k-truncate" style="display:block;max-inline-size:250px" title="{{ $deal['title'] }}">
                                        {{ $deal['title'] }}
                                    </span>
                                </td>
                                <td>
                                    @if (isset($deal->product))
                                        <span class="k-truncate" style="display:block;max-inline-size:280px" title="{{ $deal->product->name }}">
                                            {{ $deal->product->name }}
                                        </span>
                                    @else
                                        <x-k.badge tone="warning">{{ translate('not_selected') }}</x-k.badge>
                                    @endif
                                </td>
                                <td>
                                    <form action="{{route('admin.deal.day-status-update') }}" method="post"
                                          id="deal-of-the-day{{ $deal['id'] }}-form" class="no-reload-form reload-true">
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $deal['id'] }}">

                                        <label class="switcher" for="deal-of-the-day{{ $deal['id'] }}">
                                            <input
                                                class="switcher_input custom-modal-plugin"
                                                type="checkbox" value="1" name="status"
                                                id="deal-of-the-day{{ $deal['id'] }}"
                                                {{ $deal['status'] == 1 ? 'checked':'' }}
                                                data-modal-type="input-change-form"
                                                data-modal-form="#deal-of-the-day{{ $deal['id'] }}-form"
                                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/deal-of-the-day-status-on.png') }}"
                                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/deal-of-the-day-status-off.png') }}"
                                                data-on-title = "{{ translate('want_to_Turn_ON_Deal_of_the_Day_Status').'?' }}"
                                                data-off-title = "{{ translate('want_to_Turn_OFF_Deal_of_the_Day_Status').'?' }}"
                                                data-on-message = "<p>{{ translate('if_enabled_this_deal_of_the_day_will_be_available_on_the_website_and_customer_app') }}</p>"
                                                data-off-message = "<p>{{ translate('if_disabled_this_deal_of_the_day_will_be_hidden_from_the_website_and_customer_app') }}</p>"
                                                data-on-button-text="{{ translate('turn_on') }}"
                                                data-off-button-text="{{ translate('turn_off') }}">
                                            <span class="switcher_control"></span>
                                        </label>
                                    </form>
                                </td>
                                <td>
                                    <div class="k-table__actions">
                                        <a title="{{ translate('edit') }}"
                                           href="{{route('admin.deal.day-update',[$deal['id']]) }}"
                                           class="k-btn k-btn--ghost k-btn--sm k-btn--icon">
                                            <x-k.icon name="edit" :size="15" />
                                        </a>
                                        <a title="{{ translate('delete') }}"
                                           class="k-btn k-btn--ghost k-btn--sm k-btn--icon delete-data-without-form"
                                           data-action="{{route('admin.deal.day-delete') }}"
                                           data-id="{{ $deal['id'] }}">
                                            <x-k.icon name="trash" :size="15" />
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>

                    @if(count($deals)==0)
                        <x-k.empty icon="marketing" :title="translate('no_data_found')"
                                   :text="request('searchValue') ? translate('no_deal_matches_your_search') : null" />
                    @endif

                    @if ($deals->total() > 0)
                        <x-slot:pager>
                            <span class="k-pager__info">
                                {{ translate('showing') }}
                                <span class="k-num">{{ $deals->firstItem() }}–{{ $deals->lastItem() }}</span>
                                {{ translate('of') }} <span class="k-num">{{ $deals->total() }}</span>
                            </span>
                            <div>{!! $deals->appends(request()->except('page'))->links() !!}</div>
                        </x-slot:pager>
                    @endif
                </x-k.data-view>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/search-product.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin/deal.js') }}"></script>
    <script>
        $('.search-all-type-product').off('focus click');
    </script>
@endpush
