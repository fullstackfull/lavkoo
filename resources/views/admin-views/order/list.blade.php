@extends('layouts.admin.app')

@section('title', translate('order_List'))

@section('content')
    @php
        use App\Utils\OrderManager;

        $statusHeading = match ($status) {
            'processing' => translate('packaging_Orders'),
            'failed'     => translate('failed_to_Deliver_Orders'),
            'all'        => translate('all_Orders'),
            default      => translate(str_replace('_', ' ', $status)) . ' ' . translate('Orders'),
        };

        // Everything the export link and the pagination have to carry so a filtered
        // view exports and paginates the same rows it is showing.
        $currentFilters = [
            'delivery_man_id'          => request('delivery_man_id'),
            'from'                     => $from,
            'to'                       => $to,
            'filter'                   => $filter,
            'searchValue'              => $searchValue,
            'seller_id'                => $vendorId,
            'customer_id'              => $customerId,
            'date_type'                => $dateType,
            'payment_status'           => request('payment_status'),
            'order_current_status'     => request('order_current_status'),
            'order_amount_settlement'  => request('order_amount_settlement'),
        ];
        $hasFilters = collect($currentFilters)->filter(fn ($value) => !is_null($value) && $value !== '' && $value !== [])->isNotEmpty();

        $statusTiles = [
            ['key' => 'pending',          'label' => translate('Pending'),           'value' => $allOrdersInfo['pending_order'],          'icon' => 'hourglass'],
            ['key' => 'confirmed',        'label' => translate('Confirmed'),         'value' => $allOrdersInfo['confirmed_order'],        'icon' => 'check'],
            ['key' => 'processing',       'label' => translate('Packaging'),         'value' => $allOrdersInfo['processing_order'],       'icon' => 'catalog'],
            ['key' => 'out_for_delivery', 'label' => translate('Out_For_Delivery'),  'value' => $allOrdersInfo['out_for_delivery_order'], 'icon' => 'arrow-end'],
            ['key' => 'delivered',        'label' => translate('Delivered'),         'value' => $allOrdersInfo['delivered_order'],        'icon' => 'orders'],
            ['key' => 'canceled',         'label' => translate('Canceled'),          'value' => $allOrdersInfo['canceled_order'],         'icon' => 'close'],
            ['key' => 'returned',         'label' => translate('Returned'),          'value' => $allOrdersInfo['returned_order'],         'icon' => 'refresh'],
            ['key' => 'failed',           'label' => translate('Failed_to_Deliver'), 'value' => $allOrdersInfo['failed_order'],           'icon' => 'alert'],
        ];

        // A bulk action may only set statuses that need no per-order input; the
        // endpoint enforces the same list.
        $bulkStatuses = [
            'confirmed'        => translate('Confirmed'),
            'processing'       => translate('Packaging'),
            'out_for_delivery' => translate('Out_For_Delivery'),
            'delivered'        => translate('Delivered'),
            'canceled'         => translate('Canceled'),
        ];

        $statusTone = fn ($value) => match ($value) {
            'delivered', 'confirmed' => 'success',
            'processing', 'out_for_delivery' => 'warning',
            'pending' => 'info',
            default => 'danger',
        };
    @endphp

    <div class="content container-fluid">
        <x-k.page-header :title="$statusHeading">
            <x-slot:actions>
                <x-k.button variant="secondary" icon="download"
                            :href="route('admin.orders.export-excel', ['status' => $status] + $currentFilters)">
                    {{ translate('export') }}
                </x-k.button>
            </x-slot:actions>
        </x-k.page-header>

@if ($status == 'all')
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 16px;">
        @foreach ($statusTiles as $tile)
            <a href="{{ route('admin.orders.list', [$tile['key']]) }}" style="text-decoration: none; color: inherit;">
                <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; text-align: center;">
                    @php
                        $icons = [
                            'pending' => '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
                            'confirmed' => '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
                            'processing' => '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
                            'out_for_delivery' => '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
                            'delivered' => '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
                            'canceled' => '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
                            'returned' => '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#f97316" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>',
                            'failed' => '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
                        ];
                    @endphp
                    
                    <div style="margin-bottom: 8px;">
                        {!! $icons[$tile['key']] !!}
                    </div>
                    <div style="font-size: 13px; color: #6b7280; margin-bottom: 4px;">{{ $tile['label'] }}</div>
                    <div style="font-size: 24px; font-weight: bold; color: #111827;">{{ $tile['value'] }}</div>
                </div>
            </a>
        @endforeach
    </div>
@endif
        <x-k.data-view :title="translate('order_List')" :count="$orders->total()" :selectable="true"
                       searchName="searchValue" :searchValue="$searchValue"
                       :searchPlaceholder="translate('search_by_Order_ID')">

            <x-slot:actions>
                <div class="position-relative">
                    @if ($hasFilters)
                        {{-- A filtered list that looks unfiltered is how people misread a report. --}}
                        <span class="position-absolute inset-inline-end-0 top-0 mt-n1 me-n1 btn-circle bg-danger border border-white border-2"
                              style="--size: 10px;"></span>
                    @endif
                    <x-k.button variant="secondary" icon="filter" data-bs-toggle="offcanvas"
                                data-bs-target="#offcanvasOrderFilter">
                        {{ translate('Filter') }}
                    </x-k.button>
                </div>
            </x-slot:actions>

            @if ($hasFilters)
                <x-slot:filters>
                    @foreach ($currentFilters as $key => $value)
                        @continue(is_null($value) || $value === '' || $value === [])
                        <x-k.chip :key="translate(str_replace('_', ' ', $key))" :removable="false">
                            {{ is_array($value) ? implode(', ', $value) : $value }}
                        </x-k.chip>
                    @endforeach
                    <a class="k-btn k-btn--ghost k-btn--sm" href="{{ route('admin.orders.list', [$status]) }}">
                        {{ translate('clear_all') }}
                    </a>
                </x-slot:filters>
            @endif

            <x-slot:bulk>
                @foreach ($bulkStatuses as $value => $label)
                    <x-k.button variant="secondary" size="sm" data-k-bulk-status="{{ $value }}">
                        {{ translate('mark_as') }} {{ $label }}
                    </x-k.button>
                @endforeach
            </x-slot:bulk>

            <table class="k-table" id="order-table">
                <thead>
                <tr>
                    <th style="inline-size:44px">
                        <input type="checkbox" data-k-select-all aria-label="{{ translate('select_all') }}">
                    </th>
                    <th>{{ translate('order_ID') }}</th>
                    <th>{{ translate('order_date') }}</th>
                    <th>{{ translate('customer_info') }}</th>
                    <th>{{ translate('Store') }}</th>
                    <th class="k-table__num">{{ translate('total_amount') }}</th>
                    <th>{{ $status == 'all' ? translate('order_status') : translate('payment_method') }}</th>
                    <th></th>
                </tr>
                </thead>

                <tbody>
                @foreach ($orders as $key => $order)
                    @php $orderTotalPriceSummary = OrderManager::getOrderTotalPriceSummary(order: $order); @endphp
                    <tr class="status-{{ $order['order_status'] }} class-all">
                        <td>
                            <input type="checkbox" data-k-row-select value="{{ $order['id'] }}"
                                   aria-label="{{ translate('select_order') }} {{ $order['id'] }}">
                        </td>

<td>
    <div class="k-row" style="gap:var(--k-size-1)">
        <a href="{{ route('admin.orders.details', ['id' => $order['id']]) }}" 
           style="font-weight:500; color: #000000 !important; text-decoration: none;">
            <span class="k-num" style="color: #000000 !important;">{{ $order['id'] }}</span>
        </a>                                

                                
                                
@if ($order->order_type == 'POS')
    <x-k.badge tone="dark" :dot="false" 
               style="background-color: #ffffff !important; color: #000000 !important;">
        {{ translate('POS') }}
    </x-k.badge>
@endif                                
                                
                                @if ($order->edited_status == 1 && $order?->latestEditHistory?->order_due_payment_status == 'unpaid' && $order?->latestEditHistory?->order_due_payment_method != 'offline_payment' && $order?->latestEditHistory?->order_due_payment_method != 'cash_on_delivery' && $order?->latestEditHistory?->order_due_amount > 0)
                                    <span title="{{ translate('customer_will_pay_due_the_amount') }}" class="k-text-subtle">
                                        <x-k.icon name="alert" :size="14" />
                                    </span>
                                @elseif ($order->edited_status == 1 && $order?->latestEditHistory?->order_return_payment_status == 'pending' && $order?->latestEditHistory?->order_return_amount > 0)
                                    <span title="{{ translate('you_need_to_return_the_excess_amount_to_the_customer.') }}" class="k-text-subtle">
                                        <x-k.icon name="refresh" :size="14" />
                                    </span>
                                @endif
                            </div>
                            @if ($order->edited_status == 1)
                                <x-k.badge tone="info" :dot="false">{{ translate('Edited') }}</x-k.badge>
                            @endif
                        </td>

                        <td class="k-text-muted">
                            <div>{{ date('d M Y', strtotime($order['created_at'])) }}</div>
                            <div class="k-text-subtle">{{ date('h:i A', strtotime($order['created_at'])) }}</div>
                        </td>

<td>
    @if ($order->is_guest)
        <strong style="color: #000000 !important;">{{ translate('guest_customer') }}</strong>
    @elseif ($order->customer_id == 0)
        <strong style="color: #000000 !important;">{{ translate('Walk-In-Customer') }}</strong>
    @elseif ($order->customer)
        <span class="k-row" style="color: #000000 !important;">
            <x-k.avatar :name="$order->customer['f_name'] . ' ' . $order->customer['l_name']" />
            <span style="min-inline-size:0; color: #000000 !important;">
                <a class="k-truncate" 
                   style="display:block; font-weight:500; color: #000000 !important; text-decoration: none !important;"
                   href="{{ route('admin.customer.view', ['user_id' => $order->customer['id']]) }}">
                    {{ $order->customer['f_name'] . ' ' . $order->customer['l_name'] }}
                </a>
                @if ($order->customer['phone'])
                    <a class="k-text-subtle" 
                       href="tel:{{ $order->customer['phone'] }}" 
                       style="color: #000000 !important; text-decoration: none !important;">
                        {{ $order->customer['phone'] }}
                    </a>
                @else
                    <a class="k-text-subtle" 
                       href="mailto:{{ $order->customer['email'] }}" 
                       style="color: #000000 !important; text-decoration: none !important;">
                        {{ $order->customer['email'] }}
                    </a>
                @endif
            </span>
        </span>
    @else
        <x-k.badge tone="danger">{{ translate('customer_not_found') }}</x-k.badge>
    @endif
</td>
<td style="color: #000000;">
    @if (isset($order->seller_id) && isset($order->seller_is))
        <a class="k-truncate" style="display:block;max-inline-size:220px; color: #000000 !important; text-decoration: none;"
           href="{{ $order->seller_is == 'seller' && $order->seller?->shop ? route('admin.vendors.view', ['id' => $order->seller->shop->id]) : route('admin.business-settings.inhouse-shop') }}">
            @if ($order->seller_is == 'seller')
                {{ $order->seller?->shop?->name ?? translate('Store_not_found') }}
            @else
                {{ getInHouseShopConfig(key: 'name') }}
            @endif
        </a>
    @else
        <span style="color: #000000;">{{ translate('Store_not_found') }}</span>
    @endif
</td>




                        <td class="k-table__num">
                            <div class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['totalAmount']), currencyCode: getCurrencyCode()) }}</div>
                            @if ($order->payment_status == 'paid')
                                <span class="k-text-subtle" style="color:var(--k-success-text)">{{ translate('paid') }}</span>
                            @else
                                <span class="k-text-subtle" style="color:var(--k-danger-text)">{{ translate('unpaid') }}</span>
                            @endif
                        </td>

                        <td>
                            @if ($status == 'all')
                                <x-k.badge :tone="$statusTone($order['order_status'])">
                                    @if ($order['order_status'] == 'processing')
                                        {{ translate('packaging') }}
                                    @elseif ($order['order_status'] == 'failed')
                                        {{ translate('failed_to_deliver') }}
                                    @else
                                        {{ translate(str_replace('_', ' ', $order['order_status'])) }}
                                    @endif
                                </x-k.badge>
                            @else
                                <span class="k-text-muted">{{ translate(str_replace('_', ' ', $order['payment_method'])) }}</span>
                            @endif
                        </td>

                        <td>
                            <div class="k-table__actions">
                                @if ($order->edited_status == 1 && $order?->edit_return_amount > 0)
                                    <button type="button" class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                            title="{{ translate('return_amount') }}"
                                            data-bs-toggle="modal" data-bs-target="#returnDueAmountModal-{{ $order['id'] }}">
                                        <x-k.icon name="refresh" :size="15" />
                                    </button>
                                @endif
                                <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{ translate('view') }}"
                                   href="{{ route('admin.orders.details', ['id' => $order['id']]) }}">
                                    <x-k.icon name="eye" :size="15" />
                                </a>
                                <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon" target="_blank" title="{{ translate('invoice') }}"
                                   href="{{ route('admin.orders.generate-invoice', [$order['id']]) }}">
                                    <x-k.icon name="download" :size="15" />
                                </a>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if (count($orders) == 0)
                <x-k.empty icon="orders" :title="translate('no_order_found')"
                           :text="$hasFilters ? translate('no_order_matches_the_filters_you_applied') : translate('orders_will_appear_here_as_soon_as_a_customer_checks_out')">
                    @if ($hasFilters)
                        <x-slot:action>
                            <x-k.button variant="secondary" :href="route('admin.orders.list', [$status])">
                                {{ translate('clear_all') }}
                            </x-k.button>
                        </x-slot:action>
                    @endif
                </x-k.empty>
            @endif

            @if ($orders->total() > 0)
                <x-slot:pager>
                    <span class="k-pager__info">
                        {{ translate('showing') }}
                        <span class="k-num">{{ $orders->firstItem() }}–{{ $orders->lastItem() }}</span>
                        {{ translate('of') }} <span class="k-num">{{ $orders->total() }}</span>
                    </span>
                    <div>{!! $orders->appends($currentFilters)->links() !!}</div>
                </x-slot:pager>
            @endif
        </x-k.data-view>
    </div>

    <span id="message-date-range-text" data-text="{{ translate('invalid_date_range') }}"></span>
    <span id="js-data-example-ajax-url" data-url="{{ route('admin.orders.customers') }}"></span>

    @include('admin-views.order.partials._filter-offcanvas')

    @if ($orders->isNotEmpty())
        @foreach ($orders as $order)
            @include('admin-views.order.partials.modal.order-edit-return-amount-modal', ['order' => $order])
        @endforeach
    @endif
@endsection













@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin/order.js') }}"></script>
    
    <script>
        "use strict";
        (function () {
            var view = document.querySelector('[data-k-selectable]');
            if (!view) return;

            var endpoint = @json(route('admin.orders.bulk-status'));
            var csrf = document.querySelector('meta[name="csrf-token"]');
            var labels = {
                confirm: @json(translate('change_the_status_of')),
                orders: @json(translate('orders')),
                working: @json(translate('updating_orders')),
                failed: @json(translate('could_not_update_the_orders')),
                skipped: @json(translate('skipped')),
            };

            view.addEventListener('click', function (event) {
                var trigger = event.target.closest('[data-k-bulk-status]');
                if (!trigger) return;

                var ids = Kohl.selectedIds(view);
                if (!ids.length) return;

                // State-changing and hard to walk back: name the count and the target
                // status before doing it.
                var status = trigger.getAttribute('data-k-bulk-status');
                if (!confirm(labels.confirm + ' ' + ids.length + ' ' + labels.orders + '?')) return;

                trigger.disabled = true;
                Kohl.toast({title: labels.working, tone: 'neutral', duration: 2000});

                fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : ''
                    },
                    body: JSON.stringify({ids: ids, order_status: status})
                })
                    .then(function (response) { return response.json(); })
                    .then(function (body) {
                        // Partial success is normal — an order failing its own guards is
                        // skipped, and the operator needs to know which and why.
                        var skipped = body.skipped || [];
                        Kohl.toast({
                            title: body.message || labels.failed,
                            text: skipped.length
                                ? labels.skipped + ': #' + skipped.map(function (s) { return s.id; }).join(', #')
                                : null,
                            tone: body.status === 1 ? (skipped.length ? 'warning' : 'success') : 'danger',
                        });
                        if (body.updated) setTimeout(function () { location.reload(); }, 1200);
                        else trigger.disabled = false;
                    })
                    .catch(function () {
                        Kohl.toast({title: labels.failed, tone: 'danger'});
                        trigger.disabled = false;
                    });
            });
        })();
    </script>
@endpush