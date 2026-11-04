@php use Illuminate\Support\Str; @endphp

@extends('layouts.admin.app')

@section('title', translate('Refund_Requests'))

@section('content')
    <div class="content container-fluid">

        <h2 class="h1 text-capitalize d-flex align-items-center gap-2 mb-3">
            <img width="20" src="{{dynamicAsset(path: 'public/assets/back-end/img/refund-request-list.png')}}" alt="">
            {{translate('refund_request_list')}}
        </h2>

        @php($refundStatus = request('status'))
        <x-k.data-view :title="translate('refund_requests')" :count="$refundList->total()"
                       searchName="searchValue" :searchValue="requestString('searchValue')"
                       :searchPlaceholder="translate('search_by_order_id_or_refund_id')">

            <x-slot:tabs>
                @foreach(['pending', 'approved', 'refunded', 'rejected'] as $statusTab)
                    <a class="k-tab" href="{{ route('admin.refund-section.refund.list', [$statusTab, 'searchValue' => request('searchValue')]) }}"
                       aria-selected="{{ $refundStatus === $statusTab ? 'true' : 'false' }}">{{ translate($statusTab) }}</a>
                @endforeach
            </x-slot:tabs>

            <x-slot:actions>
                <a class="k-btn k-btn--secondary"
                   href="{{ route('admin.refund-section.refund.export', [
                        'status' => request('status'),
                        'searchValue' => request('searchValue'),
                        'from_date' => request('from_date'),
                        'to_date' => request('to_date'),
                   ]) }}">
                    <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                </a>
                @php($refundFilterActive = !empty(request('sort_by')) || !empty(request('from_date')) || !empty(request('to_date')) || !empty(request('type')))
                <button type="button" class="k-btn {{ $refundFilterActive ? 'k-btn--primary' : 'k-btn--secondary' }}"
                        data-bs-toggle="offcanvas" data-bs-target="#PendingRefundRequestFilter">
                    <x-k.icon name="filter" :size="15" /> {{ translate('Filter') }}
                </button>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{ translate('SL') }}</th>
                    <th>{{ translate('refund_ID') }}</th>
                    <th>{{ translate('order_id') }}</th>
                    <th>{{ translate('product_info') }}</th>
                    <th>{{ translate('customer_info') }}</th>
                    <th class="k-table__num">{{ translate('total_amount') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach($refundList as $key => $refund)
                    @php($isProductUnavailable = $refund->product === null)
                    @php($productDetails = $refund?->orderDetails?->product_details ? json_decode($refund->orderDetails->product_details, true) : null)
                    <tr>
                        <td><span class="k-num">{{ $refundList->firstItem()+$key}}</span></td>
                        <td>
                            <a href="{{route('admin.refund-section.refund.details', ['id' => $refund['id']]) }}"
                               class="text-dark hover-primary">
                                {{ $refund->id}}
                            </a>
                        </td>
                        <td>
                            <a href="{{route('admin.orders.details',['id'=>$refund->order_id]) }}"
                               class="text-dark hover-primary">
                                {{ $refund->order_id }}
                            </a>
                        </td>
                        <td>
                            @if(!$isProductUnavailable)
                                <a href="{{ route('admin.products.view',['addedBy'=>($refund->product->added_by =='seller'?'vendor' : 'in-house'), 'id'=>$refund->product->id]) }}" class="k-row">
                                    <img src="{{ getStorageImages(path: $refund?->product?->thumbnail_full_url, type: 'backend-product') }}"
                                         alt="" width="40" height="40"
                                         style="border-radius:8px;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)">
                                    <span style="min-inline-size:0">
                                        <span class="k-truncate text-dark" style="display:block;max-inline-size:220px" title="{{ $refund->product->name }}">
                                            {{ Str::limit($refund->product->name, 35) }}
                                        </span>
                                        <span class="k-text-subtle">{{ translate('QTY') }} : <span class="k-num">{{ $refund?->orderDetails?->qty ?? 1 }}</span></span>
                                    </span>
                                </a>
                            @else
                                <div class="k-row" style="opacity:.5" data-bs-toggle="tooltip"
                                     title="{{ translate('Product_has_been_deleted') }}">
                                    <img src="{{ getStorageImages(path: '', type: 'backend-product') }}"
                                         alt="" width="40" height="40"
                                         style="border-radius:8px;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)">
                                    <span style="min-inline-size:0">
                                        <span class="k-truncate" style="display:block;max-inline-size:220px">
                                            {{ Str::limit($productDetails['name'] ?? translate('product_name_not_found'), 35) }}
                                        </span>
                                        <span class="k-text-subtle">{{ translate('QTY') }} : <span class="k-num">{{ $refund?->orderDetails?->qty ?? 1 }}</span></span>
                                    </span>
                                </div>
                            @endif
                        </td>
                        <td>
                            @if ($refund->customer !=null)
                                <a href="{{route('admin.customer.view', [$refund->customer->id]) }}"
                                   class="text-dark fw-bold hover-primary">
                                    {{ $refund->customer->f_name. ' '. $refund->customer->l_name}}
                                </a>
                                <div class="k-text-subtle">
                                    {{ $refund?->customer?->phone ?: $refund->customer['email'] }}
                                </div>
                            @else
                                <span class="k-text-subtle">{{ translate('customer_not_found') }}</span>
                            @endif
                        </td>
                        <td class="k-table__num">
                            <span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $refund->amount), currencyCode: getCurrencyCode()) }}</span>
                        </td>
                        <td>
                            <div class="k-table__actions">
                                <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{ translate('view') }}"
                                   href="{{route('admin.refund-section.refund.details',['id'=>$refund['id']]) }}">
                                    <x-k.icon name="eye" :size="15" />
                                </a>
                                @if($refund['status'] != 'refunded')
                                    <button type="button" class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                            title="{{ translate('refund') }}"
                                            data-bs-toggle="modal"
                                            data-bs-target="#refundModal-{{$refund['id']}}">
                                        <x-k.icon name="refresh" :size="15" />
                                    </button>
                                    @if($refund['status'] != 'rejected')
                                        <button type="button" class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                                title="{{ translate('reject') }}"
                                                data-bs-toggle="modal"
                                                data-bs-target="#rejectModal-{{$refund['id']}}">
                                            <x-k.icon name="close" :size="15" />
                                        </button>
                                    @endif
                                    @if($refund['status'] != 'approved')
                                        <button type="button" class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                                title="{{ translate('approve') }}"
                                                data-bs-toggle="modal"
                                                data-bs-target="#approveModal-{{$refund['id']}}">
                                            <x-k.icon name="check" :size="15" />
                                        </button>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if(count($refundList) == 0)
                <x-k.empty icon="refresh" :title="translate('no_refund_request_found')"
                           :text="request('searchValue') ? translate('no_refund_request_matches_your_search') : null" />
            @endif

            @if ($refundList->total() > 0)
                <x-slot:pager>
                    <span class="k-pager__info">
                        {{ translate('showing') }}
                        <span class="k-num">{{ $refundList->firstItem() }}–{{ $refundList->lastItem() }}</span>
                        {{ translate('of') }} <span class="k-num">{{ $refundList->total() }}</span>
                    </span>
                    <div>{!! $refundList->appends(request()->except('page'))->links() !!}</div>
                </x-slot:pager>
            @endif
        </x-k.data-view>
    </div>

    @include('admin-views.refund.partials._filter-offcanvas')

    @if(count($refundList ?? []) > 0)
        @foreach($refundList as $refund)
            @include('admin-views.refund.partials._approval-modal', ['refund' => $refund, 'walletStatus' => $walletStatus, 'walletAddRefund' => $walletAddRefund])
            @include('admin-views.refund.partials._reject-modal', ['refund' => $refund, 'walletStatus' => $walletStatus, 'walletAddRefund' => $walletAddRefund])
            @include('admin-views.refund.partials._refund-modal', ['refund' => $refund, 'walletStatus' => $walletStatus, 'walletAddRefund' => $walletAddRefund])
        @endforeach
    @endif
@endsection

@push('script')
    <script>
        $(function () {
            let slider = $("#price_range_slider");
            let minThumb = $("#thumb_min");
            let maxThumb = $("#thumb_max");
            let range = $(".slider-range");
            let minInput = $("#min_price");
            let maxInput = $("#max_price");

            let sliderMin = slider?.data('min-value') ?? 0;
            let sliderMax = slider?.data('max-value') ?? 100000000;

            let minValue = sliderMin;
            let maxValue = sliderMax;

            let isRtl = $('html').attr('dir') === 'rtl';

            function updateSlider() {
                let sliderWidth = slider.width();

                let minLeft = (((minValue - sliderMin) / (sliderMax - sliderMin)) * sliderWidth);
                let maxLeft = ((maxValue - sliderMin) / (sliderMax - sliderMin)) * sliderWidth;

                if (isRtl) {
                    minLeft = sliderWidth - minLeft;
                    maxLeft = sliderWidth - maxLeft;
                }

                minThumb.css(isRtl ? "insetInlineEnd" : "insetInlineStart", minLeft + "px");
                maxThumb.css(isRtl ? "insetInlineEnd" : "insetInlineStart", maxLeft + "px");

                range.css({
                    [isRtl ? 'insetInlineEnd' : 'insetInlineStart']: Math.min(minLeft, maxLeft) + "px",
                    width: Math.abs(maxLeft - minLeft) + "px",
                });

                minInput.val(minValue !== null ? minValue : minInput.attr('placeholder'));
                maxInput.val(maxValue !== null ? maxValue : maxInput.attr('placeholder'));

                let distance = maxValue - minValue;
                $('#slider_distance').text("$" + distance.toLocaleString());
            }

            function clamp(value, min, max) {
                return Math.min(Math.max(value, min), max);
            }

            function handleDrag(thumb, isMinThumb) {
                function startDrag(startX, startValue) {
                    let sliderWidth = slider.width();

                    function moveHandler(e) {
                        let pageX = e.pageX || (e.originalEvent.touches && e.originalEvent.touches[0].pageX);
                        if (!pageX) return;

                        let deltaX = isRtl ? (startX - pageX) : (pageX - startX);
                        let valueChange = (deltaX / sliderWidth) * (sliderMax - sliderMin);
                        let newValue = clamp(startValue + valueChange, sliderMin, sliderMax);

                        newValue = Math.round(newValue);

                        if (isMinThumb) {
                            minValue = Math.min(newValue, maxValue || sliderMax);
                        } else {
                            maxValue = Math.max(newValue, minValue || sliderMin);
                        }

                        updateSlider();
                    }

                    function stopHandler() {
                        $(document).off(".slider");
                    }

                    $(document).on("mousemove.slider touchmove.slider", moveHandler);
                    $(document).on("mouseup.slider touchend.slider touchcancel.slider", stopHandler);
                }

                thumb.on("mousedown touchstart", function (e) {
                    e.preventDefault();
                    let pageX = e.pageX || (e.originalEvent.touches && e.originalEvent.touches[0].pageX);
                    if (!pageX) return;

                    let startValue = isMinThumb ? minValue : maxValue;
                    startDrag(pageX, startValue);
                });
            }

            minInput.on("input", function () {
                let inputValue = parseInt($(this).val(), 10);
                if (!isNaN(inputValue)) {
                    minValue = clamp(inputValue, sliderMin, maxValue || sliderMax);
                } else {
                    minValue = null;
                }
                updateSlider();
            });

            maxInput.on("input", function () {
                let inputValue = parseInt($(this).val(), 10);
                if (!isNaN(inputValue)) {
                    maxValue = clamp(inputValue, minValue || sliderMin, sliderMax);
                } else {
                    maxValue = null;
                }
                updateSlider();
            });

            handleDrag(minThumb, true);
            handleDrag(maxThumb, false);

            updateSlider();

            $(window).on("resize", function () {
                updateSlider();
            });
            $("form").on("reset", function () {
                // Reset values to default
                minValue = sliderMin;
                maxValue = sliderMax;

                // Update inputs and slider visuals
                setTimeout(() => updateSlider(), 10);
            });
        });

    </script>
@endpush
