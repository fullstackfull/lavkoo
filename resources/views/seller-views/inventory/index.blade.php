@extends('layouts.seller.app')

@section('title', translate('nav_inventory'))

@php
    use App\Services\SellerCenter\Lists\InventoryList;

    $columns = [
        ['key' => 'sku', 'label' => translate('sku'), 'width' => 130, 'sortable' => true],
        ['key' => 'product', 'label' => translate('product'), 'sortable' => true],
        ['key' => 'available', 'label' => translate('available'), 'width' => 90, 'num' => true, 'sortable' => true],
        ['key' => 'reserved', 'label' => translate('reserved'), 'width' => 90, 'num' => true],
        ['key' => 'physical', 'label' => translate('physical'), 'width' => 90, 'num' => true, 'priority' => 'md'],
        ['key' => 'daily', 'label' => translate('daily_sales'), 'width' => 100, 'num' => true, 'priority' => 'lg'],
        ['key' => 'coverage', 'label' => translate('coverage'), 'width' => 110],
        ['key' => 'state', 'label' => translate('state'), 'width' => 120],
        ['key' => 'action', 'label' => '', 'width' => 100],
    ];

    $sortUrls = collect($columns)->filter(fn ($c) => $c['sortable'] ?? false)
        ->mapWithKeys(fn ($c) => [$c['key'] => $filters->urlSort($c['key'])])->all();

    $views = collect(InventoryList::VIEWS)->map(fn ($view, $key) => [
        'key' => $key,
        'label' => translate($view['label']),
        'href' => $key === 'all' ? route('seller.inventory.index') : route('seller.inventory.index', ['view' => $key]),
        'tone' => $view['tone'],
    ])->values()->all();
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_inventory')" :title="translate('inventory_overview')">
        <x-slot:actions>
            <x-sc.button variant="secondary" icon="list" :href="route('seller.inventory.movements')">{{ translate('nav_movements') }}</x-sc.button>
        </x-slot:actions>
    </x-sc.page-header>

    <x-sc.tabs :tabs="$views" :current="$currentView" />

    <div class="sc-scroll">
        <div class="sc-page" style="padding-bottom:0">
            <div class="sc-stats">
                <x-sc.stat :label="translate('units_on_hand')" :value="number_format($summary['units_on_hand'])"
                           :note="\App\Services\SellerCenter\Copy::line('across_n_skus', ['count' => number_format($summary['skus'])])" />
                <x-sc.stat :label="translate('running_low')" :value="number_format($summary['running_low'])"
                           :tone="$summary['running_low'] > 0 ? 'high' : null"
                           :note="\App\Services\SellerCenter\Copy::line('at_or_under_n_units', ['count' => $summary['threshold']])" />
                <x-sc.stat :label="translate('out_of_stock')" :value="number_format($summary['out_of_stock'])"
                           :tone="$summary['out_of_stock'] > 0 ? 'critical' : null"
                           :note="translate('not_sellable_right_now')" />
                <x-sc.stat :label="translate('reserved')" :value="number_format($summary['reserved'])"
                           :note="translate('held_by_open_orders')" />
            </div>

            @if ($summary['running_low'] > 0 || $summary['out_of_stock'] > 0)
                <x-sc.alert tone="high" class="mt-3"
                            :title="\App\Services\SellerCenter\Copy::line('n_lines_running_low', ['count' => $summary['running_low']])">
                    {{ translate('disclaimer_coverage') }}
                    <x-slot:action>
                        <x-sc.button variant="secondary" size="sm" :href="route('seller.inventory.index', ['view' => 'low_stock'])">
                            {{ \App\Services\SellerCenter\Copy::line('review_n_lines', ['count' => $summary['running_low']]) }}
                        </x-sc.button>
                    </x-slot:action>
                </x-sc.alert>
            @endif
        </div>

        <x-sc.toolbar :count="\App\Services\SellerCenter\Copy::line('n_skus', ['count' => $products->total()])"
                      :search-url="route('seller.inventory.index')"
                      :search-value="request('q', '')"
                      :search-placeholder="translate('name_sku_barcode')"
                      :chips="$filters->chips()"
                      :clear-url="$filters->urlClearAll()"
                      :filters="$filters->available()" />

        <x-sc.table :columns="$columns" :state="$state" :sort="request('sort')" :dir="request('dir', 'asc')"
                    :sort-urls="$sortUrls">
            <x-slot:empty>
                <x-sc.empty glyph="stack" :title="translate('no_stock_to_track_yet')"
                            :text="translate('physical_products_appear_here_with_their_cover_and_their_movements')" />
            </x-slot:empty>
            <x-slot:noResults>
                <x-sc.empty glyph="funnel" :title="translate('no_stock_matches_these_filters')"
                            :text="translate('adjust_or_clear_the_filters_to_see_more')" />
            </x-slot:noResults>

            @foreach ($products as $product)
                @php($available = (int) $product->current_stock)
                @php($held = $reserved[$product->id] ?? 0)
                @php($daily = $velocity[$product->id] ?? 0.0)
                @php($cover = $list->coverage($available, $daily))
                @php($state = $list->stateFor($available, $cover, $summary['threshold']))
                <x-sc.tr :href="url('vendor/products/update/' . $product->id)" :id="$product->id">
                    <x-sc.td class="sc-code">{{ $product->code ?: '—' }}</x-sc.td>
                    <x-sc.td>{{ $product->getRawOriginal('name') }}</x-sc.td>
                    <x-sc.td num :tone="$available <= 0 ? 'critical' : null">{{ number_format($available) }}</x-sc.td>
                    <x-sc.td num class="sc-muted">{{ $held > 0 ? number_format($held) : '—' }}</x-sc.td>
                    <x-sc.td num drop="md" class="sc-muted">{{ number_format($available + $held) }}</x-sc.td>
                    <x-sc.td num drop="lg" class="sc-muted">{{ $daily > 0 ? number_format($daily, 1) : '—' }}</x-sc.td>
                    {{-- Velocity of zero renders `—`, never `∞`: infinite cover is a statement about
                         a product nobody is buying. --}}
                    <x-sc.td :tone="$cover === null ? null : $state['tone']">
                        <span class="sc-num" data-sc-tip="{{ translate('disclaimer_coverage') }}">
                            {{ $cover === null ? '—' : \App\Services\SellerCenter\Copy::line('n_days_cover', ['count' => number_format($cover, 1)]) }}
                        </span>
                    </x-sc.td>
                    <x-sc.td><x-sc.badge :status="$state['state']" /></x-sc.td>
                    <x-sc.td action>
                        <a class="sc-btn sc-btn--ghost sc-btn--sm" href="{{ url('vendor/products/update/' . $product->id) }}">
                            {{ $available <= 0 ? translate('restock') : translate('adjust') }}
                        </a>
                    </x-sc.td>
                </x-sc.tr>
            @endforeach

            <x-slot:mobile>
                @foreach ($products as $product)
                    @php($available = (int) $product->current_stock)
                    @php($cover = $list->coverage($available, $velocity[$product->id] ?? 0.0))
                    @php($state = $list->stateFor($available, $cover, $summary['threshold']))
                    <x-sc.entity-card :title="$product->getRawOriginal('name')" :href="url('vendor/products/update/' . $product->id)"
                                      :figure="number_format($available)"
                                      :meta="\App\Services\SellerCenter\Copy::line('sku_and_cover', [
                                          'sku' => $product->code ?: '—',
                                          'cover' => $cover === null ? '—' : \App\Services\SellerCenter\Copy::line('n_days_cover', ['count' => number_format($cover, 1)]),
                                      ])">
                        <div class="sc-row"><x-sc.badge :status="$state['state']" /></div>
                    </x-sc.entity-card>
                @endforeach
            </x-slot:mobile>

            <x-slot:footer><x-sc.pager :paginator="$products" /></x-slot:footer>
        </x-sc.table>
    </div>
@endsection
