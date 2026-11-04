@extends('layouts.admin.app')

@section('title', translate('coupon_Add'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/coupon_setup.png') }}" alt="">
                {{translate('coupon_setup')}}
            </h2>
        </div>

        <div class="row">
            <div class="col-sm-12 col-lg-12 mb-3 mb-lg-2">
                <div class="card">
                    <div class="card-body">
                        <form action="{{route('admin.coupon.add')}}" method="POST" class="" novalidate="novalidate" id="coupon-add-ajax-submit">
                            @csrf
                            <div class="row g-4 mb-4">
                                <div class="col-md-6 col-lg-4">
                                    <label for="name"
                                           class="form-label d-flex">{{translate('coupon_type')}} <span class="text-danger">*</span></label>
                                    <div class="select-wrapper">
                                        <select class="form-select" id="coupon_type" name="coupon_type" data-required-msg="{{ translate('coupon_type_is_required') }}" required>
                                            <option disabled selected>{{translate('select_coupon_type')}}</option>
                                            <option
                                                value="discount_on_purchase">{{translate('discount_on_Purchase')}}</option>
                                            <option value="free_delivery">{{translate('free_Delivery')}}</option>
                                            <option value="first_order">{{translate('first_Order')}}</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6 col-lg-4">
                                    <div class="form-group">
                                        <label for="name"
                                               class="form-label d-flex">{{translate('coupon_title')}} <span class="text-danger">*</span></label>
                                        <input type="text" name="title" class="form-control" value="{{ old('title') }}"
                                               id="title"  data-maxlength="80"
                                               placeholder="{{translate('title')}}" required  data-required-msg="{{ translate('coupon_title_is_required') }}">
                                    </div>
                                </div>
                                <div class="col-md-6 col-lg-4">
                                    <div class="d-flex justify-content-between">
                                        <label for="name"
                                               class="form-label text-capitalize">{{translate('coupon_code')}} <span class="text-danger">*</span></label>
                                        <a href="javascript:" class="text-primary fs-12" id="generateCode">{{translate('generate_code')}}</a>
                                    </div>
                                    <input type="text" name="code" value=""
                                           class="form-control" id="code"
                                           placeholder="{{translate('ex')}}: EID100" required  data-required-msg="{{ translate('coupon_code_is_required') }}">
                                </div>
                                <div class="col-md-6 col-lg-4 first_order">
                                    <label for="name"
                                           class="form-label d-flex">{{translate('coupon_bearer')}} <span class="text-danger">*</span></label>
                                    <div class="select-wrapper">
                                        <select class="form-select" name="coupon_bearer" id="coupon_bearer" required  data-required-msg="{{ translate('coupon_bearer_is_required') }}">
                                            <option disabled selected>{{translate('select_coupon_bearer')}}</option>
                                            <option value="seller">{{translate('vendor')}}</option>
                                            <option value="inhouse">{{translate('admin')}}</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6 col-lg-4 coupon_by first_order">
                                    <label for="name"
                                           class="form-label d-flex">{{translate('vendor')}} <span class="text-danger">*</span></label>
                                    <select
                                        class="custom-select"
                                        name="seller_id" id="vendor_wise_coupon"  data-required-msg="{{ translate('vendor_field_is_required') }}">
                                        <option disabled selected>{{translate('select_vendor')}}</option>
                                    </select>
                                </div>

                                <div class="col-md-6 col-lg-4 coupon_type first_order">
                                    <label for="name"
                                           class="form-label d-flex">{{translate('customer')}} <span class="text-danger">*</span></label>
                                    <select
                                        class="custom-select"
                                        name="customer_id">
                                        <option disabled selected>{{translate('select_customer')}}</option>
                                        <option value="0">{{translate('All_Customer')}}</option>
                                        @foreach($customers as $customer)
                                            <option
                                                value="{{ $customer->id }}">{{ $customer->f_name. ' '. $customer->l_name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6 col-lg-4 first_order">
                                    <label
                                        for="exampleFormControlInput1"
                                        class="form-label d-flex">{{translate('limit_for_same_user')}} <span class="text-danger">*</span></label>
                                    <input type="number" name="limit" value="{{ old('limit') }}" min="0"
                                           id="coupon_limit" class="form-control"
                                           placeholder="{{translate('ex')}}: 10">
                                </div>
                                <div class="col-md-6 col-lg-4 free_delivery">
                                    <label for="name"
                                           class="form-label d-flex">{{translate('discount_type')}}<span class="text-danger">*</span></label>
                                    <div class="select-wrapper">
                                        <select id="discount_type" class="form-select" name="discount_type">
                                            <option value="amount">{{translate('amount')}}</option>
                                            <option value="percentage">{{translate('percentage')}} (%)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6 col-lg-4 free_delivery">
                                    <label for="name"
                                           class="form-label d-flex">{{translate('discount_Amount')}}<span class="text-danger">*</span>
                                        <span id="discount_currency">({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})</span>
                                        <span id="discount_percent"> (%)</span></label>
                                    <input type="number" min="1" max="1000000" name="discount"
                                           value="{{ old('discount') }}" class="form-control"
                                           id="discount"
                                           placeholder="{{translate('ex')}} : 500">
                                </div>
                                <div class="col-md-6 col-lg-4">
                                    <label for="name"
                                           class="form-label d-flex">
                                        {{ translate('minimum_purchase') }}
                                        <span class="text-danger">*</span>
                                        ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})
                                    </label>
                                    <input type="number" min="1" max="1000000" name="min_purchase"  data-required-msg="{{ translate('Minimum_purchase_field_is_required') }}"
                                           value="{{ old('min_purchase') }}" class="form-control"
                                           id="minimum purchase"
                                           placeholder="{{translate('ex')}} : 100" required>
                                </div>
                                <div class="col-md-6 col-lg-4 free_delivery" id="max-discount">
                                    <label for="name"
                                           class="form-label d-flex">
                                        {{translate('maximum_discount')}}
                                        ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})
                                    </label>
                                    <input type="number" min="1" max="1000000" name="max_discount"
                                           value="{{ old('max_discount') }}"
                                           class="form-control" id="maximum discount"
                                           placeholder="{{translate('ex')}} : 5000">
                                </div>
                                <div class="col-md-6 col-lg-4">
                                    <label for="name"
                                           class="form-label d-flex">{{translate('Start_Date')}} <span class="text-danger">*</span></label>
                                    <input id="start_date" type="date" name="start_date" value="{{ old('start_date') }}"
                                           class="form-control"
                                           placeholder="{{translate('Start_Date')}}" required>
                                </div>
                                <div class="col-md-6 col-lg-4">
                                    <label for="name"
                                           class="form-label d-flex">{{translate('expire_date')}} <span class="text-danger">*</span></label>
                                    <input id="expire_date" type="date" name="expire_date"
                                           value="{{ old('expire_date') }}" class="form-control"
                                           placeholder="{{translate('expire_date')}}" required>
                                </div>
                            </div>

                            <div class="d-flex align-items-center justify-content-end flex-wrap gap-3">
                                <button type="reset" class="btn btn-secondary px-4">{{translate('reset')}}</button>
                                <button type="submit" class="btn btn-primary px-4">{{translate('submit')}}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-20">
            <div class="col-md-12">
                <x-k.data-view :title="translate('coupon_list')" :count="$coupons->total()" :selectable="true"
                               searchName="searchValue" :searchValue="requestString('searchValue')"
                               :searchPlaceholder="translate('search_by_Title_or_Code_or_Discount_Type')">

                    <x-slot:tabs>
                        <a class="k-tab" href="{{ route('admin.coupon.add', ['searchValue' => request('searchValue')]) }}"
                           aria-selected="{{ request()->filled('status') ? 'false' : 'true' }}">{{ translate('all') }}</a>
                        <a class="k-tab" href="{{ route('admin.coupon.add', ['status' => 1, 'searchValue' => request('searchValue')]) }}"
                           aria-selected="{{ request('status') === '1' ? 'true' : 'false' }}">{{ translate('active') }}</a>
                        <a class="k-tab" href="{{ route('admin.coupon.add', ['status' => 0, 'searchValue' => request('searchValue')]) }}"
                           aria-selected="{{ request('status') === '0' ? 'true' : 'false' }}">{{ translate('disabled') }}</a>
                    </x-slot:tabs>

                    <x-slot:bulk>
                        <x-k.button variant="secondary" size="sm" icon="check" data-k-bulk-status="1">
                            {{ translate('turn_on') }}
                        </x-k.button>
                        <x-k.button variant="secondary" size="sm" icon="eye-off" data-k-bulk-status="0">
                            {{ translate('turn_off') }}
                        </x-k.button>
                        <a class="k-btn k-btn--secondary k-btn--sm"
                           href="{{ route('admin.coupon.export', ['searchValue' => request('searchValue')]) }}">
                            <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                        </a>
                    </x-slot:bulk>

                    <table class="k-table">
                        <thead>
                        <tr>
                            <th style="inline-size:44px">
                                <input type="checkbox" data-k-select-all aria-label="{{ translate('select_all') }}">
                            </th>
                            <th>{{ translate('coupon') }}</th>
                            <th>{{ translate('coupon_type') }}</th>
                            <th>{{ translate('duration') }}</th>
                            <th class="k-table__num">{{ translate('Limit') }}</th>
                            <th class="k-table__num">{{ translate('Total_Used') }}</th>
                            <th>{{ translate('discount_bearer') }}</th>
                            <th>{{ translate('status') }}</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($coupons as $coupon)
                            @php
                                // The switch answers "is it enabled"; the badge answers "is it
                                // doing anything right now" — a coupon can be on yet expired.
                                $couponStartsAt = strtotime($coupon['start_date']);
                                $couponExpiresAt = strtotime($coupon['expire_date']);
                                $today = strtotime('today');
                            @endphp
                            <tr>
                                <td>
                                    <input type="checkbox" data-k-row-select value="{{ $coupon['id'] }}"
                                           aria-label="{{ translate('select_coupon') }} {{ $coupon['id'] }}">
                                </td>
                                <td>
                                    <span class="k-truncate" style="display:block;max-inline-size:230px" title="{{ $coupon['title'] }}">
                                        {{ $coupon['title'] }}
                                    </span>
                                    <code class="k-text-subtle">{{ $coupon['code'] }}</code>
                                </td>
                                <td class="text-capitalize">{{ translate(str_replace('_', ' ', $coupon['coupon_type'])) }}</td>
                                <td>
                                    <span class="k-num">{{ date('d M, y', $couponStartsAt) }} – {{ date('d M, y', $couponExpiresAt) }}</span>
                                    @if ($couponExpiresAt < $today)
                                        <x-k.badge tone="danger">{{ translate('expired') }}</x-k.badge>
                                    @elseif ($couponStartsAt > $today)
                                        <x-k.badge tone="info">{{ translate('upcoming') }}</x-k.badge>
                                    @else
                                        <x-k.badge tone="warning">{{ translate('live') }}</x-k.badge>
                                    @endif
                                </td>
                                <td class="k-table__num"><span class="k-num">{{ $coupon['limit'] ?? '—' }}</span></td>
                                <td class="k-table__num"><span class="k-num">{{ $coupon['order_count'] }}</span></td>
                                <td>{{ translate($coupon['coupon_bearer'] == 'inhouse' ? 'admin' : $coupon['coupon_bearer']) }}</td>
                                <td>
                                    <form
                                        action="{{route('admin.coupon.status',[$coupon['id'],$coupon['status']?0:1])}}"
                                        method="GET" id="coupon_status{{$coupon['id'] }}-form"
                                        class="coupon_status_form">
                                        <label class="switcher mx-auto" for="coupon_status{{$coupon['id'] }}">
                                            <input
                                                class="switcher_input custom-modal-plugin"
                                                type="checkbox" value="1" name="status"
                                                id="coupon_status{{$coupon['id'] }}"
                                                {{ $coupon['status'] == 1 ? 'checked':'' }}
                                                data-modal-type="input-change-form"
                                                data-modal-form="#coupon_status{{$coupon['id'] }}-form"
                                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/coupon-status-on.png') }}"
                                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/coupon-status-off.png') }}"
                                                data-on-title="{{translate('Want_to_Turn_ON_Coupon_Status').'?' }}"
                                                data-off-title="{{translate('Want_to_Turn_OFF_Coupon_Status').'?' }}"
                                                data-on-message="<p>{{translate('if_enabled_this_coupon_will_be_available_on_the_website_and_customer_app')}}</p>"
                                                data-off-message="<p>{{translate('if_disabled_this_coupon_will_be_hidden_from_the_website_and_customer_app')}}</p>"
                                                data-on-button-text="{{ translate('turn_on') }}"
                                                data-off-button-text="{{ translate('turn_off') }}">
                                            <span class="switcher_control"></span>
                                        </label>
                                    </form>
                                </td>
                                <td>
                                    <div class="k-table__actions">
                                        <button class="k-btn k-btn--ghost k-btn--sm k-btn--icon get-quick-view"
                                                data-id="{{ $coupon['id'] }}" title="{{ translate('view') }}">
                                            <x-k.icon name="eye" :size="15" />
                                        </button>
                                        <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                           href="{{ route('admin.coupon.update', [$coupon['id']]) }}" title="{{ translate('edit') }}">
                                            <x-k.icon name="edit" :size="15" />
                                        </a>
                                        <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon delete-data" href="javascript:"
                                           data-id="coupon-{{ $coupon['id'] }}" title="{{ translate('delete') }}">
                                            <x-k.icon name="trash" :size="15" />
                                        </a>
                                        <form action="{{ route('admin.coupon.delete', [$coupon['id']]) }}"
                                              method="post" id="coupon-{{ $coupon['id'] }}">
                                            @csrf @method('delete')
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>

                    @if (count($coupons) == 0)
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
                        <div class="modal-content border-0" id="quick-view-modal">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <span id="coupon-bearer-url" data-url="{{route('admin.coupon.ajax-get-vendor')}}"></span>
    <span id="get-detail-url" data-url="{{ route('admin.coupon.quick-view-details') }}"></span>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin/coupon.js')}}"></script>
    <script>
        "use strict";
        (function () {
            var view = document.querySelector('[data-k-selectable]');
            if (!view) return;

            var endpoint = @json(route('admin.coupon.bulk-status'));
            var csrf = document.querySelector('meta[name="csrf-token"]');
            var labels = {
                confirm: @json(translate('apply_this_to')),
                coupons: @json(translate('coupons')),
                working: @json(translate('updating_coupons')),
                failed: @json(translate('could_not_update_the_coupons')),
            };

            view.addEventListener('click', function (event) {
                var trigger = event.target.closest('[data-k-bulk-status]');
                if (!trigger) return;

                var ids = Kohl.selectedIds(view);
                if (!ids.length) return;
                if (!confirm(labels.confirm + ' ' + ids.length + ' ' + labels.coupons + '?')) return;

                trigger.disabled = true;
                Kohl.toast({title: labels.working, duration: 2000});

                fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : ''
                    },
                    body: JSON.stringify({ids: ids, status: Number(trigger.getAttribute('data-k-bulk-status'))})
                })
                    .then(function (response) { return response.json(); })
                    .then(function (body) {
                        Kohl.toast({
                            title: body.message || labels.failed,
                            tone: body.status === 1 ? 'success' : 'danger',
                        });
                        if (body.updated) setTimeout(function () { location.reload(); }, 1000);
                        else trigger.disabled = false;
                    })
                    .catch(function () {
                        Kohl.toast({title: labels.failed, tone: 'danger'});
                        trigger.disabled = false;
                    });
            });
        })();

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
