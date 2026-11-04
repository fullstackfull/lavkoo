{{--
    Seller operational KPIs.

    Two rules this deliberately follows:
    1. NO FABRICATED TRENDS. When there is no comparable prior period the card says so instead of
       showing an invented percentage — one made-up number makes a seller distrust all of them.
    2. Inventory alerts link straight to the filtered product list, so the card is an action, not
       a decoration.
--}}
@php
    $revenue = $salesComparison['revenue'] ?? null;
    $orders  = $salesComparison['orders'] ?? null;
@endphp

<div class="row g-2 mb-3">
    {{-- Revenue this month --}}
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">{{ translate('revenue_this_month') }}</div>
                <div class="h4 mb-1"><x-ui.money :amount="$revenue['current'] ?? 0" /></div>
                @if(($revenue['state'] ?? '') === 'comparable' && $revenue['change'] !== null)
                    <span class="badge {{ $revenue['direction'] === 'up' ? 'badge-soft-success' : ($revenue['direction'] === 'down' ? 'badge-soft-danger' : 'badge-soft-secondary') }}">
                        {{ $revenue['change'] > 0 ? '+' : '' }}{{ $revenue['change'] }}% {{ translate('vs_previous_period') }}
                    </span>
                @elseif(($revenue['state'] ?? '') === 'new')
                    <span class="badge badge-soft-info">{{ translate('first_sales_no_previous_period') }}</span>
                @else
                    <span class="text-muted small">{{ translate('no_comparison_data_yet') }}</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Orders this month --}}
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">{{ translate('orders_this_month') }}</div>
                <div class="h4 mb-1">{{ (int) ($orders['current'] ?? 0) }}</div>
                @if(($orders['state'] ?? '') === 'comparable' && $orders['change'] !== null)
                    <span class="badge {{ $orders['direction'] === 'up' ? 'badge-soft-success' : ($orders['direction'] === 'down' ? 'badge-soft-danger' : 'badge-soft-secondary') }}">
                        {{ $orders['change'] > 0 ? '+' : '' }}{{ $orders['change'] }}% {{ translate('vs_previous_period') }}
                    </span>
                @elseif(($orders['state'] ?? '') === 'new')
                    <span class="badge badge-soft-info">{{ translate('first_orders_no_previous_period') }}</span>
                @else
                    <span class="text-muted small">{{ translate('no_comparison_data_yet') }}</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Low stock --}}
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">{{ translate('low_stock_products') }}</div>
                <div class="h4 mb-1 {{ ($inventoryAlerts['low_stock'] ?? 0) > 0 ? 'text-warning' : '' }}">
                    {{ (int) ($inventoryAlerts['low_stock'] ?? 0) }}
                </div>
                <span class="text-muted small">
                    {{ translate('at_or_below') }} {{ (int) ($inventoryAlerts['threshold'] ?? 5) }}
                </span>
            </div>
        </div>
    </div>

    {{-- Out of stock --}}
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">{{ translate('out_of_stock_products') }}</div>
                <div class="h4 mb-1 {{ ($inventoryAlerts['out_of_stock'] ?? 0) > 0 ? 'text-danger' : '' }}">
                    {{ (int) ($inventoryAlerts['out_of_stock'] ?? 0) }}
                </div>
                <span class="text-muted small">{{ translate('needs_restocking') }}</span>
            </div>
        </div>
    </div>
</div>
