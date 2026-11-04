@extends('layouts.vendor.app')

@section('title', translate('coupon_Add'))


@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{dynamicAsset(path: 'public/assets/back-end/img/coupon_setup.png') }}" alt="">
                {{ translate('coupon_setup') }}
            </h2>
        </div>
        <div class="row">
            <div class="col-sm-12 col-lg-12 mb-3 mb-lg-2">
                <div class="card">
                    <div class="card-body">
                        <form action="{{ route('vendor.coupon.add') }}" method="post">
                            @csrf
                            <div class="row">
                                <div class="col-md-6 col-lg-4 form-group">
                                    <label for="name"
                                           class="title-color font-weight-medium d-flex">{{ translate('coupon_type') }}</label>
                                    <select class="js-example-basic-multiple js-states js-example-responsive form-control" id="coupon_type" name="coupon_type" required>
                                        <option disabled selected>{{ translate('select_coupon_type') }}</option>
                                        <option
                                            value="discount_on_purchase">{{ translate('discount_on_Purchase') }}</option>
                                        <option value="free_delivery">{{ translate('free_Delivery') }}</option>
                                    </select>
                                </div>
                                <div class="col-md-6 col-lg-4 form-group">
                                    <label for="name"
                                           class="title-color font-weight-medium d-flex">{{ translate('coupon_title') }}</label>
                                    <input type="text" name="title" class="form-control" value="{{ old('title') }}"
                                           id="title"
                                           placeholder="{{ translate('title') }}" required>
                                </div>
                                <div class="col-md-6 col-lg-4 form-group">
                                    <div class="d-flex justify-content-between">
                                        <label for="name"
                                               class="title-color font-weight-medium text-capitalize">{{ translate('coupon_code') }}</label>
                                        <a href="javascript:" class="float-end c1 fs-12"
                                           id="generateCode">{{ translate('generate_code') }}</a>
                                    </div>
                                    <input type="text" name="code" value=""
                                           class="form-control" id="code"
                                           placeholder="{{ translate('ex') }}: EID100" required>
                                </div>
                                <input type="hidden" value="seller" name="coupon_bearer">
                                <div class="col-md-6 col-lg-4 form-group coupon_type">
                                    <label for="name"
                                           class="title-color font-weight-medium d-flex">{{ translate('customer') }}</label>
                                    <select
                                        class="js-example-basic-multiple js-states js-example-responsive form-control"
                                        name="customer_id">
                                        <option disabled selected>{{ translate('Select_Customer') }}</option>
                                        <option value="0">{{ translate('All_Customer') }}</option>
                                        @foreach($customers as $customer)
                                            <option
                                                value="{{ $customer->id }}">{{ $customer->f_name. ' '. $customer->l_name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6 col-lg-4 form-group">
                                    <label
                                        for="exampleFormControlInput1"
                                        class="title-color font-weight-medium d-flex">{{ translate('limit_for_same_user') }}</label>
                                    <input type="number" name="limit" value="{{ old('limit') }}" min="0"
                                           id="coupon_limit" class="form-control"
                                           placeholder="{{ translate('ex') }}: {{ translate('10') }}">
                                </div>
                                <div class="col-md-6 col-lg-4 form-group free_delivery">
                                    <label for="name"
                                           class="title-color font-weight-medium d-flex">{{ translate('discount_type') }}</label>
                                    <select id="discount_type" class="js-example-basic-multiple js-states js-example-responsive form-control" name="discount_type">
                                        <option value="amount">{{ translate('amount') }}</option>
                                        <option value="percentage">{{ translate('percentage') }} (%)</option>
                                    </select>
                                </div>
                                <div class="col-md-6 col-lg-4 form-group free_delivery">
                                    <label for="name"
                                           class="title-color font-weight-medium d-flex">{{ translate('discount_Amount') }}
                                        <span id="discount_percent"> (%)</span></label>
                                    <input type="number" min="1" max="1000000" name="discount"
                                           value="{{ old('discount') }}" class="form-control"
                                           id="discount"
                                           placeholder="{{ translate('ex').': 5000'}}">
                                </div>
                                <div class="col-md-6 col-lg-4 form-group">
                                    <label for="name"
                                           class="title-color font-weight-medium d-flex">{{ translate('minimum_purchase') }}
                                        ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})
                                    </label>
                                    <input type="number" min="1" max="1000000" name="min_purchase"
                                           value="{{ old('min_purchase') }}" class="form-control"
                                           id="minimum purchase"
                                           placeholder="{{ translate('ex') }}: 100">
                                </div>
                                <div class="col-md-6 col-lg-4 form-group free_delivery" id="max-discount">
                                    <label for="name"
                                           class="title-color font-weight-medium d-flex">{{ translate('maximum_discount') }}
                                        ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})
                                    </label>
                                    <input type="number" min="1" max="1000000" name="max_discount"
                                           value="{{ old('max_discount') }}"
                                           class="form-control" id="maximum discount"
                                           placeholder="{{ translate('ex').': 5000'}}">
                                </div>
                                <div class="col-md-6 col-lg-4 form-group">
                                    <label for="name"
                                           class="title-color font-weight-medium d-flex">{{ translate('Start_Date') }}</label>
                                    <input id="start_date" type="date" name="start_date" value="{{ old('start_date') }}"
                                           class="form-control"
                                           placeholder="{{ translate('Start_Date') }}" required>
                                </div>
                                <div class="col-md-6 col-lg-4 form-group">
                                    <label for="name"
                                           class="title-color font-weight-medium d-flex">{{ translate('expire_date') }}</label>
                                    <input id="expire_date" type="date" name="expire_date"
                                           value="{{ old('expire_date') }}" class="form-control"
                                           placeholder="{{ translate('expire_date') }}" required>
                                </div>
                            </div>

                            <div class="d-flex align-items-center justify-content-end flex-wrap gap-10">
                                <button class="btn btn-secondary px-4" type="reset">
                                    {{ translate('reset') }}
                                </button>
                                <button type="submit" class="btn btn--primary px-4">
                                    {{ translate('submit') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-20">
            <div class="col-md-12">
                <x-k.data-view :title="translate('coupon_list')" :count="$coupons->total()"
                               searchName="searchValue" :searchValue="requestString('searchValue')"
                               :searchPlaceholder="translate('search_by_Title_or_Code_or_Discount_Type')">

                    <x-slot:actions>
                        <a class="k-btn k-btn--secondary"
                           href="{{ route('vendor.coupon.export',['searchValue'=>request('searchValue')]) }}">
                            <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                        </a>
                    </x-slot:actions>

                    <table class="k-table">
                        <thead>
                        <tr>
                            <th>{{ translate('SL') }}</th>
                            <th>{{ translate('coupon') }}</th>
                            <th>{{ translate('coupon_type') }}</th>
                            <th>{{ translate('duration') }}</th>
                            <th>{{ translate('Limit') }}</th>
                            <th>{{ translate('discount_bearer') }}</th>
                            <th>
                                {{ translate('status') }}
                                <span data-toggle="tooltip"
                                      title="{{ translate('some_status_buttons_are_disabled_because_the_admin_added_coupons') }}, {{ translate('the_coupon_discount_bearer_is_admin') }}, {{ translate('or_some_coupons_are_for_all_vendors') }}">
                                    <x-k.icon name="info" :size="13" />
                                </span>
                            </th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($coupons as $k=>$coupon)
                            @php($vendorOwnsCoupon = $coupon->added_by=='seller' || ($coupon->added_by=='admin' && $coupon->coupon_bearer=='seller' && $coupon->seller_id==auth('seller')->id()))
                            <tr>
                                <td><span class="k-num">{{$coupons->firstItem() + $k}}</span></td>
                                <td>
                                    <div class="k-truncate" style="max-inline-size:200px" title="{{ $coupon['title'] }}">{{substr($coupon['title'],0,20) }}</div>
                                    <strong class="k-num">{{ translate('code') }}: {{$coupon['code']}}</strong>
                                </td>
                                <td class="text-capitalize">{{ translate(str_replace('_',' ',$coupon['coupon_type'])) }}</td>
                                <td>
                                    <span class="k-num">{{date('d M, y',strtotime($coupon['start_date'])) }}</span>
                                    <span class="k-text-subtle">–</span>
                                    <span class="k-num">{{date('d M, y',strtotime($coupon['expire_date'])) }}</span>
                                </td>
                                <td>
                                    <div class="k-text-subtle">{{translate('Same_User_Limit')}}: <strong class="k-num">{{ $coupon['limit'] }}</strong></div>
                                    <div class="k-text-subtle">{{translate('Total_Used')}}: <strong class="k-num">{{ $coupon['order_count'] }}</strong></div>
                                </td>
                                <td>{{ translate($coupon['coupon_bearer'] == 'inhouse' ? 'admin':$coupon['coupon_bearer']) }}</td>
                                <td>
                                    @if($vendorOwnsCoupon)
                                        <form
                                            action="{{ route('vendor.coupon.update-status',[$coupon['id'],$coupon->status?0:1]) }}"
                                            method="get" id="coupon_status{{$coupon['id']}}-form"
                                            class="coupon_status_form">
                                            <label class="switcher">
                                                <input type="checkbox" class="switcher_input toggle-switch-message"
                                                       id="coupon_status{{$coupon['id']}}" name="status" value="1"
                                                       {{ $coupon->status == 1 ? 'checked':'' }}
                                                       data-modal-id="toggle-status-modal"
                                                       data-toggle-id="coupon_status{{$coupon['id']}}"
                                                       data-on-image="coupon-status-on.png"
                                                       data-off-image="coupon-status-off.png"
                                                       data-on-title="{{ translate('want_to_Turn_ON_Coupon_Status').'?'}}"
                                                       data-off-title="{{ translate('want_to_Turn_OFF_Coupon_Status').'?'}}"
                                                       data-on-message="<p>{{ translate('if_enabled_this_coupon_will_be_available_on_the_website_and_customer_app') }}</p>"
                                                       data-off-message="<p>{{ translate('if_disabled_this_coupon_will_be_hidden_from_the_website_and_customer_app') }}</p>"
                                                >
                                                <span class="switcher_control"></span>
                                            </label>
                                        </form>
                                    @else
                                        <label class="switcher">
                                            <input type="checkbox"
                                                   class="switcher_input toggle-switch-input"
                                                   {{$coupon->status?'checked':''}} disabled>
                                            <span class="switcher_control opacity--40"></span>
                                        </label>
                                    @endif
                                </td>
                                <td>
                                    <div class="k-table__actions">
                                        <button type="button" class="k-btn k-btn--ghost k-btn--sm k-btn--icon get-quick-view"
                                                title="{{ translate('view') }}"
                                                data-id="{{ $coupon['id'] }}">
                                            <x-k.icon name="eye" :size="15" />
                                        </button>
                                        @if($vendorOwnsCoupon)
                                            <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon edit"
                                               href="{{ route('vendor.coupon.update',[$coupon['id']]) }}"
                                               title="{{ translate('edit') }}">
                                                <x-k.icon name="edit" :size="15" />
                                            </a>
                                            <span class="k-btn k-btn--ghost k-btn--sm k-btn--icon delete delete-data" role="button"
                                                  title="{{ translate('delete') }}"
                                                  data-id="coupon-{{$coupon['id']}}">
                                                <x-k.icon name="trash" :size="15" />
                                            </span>
                                            <form action="{{ route('vendor.coupon.delete',[$coupon['id']]) }}"
                                                  method="post" id="coupon-{{$coupon['id']}}">
                                                @csrf @method('delete')
                                            </form>
                                        @else
                                            <span class="k-btn k-btn--ghost k-btn--sm k-btn--icon" style="opacity:.4;pointer-events:none"
                                                  title="{{ translate('some_actions_are_disabled_because_the_admin_added_coupons') }}">
                                                <x-k.icon name="edit" :size="15" />
                                            </span>
                                            <span class="k-btn k-btn--ghost k-btn--sm k-btn--icon" style="opacity:.4;pointer-events:none"
                                                  title="{{ translate('some_actions_are_disabled_because_the_admin_added_coupons') }}">
                                                <x-k.icon name="trash" :size="15" />
                                            </span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>

                    @if(count($coupons)==0)
                        <x-k.empty icon="marketing" :title="translate('no_coupon_found')"
                                   :text="request('searchValue') ? translate('no_coupon_matches_your_search') : null" />
                    @endif

                    @if ($coupons->total() > 0)
                        <x-slot:pager>
                            <span class="k-pager__info">
                                {{ translate('showing') }}
                                <span class="k-num">{{ $coupons->firstItem() }}–{{ $coupons->lastItem() }}</span>
                                {{ translate('of') }} <span class="k-num">{{ $coupons->total() }}</span>
                            </span>
                            <div>{!! $coupons->appends(request()->except('page'))->links() !!}</div>
                        </x-slot:pager>
                    @endif
                </x-k.data-view>

                <div class="modal fade" id="quick-view" tabindex="-1" role="dialog"
                     aria-labelledby="exampleModalCenterTitle" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered coupon-details" role="document">
                        <div class="modal-content" id="quick-view-modal">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <span id="get-detail-url" data-url="{{ route('vendor.coupon.quick-view') }}"></span>
@endsection
@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/back-end/js/vendor/coupon.js') }}"></script>
    <script>
        $(document).ready(function () {

            function toggleCustomDate(wrapper) {
                let selectValue = wrapper.find('.date-type-select').val();

                if (selectValue === 'custom') {
                    wrapper.find('.custom-date-div').slideDown(200);
                } else {
                    wrapper.find('.custom-date-div').slideUp(200);
                }
            }

            $(document).on('change', '.date-type-select', function () {
                let wrapper = $(this).closest('.date-filter-wrapper');
                toggleCustomDate(wrapper);
            });

            $('.date-filter-wrapper').each(function () {
                toggleCustomDate($(this));
            });

            // --- free delivery collapse
            function toggleCollapse(wrapper) {
                let isChecked = wrapper.find('.free-del-input').is(':checked');

                if (isChecked) {
                    wrapper.find('.discount-type-div').slideUp(200);
                } else {
                    wrapper.find('.discount-type-div').slideDown(200);
                }
            }

            $(document).on('change', '.free-del-input', function () {
                let wrapper = $(this).closest('.free-delivery-collapse');
                toggleCollapse(wrapper);
            });

            $('.free-delivery-collapse').each(function () {
                toggleCollapse($(this));
            });

        });
    </script>
@endpush

