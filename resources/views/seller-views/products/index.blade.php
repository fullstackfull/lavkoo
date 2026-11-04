@extends('layouts.seller.app')

@section('title', translate('nav_products'))

@php
    $columns = [
        ['key' => 'product', 'label' => translate('product'), 'sortable' => true],
        ['key' => 'sku', 'label' => translate('sku'), 'width' => 130],
        ['key' => 'brand', 'label' => translate('brand'), 'width' => 90, 'priority' => 'md'],
        ['key' => 'category', 'label' => translate('category'), 'width' => 120, 'priority' => 'md'],
        ['key' => 'price', 'label' => translate('price'), 'width' => 110, 'num' => true, 'sortable' => true],
        ['key' => 'stock', 'label' => translate('stock'), 'width' => 80, 'num' => true, 'sortable' => true],
        ['key' => 'quality', 'label' => translate('listing_quality'), 'width' => 130, 'priority' => 'lg'],
        ['key' => 'status', 'label' => translate('status'), 'width' => 130],
        ['key' => 'issue', 'label' => translate('issue'), 'width' => 230],
    ];

    $sortUrls = collect($columns)->filter(fn ($c) => $c['sortable'] ?? false)
        ->mapWithKeys(fn ($c) => [$c['key'] => $filters->urlSort($c['key'])])->all();

    $views = collect(\App\Services\SellerCenter\Lists\ProductList::VIEWS)->map(fn ($view, $key) => [
        'key' => $key,
        'label' => translate($view['label']),
        'href' => $key === 'all' ? route('seller.products.index') : route('seller.products.index', ['view' => $key]),
        'tone' => $view['tone'],
    ])->values()->all();
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_catalog')" :title="translate('nav_products')">
        <x-slot:actions>
            <x-sc.button variant="secondary" icon="upload-simple" :href="url('vendor/products/bulk-import')">{{ translate('bulk_import') }}</x-sc.button>
            <x-sc.button variant="primary" icon="plus" :href="url('vendor/products/add')">{{ translate('nav_add_product') }}</x-sc.button>
        </x-slot:actions>
    </x-sc.page-header>

    <x-sc.tabs :tabs="$views" :current="$currentView" />

    <div class="sc-scroll">
        <x-sc.toolbar :count="\App\Services\SellerCenter\Copy::line('n_products', ['count' => $products->total()])"
                      :search-url="route('seller.products.index')"
                      :search-value="request('q', '')"
                      :search-placeholder="translate('name_sku_barcode')"
                      :chips="$filters->chips()"
                      :clear-url="$filters->urlClearAll()"
                      :filters="$filters->available()" />

        <x-sc.table :columns="$columns" :state="$state" :sort="request('sort')" :dir="request('dir', 'asc')"
                    :sort-urls="$sortUrls">
            <x-slot:empty>
                <x-sc.empty glyph="tag" :title="translate('no_products_yet')"
                            :text="translate('add_your_first_product_to_start_selling')">
                    <x-slot:actions>
                        <x-sc.button variant="primary" :href="url('vendor/products/add')">{{ translate('nav_add_product') }}</x-sc.button>
                    </x-slot:actions>
                </x-sc.empty>
            </x-slot:empty>
            <x-slot:noResults>
                <x-sc.empty glyph="funnel" :title="translate('no_products_match_these_filters')"
                            :text="translate('adjust_or_clear_the_filters_to_see_more')">
                    <x-slot:actions>
                        <x-sc.button variant="secondary" :href="$filters->urlClearAll()">{{ translate('clear_all_filters') }}</x-sc.button>
                    </x-slot:actions>
                </x-sc.empty>
            </x-slot:noResults>

            @foreach ($products as $product)
                @php($status = $list->statusOf($product))
                @php($quality = $list->listingQuality($product))
                @php($issue = $issues[$product->id] ?? null)
                @php($stock = (int) $product->current_stock)
                <x-sc.tr :href="url('vendor/products/update/' . $product->id)" :id="$product->id">
                    <x-sc.td>{{ $product->getRawOriginal('name') }}</x-sc.td>
                    <x-sc.td class="sc-code sc-muted">{{ $product->code ?: '—' }}</x-sc.td>
                    <x-sc.td drop="md" class="sc-muted">{{ $product->brand->name ?? '—' }}</x-sc.td>
                    <x-sc.td drop="md" class="sc-muted">{{ $product->category->name ?? '—' }}</x-sc.td>
                    <x-sc.td num><span class="sc-money">{{ number_format((float) $product->unit_price) }}</span></x-sc.td>
                    <x-sc.td num :tone="$stock <= 0 ? 'critical' : null">{{ number_format($stock) }}</x-sc.td>
                    <x-sc.td drop="lg">
                        <div class="sc-row" style="gap:6px;flex-wrap:nowrap">
                            <x-sc.progress :value="$quality" :tone="$list->qualityTone($quality)" style="flex:1 1 auto" />
                            <span class="sc-num sc-muted" style="font-size:11px">{{ $quality }}%</span>
                        </div>
                    </x-sc.td>
                    <x-sc.td><x-sc.badge :status="$status" /></x-sc.td>
                    {{-- The precise reason, never the word "Error". No issue leaves the cell empty. --}}
                    <x-sc.td>
                        @if ($issue)
                            <div class="sc-dim" style="font-size:11.5px">{{ $issue->body ?: translate($issue->title) }}</div>
                            @php($action = \App\Services\SellerCenter\IssueAction::resolve($issue->action_key, $issue->action_params))
                            @if ($action['href'])
                                <a class="sc-btn sc-btn--ghost sc-btn--sm" href="{{ $action['href'] }}" data-sc-stop>{{ $action['label'] }}</a>
                            @endif
                        @endif
                    </x-sc.td>
                </x-sc.tr>
            @endforeach

            <x-slot:mobile>
                @foreach ($products as $product)
                    @php($issue = $issues[$product->id] ?? null)
                    <x-sc.entity-card :title="$product->getRawOriginal('name')" :href="url('vendor/products/update/' . $product->id)"
                                      :figure="number_format((float) $product->unit_price)"
                                      :meta="\App\Services\SellerCenter\Copy::line('sku_and_stock', ['sku' => $product->code ?: '—', 'stock' => (int) $product->current_stock])">
                        <div class="sc-row">
                            <x-sc.badge :status="$list->statusOf($product)" />
                        </div>
                        @if ($issue)
                            <div class="sc-dim" style="font-size:11.5px">{{ $issue->body ?: translate($issue->title) }}</div>
                        @endif
                    </x-sc.entity-card>
                @endforeach
            </x-slot:mobile>

            <x-slot:footer><x-sc.pager :paginator="$products" /></x-slot:footer>
        </x-sc.table>
    </div>
@endsection
