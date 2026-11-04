@extends('layouts.seller.app')

@section('title', translate('nav_all_orders'))

@php
    use App\Services\SellerCenter\Status;

    /* Columns and their drop priority. A desktop table never shrinks below its tablet set, and
       below `sm` the whole thing becomes cards (handoff 05 A9). */
    $columns = [
        ['key' => 'order', 'label' => translate('order'), 'width' => 110, 'sortable' => true],
        ['key' => 'placed', 'label' => translate('placed'), 'width' => 104, 'sortable' => true, 'priority' => 'sm'],
        ['key' => 'customer', 'label' => translate('customer')],
        ['key' => 'items', 'label' => translate('items'), 'width' => 52, 'num' => true, 'priority' => 'md'],
        ['key' => 'total', 'label' => translate('total'), 'width' => 110, 'num' => true, 'sortable' => true],
        ['key' => 'payment', 'label' => translate('payment'), 'width' => 90, 'priority' => 'lg'],
        ['key' => 'fulfilment', 'label' => translate('fulfilment'), 'width' => 120, 'priority' => 'md'],
        ['key' => 'shipping', 'label' => translate('shipping'), 'width' => 130, 'priority' => 'xl'],
        ['key' => 'sla', 'label' => translate('sla'), 'width' => 130],
        ['key' => 'status', 'label' => translate('status'), 'width' => 120],
    ];

    $sortUrls = collect($columns)->filter(fn ($c) => $c['sortable'] ?? false)
        ->mapWithKeys(fn ($c) => [$c['key'] => $filters->urlSort($c['key'])])->all();

    /* One SLA formatter for every screen that shows a deadline (handoff 06 §5). */
    $slaText = fn (array $sla) => \App\Services\SellerCenter\Copy::sla($sla);
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_orders')" :title="translate('nav_all_orders')">
        <x-slot:actions>
            <x-sc.button variant="secondary" icon="download-simple">{{ translate('export') }}</x-sc.button>
            <x-sc.button variant="secondary" icon="printer">{{ translate('print_labels') }}</x-sc.button>
        </x-slot:actions>
    </x-sc.page-header>

    <x-sc.tabs :tabs="$views" :current="$currentView" />

    <div class="sc-scroll">
        <x-sc.toolbar :count="\App\Services\SellerCenter\Copy::line('n_orders', ['count' => $orders->total()])"
                      :search-url="route('seller.orders.index')"
                      :search-value="request('q', '')"
                      :search-placeholder="translate('order_phone_tracking_sku')"
                      :chips="$filters->chips()"
                      :clear-url="$filters->urlClearAll()"
                      :filters="$filters->available()" />

        <x-sc.table :columns="$columns" :state="$state" :sort="request('sort')" :dir="request('dir', 'asc')"
                    :sort-urls="$sortUrls">
            <x-slot:empty>
                <x-sc.empty glyph="receipt" :title="translate('no_orders_yet')"
                            :text="translate('orders_appear_here_as_soon_as_customers_buy')" />
            </x-slot:empty>
            <x-slot:noResults>
                <x-sc.empty glyph="funnel" :title="translate('no_orders_match_these_filters')"
                            :text="translate('adjust_or_clear_the_filters_to_see_more')">
                    <x-slot:actions>
                        <x-sc.button variant="secondary" :href="$filters->urlClearAll()">{{ translate('clear_all_filters') }}</x-sc.button>
                    </x-slot:actions>
                </x-sc.empty>
            </x-slot:noResults>

            @foreach ($orders as $order)
                @php($sla = $list->slaFor($order))
                @php($isCod = ($order->payment_method ?? '') === 'cash_on_delivery')
                <x-sc.tr :href="route('seller.orders.show', $order->id)" :id="$order->id">
                    <x-sc.td :sub="$order->order_type === 'POS' ? translate('pos') : translate('marketplace')">
                        <span class="sc-code" style="color:var(--color-accent)">#{{ $order->id }}</span>
                    </x-sc.td>
                    <x-sc.td drop="sm" class="sc-muted sc-ts">{{ \App\Services\SellerCenter\Moment::stamp($order->created_at) }}</x-sc.td>
                    <x-sc.td :sub="$order->shippingAddress->city ?? null">
                        {{ $order->customer ? trim($order->customer->f_name . ' ' . $order->customer->l_name) : translate('walk_in_customer') }}
                    </x-sc.td>
                    <x-sc.td num drop="md">{{ $order->orderDetails->count() }}</x-sc.td>
                    <x-sc.td num><span class="sc-money">{{ number_format((float) $order->order_amount) }}</span></x-sc.td>
                    <x-sc.td drop="lg">
                        <x-sc.badge :status="$isCod ? 'cod' : 'card'" :label="$isCod ? translate('cod') : translate('card')" />
                    </x-sc.td>
                    <x-sc.td drop="md" class="sc-muted">
                        {{ $order->order_type === 'POS' ? translate('pos') : translate('seller_fulfilled') }}
                    </x-sc.td>
                    <x-sc.td drop="xl" :sub="$order->deliveryMan->f_name ?? null">
                        {{ $order->shipping_type ? translate($order->shipping_type) : '—' }}
                    </x-sc.td>
                    <x-sc.td>
                        <span class="sc-row" style="gap:4px;flex-wrap:nowrap;color:{{ $sla['tone'] === 'neutral' ? 'var(--sc-dim)' : 'var(--st-' . $sla['tone'] . ')' }}">
                            <x-sc.icon :name="$sla['glyph']" :size="12" />
                            <span class="sc-num">{{ $slaText($sla) }}</span>
                        </span>
                    </x-sc.td>
                    <x-sc.td><x-sc.badge :status="$order->order_status" /></x-sc.td>
                </x-sc.tr>
            @endforeach

            <x-slot:mobile>
                @foreach ($orders as $order)
                    @php($sla = $list->slaFor($order))
                    <x-sc.entity-card :title="'#' . $order->id" :href="route('seller.orders.show', $order->id)"
                                      :figure="number_format((float) $order->order_amount)"
                                      :meta="\App\Services\SellerCenter\Copy::line('n_items_payment', [
                                          'count' => $order->orderDetails->count(),
                                          'payment' => ($order->payment_method ?? '') === 'cash_on_delivery' ? translate('cod') : translate('card'),
                                      ])">
                        <div class="sc-row">
                            <x-sc.badge :status="$order->order_status" />
                            <span class="sc-muted" style="font-size:11.5px">{{ $slaText($sla) }}</span>
                        </div>
                    </x-sc.entity-card>
                @endforeach
            </x-slot:mobile>

            <x-slot:footer>
                <x-sc.pager :paginator="$orders" />
            </x-slot:footer>
        </x-sc.table>
    </div>
@endsection
