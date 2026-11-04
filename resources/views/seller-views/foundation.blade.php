@extends('layouts.seller.app')

@section('title', translate('foundation'))

@php
    use App\Services\SellerCenter\TableFilters;

    /* Wave 1's acceptance screen. Everything below is configuration of the shared systems — there
       is no screen-specific CSS anywhere in this file, which is the point of the exercise. */

    $filters = new TableFilters(request(), [
        'status'    => ['label' => 'status', 'type' => 'enum', 'group' => 'order', 'options' => [
            ['value' => 'ready_to_ship', 'label' => translate('ready_to_ship')],
            ['value' => 'shipped', 'label' => translate('shipped')],
            ['value' => 'delivered', 'label' => translate('delivered')],
        ]],
        'severity'  => ['label' => 'severity', 'type' => 'enum', 'group' => 'issue', 'options' => [
            ['value' => 'critical', 'label' => translate('critical')],
            ['value' => 'high', 'label' => translate('high')],
        ]],
        'date_from' => ['label' => 'placed_from', 'type' => 'date', 'group' => 'dates'],
        'total'     => ['label' => 'total', 'type' => 'number', 'group' => 'finance'],
        'carrier'   => ['label' => 'carrier', 'type' => 'text', 'group' => 'fulfilment'],
        'cod'       => ['label' => 'cod_only', 'type' => 'boolean', 'group' => 'finance'],
    ], route('seller.foundation'));

    $columns = [
        ['key' => 'order', 'label' => translate('order'), 'width' => 110, 'sortable' => true],
        ['key' => 'placed', 'label' => translate('placed'), 'width' => 104, 'sortable' => true, 'priority' => 'sm'],
        ['key' => 'customer', 'label' => translate('customer')],
        ['key' => 'items', 'label' => translate('items'), 'width' => 52, 'num' => true, 'priority' => 'md'],
        ['key' => 'total', 'label' => translate('total'), 'width' => 110, 'num' => true, 'sortable' => true],
        ['key' => 'payment', 'label' => translate('payment'), 'width' => 90, 'priority' => 'lg'],
        ['key' => 'fulfilment', 'label' => translate('fulfilment'), 'width' => 120, 'priority' => 'md'],
        ['key' => 'shipping', 'label' => translate('shipping'), 'width' => 130, 'priority' => 'xl'],
        ['key' => 'sla', 'label' => translate('sla'), 'width' => 120],
        ['key' => 'status', 'label' => translate('status'), 'width' => 120],
    ];

    $sortUrls = collect($columns)->filter(fn ($column) => $column['sortable'] ?? false)
        ->mapWithKeys(fn ($column) => [$column['key'] => $filters->urlSort($column['key'])])->all();

    $views = collect(['ship_today', 'sla_risk', 'late', 'all'])->map(fn ($key) => [
        'key' => $key,
        'label' => translate($key),
        'href' => $filters->urlWithParams(['view' => $key === 'all' ? null : $key]),
        'count' => ['ship_today' => 31, 'sla_risk' => 14, 'late' => 3, 'all' => 218][$key],
        'tone' => ['ship_today' => 'high', 'sla_risk' => 'high', 'late' => 'critical', 'all' => 'neutral'][$key],
    ])->all();

    $state = $state ?? 'normal';
    /* Rows exist only so the geometry can be checked; the screen is debug-only and never ships. */
    $rows = $state === 'normal' || $state === 'refetching' ? range(1, 6) : [];
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('foundation')" :title="translate('component_foundation')"
                      :sub="translate('every_shared_pattern_on_one_page_assembled_from_configuration_only')">
        <x-slot:actions>
            <x-sc.button variant="secondary" icon="download-simple">{{ translate('export') }}</x-sc.button>
            <x-sc.button variant="primary" icon="plus">{{ translate('create_shipment') }}</x-sc.button>
        </x-slot:actions>
    </x-sc.page-header>

    <x-sc.tabs :tabs="$views" :current="request('view', 'all')">
        <a class="sc-btn sc-btn--ghost sc-btn--sm" href="#"><x-sc.icon name="plus" :size="12" />{{ translate('save_current_view') }}</a>
    </x-sc.tabs>

    <div class="sc-scroll">
        <x-sc.toolbar :count="'218 ' . translate('orders')"
                      :search-url="route('seller.foundation')"
                      :search-value="request('q', '')"
                      :search-placeholder="translate('order_phone_tracking_sku')"
                      :chips="$filters->chips()"
                      :clear-url="$filters->urlClearAll()"
                      :filters="$filters->available()">
            <button type="button" class="sc-btn sc-btn--secondary sc-btn--sm" data-sc-menu-toggle="sc-columns">
                <x-sc.icon name="rows" :size="12" />{{ translate('columns') }}
            </button>
        </x-sc.toolbar>

        <x-sc.bulk-bar>
            <input type="hidden" data-sc-bulk-ids>
            <button type="button" class="sc-btn sc-btn--ghost sc-btn--sm">{{ translate('accept') }}</button>
            <button type="button" class="sc-btn sc-btn--ghost sc-btn--sm">{{ translate('mark_packed') }}</button>
            <button type="button" class="sc-btn sc-btn--ghost sc-btn--sm">{{ translate('print_labels') }}</button>
            <button type="button" class="sc-btn sc-btn--ghost sc-btn--sm" style="color:var(--st-critical)">{{ translate('cancel') }}…</button>
        </x-sc.bulk-bar>

        <x-sc.table :columns="$columns" :state="$state" selectable
                    :sort="request('sort')" :dir="request('dir', 'asc')" :sort-urls="$sortUrls"
                    :note="$state === 'partial' ? translate('some_carrier_data_could_not_be_loaded') : null">

            <x-slot:empty>
                <x-sc.empty glyph="receipt" :title="translate('no_orders_yet')" :text="translate('orders_appear_here_as_soon_as_customers_buy')" />
            </x-slot:empty>

            <x-slot:noResults>
                <x-sc.empty glyph="funnel" :title="translate('no_orders_match_these_filters')" :text="translate('adjust_or_clear_the_filters_to_see_more')">
                    <x-slot:actions>
                        <x-sc.button variant="secondary" :href="$filters->urlClearAll()">{{ translate('clear_all_filters') }}</x-sc.button>
                    </x-slot:actions>
                </x-sc.empty>
            </x-slot:noResults>

            <x-slot:error>
                <div style="padding:20px">
                    <x-sc.alert tone="critical" :title="translate('this_list_could_not_be_loaded')">
                        {{ translate('the_orders_service_did_not_answer') }} (request 8f31-2c).
                        <x-slot:action><x-sc.button variant="secondary" icon="arrow-clockwise">{{ translate('retry') }}</x-sc.button></x-slot:action>
                    </x-sc.alert>
                </div>
            </x-slot:error>

            <x-slot:permission>
                <x-sc.permission :module="translate('orders')" />
            </x-slot:permission>

            @foreach ($rows as $row)
                @php
                    $severity = [1 => 'critical', 2 => 'high', 3 => 'medium', 4 => 'good', 5 => 'neutral', 6 => 'high'][$row];
                    $status = [1 => 'late', 2 => 'ready_to_ship', 3 => 'processing', 4 => 'delivered', 5 => 'cancelled', 6 => 'packed'][$row];
                @endphp
                <x-sc.tr :href="route('seller.foundation')" :id="900140 + $row">
                    <x-sc.td select>
                        <label class="sc-check"><input type="checkbox" data-sc-row-select value="{{ 900140 + $row }}"
                                                       aria-label="{{ translate('select_row') }}"></label>
                    </x-sc.td>
                    <x-sc.td :sub="translate('marketplace')"><span class="sc-code" style="color:var(--color-accent)">#{{ 900140 + $row }}</span></x-sc.td>
                    <x-sc.td drop="sm" class="sc-muted">24 Aug 08:1{{ $row }}</x-sc.td>
                    <x-sc.td :sub="'Damascus'">{{ ['هدى العلي', 'Layla Nasser', 'سامر خليل', 'Rana Haddad', 'مازن قاسم', 'Nour Aziz'][$row - 1] }}</x-sc.td>
                    <x-sc.td num drop="md">{{ $row }}</x-sc.td>
                    <x-sc.td num><span class="sc-money">{{ number_format(417000 - $row * 13000) }}</span></x-sc.td>
                    <x-sc.td drop="lg"><x-sc.badge status="cod" :label="translate('cod')" /></x-sc.td>
                    <x-sc.td drop="md" class="sc-muted">{{ translate('seller_fulfilled') }}</x-sc.td>
                    <x-sc.td drop="xl" :sub="'Delivery Syria'">{{ translate('label_created') }}</x-sc.td>
                    <x-sc.td :tone="$severity === 'critical' ? 'critical' : null">
                        <span class="sc-row" style="gap:4px;flex-wrap:nowrap">
                            <x-sc.icon :name="$severity === 'critical' ? 'warning-octagon' : 'clock'" :size="12" />
                            <span class="sc-num">{{ $severity === 'critical' ? translate('breached_by') . ' 42m' : (4 - ($row % 4)) . 'h ' . (10 + $row) . 'm ' . translate('left') }}</span>
                        </span>
                    </x-sc.td>
                    <x-sc.td><x-sc.badge :status="$status" /></x-sc.td>
                </x-sc.tr>
            @endforeach

            <x-slot:mobile>
                @foreach ($rows as $row)
                    <x-sc.entity-card :title="'#' . (900140 + $row)" :href="route('seller.foundation')"
                                      :figure="number_format(417000 - $row * 13000) . ' SYP'"
                                      :meta="$row . ' ' . translate('items') . ' · ' . translate('cod')">
                        <x-sc.badge :status="[1 => 'late', 2 => 'ready_to_ship', 3 => 'processing', 4 => 'delivered', 5 => 'cancelled', 6 => 'packed'][$row]" />
                        <x-slot:actions>
                            <x-sc.button variant="secondary" size="sm">{{ translate('mark_packed') }}</x-sc.button>
                        </x-slot:actions>
                    </x-sc.entity-card>
                @endforeach
            </x-slot:mobile>
        </x-sc.table>

        {{-- The remaining shared patterns, so the wave's checklist can be run on one page. --}}
        <div class="sc-page">
            <div class="sc-grid-two">
                <x-sc.card :title="translate('severity_and_status')">
                    <div class="sc-row">
                        @foreach (['critical', 'high', 'medium', 'low'] as $severity)
                            <x-sc.badge :severity="$severity" />
                        @endforeach
                    </div>
                    <div class="sc-row" style="margin-top:10px">
                        @foreach (['active', 'under_review', 'rejected', 'expiring_soon', 'out_of_stock', 'unknown'] as $status)
                            <x-sc.badge :status="$status" />
                        @endforeach
                    </div>
                </x-sc.card>

                <x-sc.card :title="translate('states')">
                    <div class="sc-row">
                        @foreach (['normal', 'loading', 'refetching', 'empty', 'no_results', 'error', 'permission'] as $option)
                            <a class="sc-btn sc-btn--secondary sc-btn--sm" href="{{ $filters->urlWithParams(['state' => $option]) }}">{{ translate($option) }}</a>
                        @endforeach
                    </div>
                </x-sc.card>

                <x-sc.card :title="translate('controls')">
                    <div class="sc-stack">
                        <div class="sc-row">
                            <x-sc.button variant="primary">{{ translate('primary') }}</x-sc.button>
                            <x-sc.button variant="secondary">{{ translate('secondary') }}</x-sc.button>
                            <x-sc.button variant="ghost">{{ translate('ghost') }}</x-sc.button>
                            <x-sc.button variant="danger">{{ translate('danger') }}</x-sc.button>
                            <x-sc.button variant="primary" loading>{{ translate('saving') }}…</x-sc.button>
                            <x-sc.button variant="secondary" disabled>{{ translate('disabled') }}</x-sc.button>
                        </div>
                        <x-sc.field :label="translate('selling_price')" required :help="translate('no_decimal_places_in_syp')">
                            <x-sc.input type="number" num suffix="SYP" value="417000" />
                        </x-sc.field>
                        <x-sc.field :label="translate('reason')" :error="translate('a_reason_is_required_to_reject')">
                            <x-sc.textarea invalid rows="2" />
                        </x-sc.field>
                        <div class="sc-row">
                            <x-sc.toggle :label="translate('cod_only')" checked />
                            <x-sc.checkbox :label="translate('compare_previous')" checked />
                        </div>
                    </div>
                </x-sc.card>

                <x-sc.card :title="translate('feedback')">
                    <div class="sc-stack">
                        <x-sc.alert tone="high" :title="translate('ship_by_sla_at_risk') . ' — 1h 24m ' . translate('left')">
                            {{ translate('carrier_pickup_for_damascus_closes_1700') }}
                            <x-slot:action><x-sc.button variant="secondary" size="sm">{{ translate('resolve_shortage') }}</x-sc.button></x-slot:action>
                        </x-sc.alert>
                        <x-sc.progress :value="71" :label="'71% · 5,978 ' . translate('of') . ' 8,420'" tone="high" />
                        <x-sc.health :label="translate('integrations')" state="degraded" :count="3" />
                        <x-sc.health :label="translate('pricing')" state="healthy" />
                        <x-sc.health :label="translate('advertising')" state="unknown" />
                        <x-sc.stepper :steps="[['label' => translate('submitted')], ['label' => translate('under_review')], ['label' => translate('approved')]]" :current="1" />
                    </div>
                </x-sc.card>
            </div>
        </div>

        <x-sc.pager />
    </div>

    <div class="sc-menu" id="sc-columns" hidden style="inset-inline-end:20px;top:120px">
        <div class="sc-menu__group">{{ translate('columns') }}</div>
        @foreach ($columns as $column)
            <label class="sc-menu__item"><input type="checkbox" checked>{{ $column['label'] }}</label>
        @endforeach
    </div>
@endsection
