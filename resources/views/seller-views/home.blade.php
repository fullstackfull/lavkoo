@extends('layouts.seller.app')

@section('title', translate('nav_seller_home'))

@php
    use App\Services\SellerCenter\Status;

    $money = fn ($value) => number_format((float) $value) . ' ' . ($currency ?: 'SYP');
    $periodUrl = fn (string $key) => request()->fullUrlWithQuery(['period' => $key]);
    $compareUrl = request()->fullUrlWithQuery(['compare' => $compare ? '0' : '1']);

    /* Health rows: current value against the marketplace's own threshold, so the bar means
       "how close am I to the limit" rather than an abstract score (handoff 07.1). */
    /* Tile destinations, resolved once here: a raw @php block inside a component body is
       extracted before the component tag compiles, so it belongs at the top of the view. */
    $waiting = $briefing['waiting'] ?? [];
    $tiles = $briefing === null ? [] : [
        ['count' => $briefing['issues']['critical'] ?? 0, 'label' => translate('critical_now'), 'note' => translate('needs_immediate_attention'), 'tone' => 'critical', 'href' => \App\Services\SellerCenter\Shell::route('seller.issues.index', ['tab' => 'critical'])],
        ['count' => $waiting['sla_at_risk'] ?? 0, 'label' => translate('sla_risk_today'), 'note' => translate('orders_inside_the_ship_by_window'), 'tone' => 'high', 'href' => \App\Services\SellerCenter\Shell::route('seller.orders.index', ['view' => 'sla_risk'])],
        ['count' => $waiting['awaiting_shipment'] ?? 0, 'label' => translate('awaiting_shipment'), 'note' => translate('accepted_and_not_yet_shipped'), 'tone' => 'medium', 'href' => \App\Services\SellerCenter\Shell::route('seller.orders.index', ['view' => 'ship_today'])],
        ['count' => $waiting['returns_to_answer'] ?? 0, 'label' => translate('returns_to_answer'), 'note' => translate('awaiting_your_answer'), 'tone' => 'medium', 'href' => \App\Services\SellerCenter\Shell::route('seller.returns.index')],
    ];

    $thresholds = app(\App\Services\Marketplace\SlaService::class)->thresholds();
    $healthRows = collect($health === null ? [] : [
        ['label' => translate('cancellation'), 'value' => $health['cancellation_rate'] ?? 0, 'limit' => $thresholds['cancellation_rate'] ?? null],
        ['label' => translate('return_rate'), 'value' => $health['return_rate'] ?? 0, 'limit' => $thresholds['return_rate'] ?? null],
        ['label' => translate('refund_rate'), 'value' => $health['refund_rate'] ?? 0, 'limit' => $thresholds['refund_rate'] ?? null],
    ])->map(function (array $row) {
        $value = (float) $row['value'];
        $limit = $row['limit'] === null ? null : (float) $row['limit'];
        $ratio = ($limit && $limit > 0) ? min(1, $value / $limit) : 0;

        // Severity by distance to the marketplace's own limit, so the bar answers "how close am I"
        // rather than showing an abstract score.
        return $row + [
            'ratio' => $ratio,
            'tone' => $ratio >= 0.9 ? Status::CRITICAL : ($ratio >= 0.75 ? Status::HIGH : Status::GOOD),
        ];
    })->all();
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_home')" :title="translate('nav_seller_home')">
        <x-slot:actions>
            <x-sc.seg size="md" :current="$period" :options="collect($periods)->map(fn ($key) => [
                'key' => $key, 'label' => translate($key), 'href' => $periodUrl($key),
            ])->all()" />
            <a href="{{ $compareUrl }}" class="sc-check" style="white-space:nowrap">
                <input type="checkbox" @checked($compare) tabindex="-1" aria-hidden="true">
                <span>{{ translate('compare_previous') }}</span>
            </a>
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        {{-- KPI strip. A null comparison renders `—`, never `0%` (handoff 04 §28). --}}
        @if ($kpis === null)
            <div class="sc-kpis">
                @for ($cell = 0; $cell < 6; $cell++)
                    <div class="sc-kpi"><x-sc.skeleton :height="14" width="60%" /><x-sc.skeleton :height="22" width="80%" class="mt-2" /></div>
                @endfor
            </div>
        @else
            <div class="sc-kpis">
                @foreach ($kpis as $kpi)
                    <x-sc.kpi :label="translate($kpi['key'])"
                              :value="($kpi['rate'] ?? false)
                                    ? (($kpi['value'] === null) ? '—' : number_format($kpi['value'], 1) . '%')
                                    : (($kpi['money'] ?? false) ? number_format($kpi['value']) : number_format($kpi['value']))"
                              :unit="($kpi['money'] ?? false) ? ($currency ?: 'SYP') : null"
                              :delta="$kpi['delta']"
                              :improving="$kpi['improving']" />
                @endforeach
            </div>
        @endif

        <div class="sc-page sc-grid-home">
            <div class="sc-stack">
                {{-- Sales trend --}}
                <x-sc.card :title="translate('sales_trend')" :context="translate($period) . ' · ' . ($currency ?: 'SYP')">
                    <x-slot:actions>
                        <div class="sc-legend">
                            <span><i class="sc-legend__swatch"></i> {{ translate('this_period') }}</span>
                            @if ($compare)<span><i class="sc-legend__swatch sc-legend__swatch--compare"></i> {{ translate('previous') }}</span>@endif
                        </div>
                    </x-slot:actions>

                    @if ($trend === null)
                        <x-sc.skeleton :height="150" />
                    @elseif (array_sum($trend['current']) <= 0 && array_sum($trend['previous']) <= 0)
                        <x-sc.empty glyph="chart-line-up" :title="translate('no_sales_in_this_period')"
                                    :text="translate('sales_appear_here_once_orders_are_delivered')" />
                    @else
                        <x-sc.chart-line :series="$trend['current']" :compare="$compare ? $trend['previous'] : []"
                                         :labels="$trend['labels']" :height="150" />
                    @endif
                </x-sc.card>

                {{-- What needs you today. Every tile is a link into the list it counted. --}}
                @if ($briefing !== null)
                    <x-sc.card :title="translate('what_needs_you_today')"
                               :context="\App\Services\SellerCenter\Copy::line('n_open_n_due_today', [
                                   'open' => $briefing['issues']['total'] ?? 0,
                                   'due' => $briefing['issues']['due_today'] ?? 0,
                               ])"
                               flush>
                        <x-slot:actions>
                            @if ($towerUrl = \App\Services\SellerCenter\Shell::route('seller.control-tower'))
                                <a class="sc-btn sc-btn--ghost sc-btn--sm" href="{{ $towerUrl }}">{{ translate('open_control_tower') }} →</a>
                            @endif
                        </x-slot:actions>

                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr))">
                            @foreach ($tiles as $tile)
                                <a href="{{ $tile['href'] ?? '#' }}" style="padding:12px 16px;border-inline-end:1px solid var(--sc-line-soft);color:inherit;display:block">
                                    <div style="font-size:22px;line-height:1;font-family:var(--font-heading);font-variant-numeric:tabular-nums;color:var(--st-{{ $tile['count'] > 0 ? $tile['tone'] : 'neutral' }})">
                                        {{ $tile['count'] }}
                                    </div>
                                    <div style="font-size:12.5px;margin-top:4px">{{ $tile['label'] }}</div>
                                    <div class="sc-muted" style="font-size:11px">{{ $tile['note'] }}</div>
                                </a>
                            @endforeach
                        </div>
                    </x-sc.card>
                @endif

                {{-- Top products --}}
                <x-sc.card :title="translate('top_products')" flush>
                    @if ($topProducts === null)
                        <div style="padding:14px 16px"><x-sc.skeleton :height="96" /></div>
                    @elseif ($topProducts === [])
                        <x-sc.empty glyph="tag" :title="translate('no_sales_in_this_period')"
                                    :text="translate('the_products_that_sell_appear_here_ranked_by_revenue')" />
                    @else
                        <div class="sc-table-wrap">
                            <table class="sc-table">
                                <thead>
                                    <tr>
                                        <th>{{ translate('product') }}</th>
                                        <th style="width:130px">{{ translate('sku') }}</th>
                                        <th class="sc-cell--num" style="width:70px">{{ translate('units') }}</th>
                                        <th class="sc-cell--num" style="width:120px">{{ translate('revenue') }}</th>
                                        <th class="sc-cell--num" style="width:80px">{{ translate('stock') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($topProducts as $product)
                                        <tr>
                                            <td>{{ $product['name'] }}</td>
                                            <td class="sc-code sc-muted">{{ $product['sku'] }}</td>
                                            <td class="sc-cell--num">{{ number_format($product['units']) }}</td>
                                            <td class="sc-cell--num sc-money">{{ number_format($product['revenue']) }}</td>
                                            <td class="sc-cell--num" @if ($product['stock'] <= 0) style="color:var(--st-critical)" @endif>
                                                {{ number_format($product['stock']) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-sc.card>
            </div>

            {{-- Context column --}}
            <div class="sc-stack sc-context">
                @if ($balances === null)
                    <x-sc.card side>
                        <x-sc.alert tone="high" compact>{{ translate('the_balance_service_did_not_answer') }}</x-sc.alert>
                    </x-sc.card>
                @else
                    <x-sc.card side :label="translate('payout')">
                        <div class="sc-bignum">{{ number_format((float) $withdrawable) }}</div>
                        <div class="sc-muted" style="font-size:11px">{{ $currency ?: 'SYP' }} · {{ translate('withdrawable_now') }}</div>
                        <div style="height:1px;background:var(--sc-line-soft);margin:10px 0"></div>
                        <div class="sc-stack--tight">
                            <div class="sc-row"><span class="sc-dim" style="flex:1 1 auto;font-size:12px">{{ translate('pending_clearance') }}</span><span class="sc-money">{{ number_format((float) ($balances['pending'] ?? 0)) }}</span></div>
                            <div class="sc-row"><span class="sc-dim" style="flex:1 1 auto;font-size:12px">{{ translate('reserved_open_payout') }}</span><span class="sc-money">{{ number_format(abs((float) ($balances['reserved'] ?? 0))) }}</span></div>
                            <div class="sc-row"><span class="sc-dim" style="flex:1 1 auto;font-size:12px">{{ translate('paid_out') }}</span><span class="sc-money">{{ number_format(abs((float) ($balances['paid'] ?? 0))) }}</span></div>
                        </div>
                        @if ($payoutUrl = \App\Services\SellerCenter\Shell::route('seller.finance.index'))
                            <x-sc.button variant="primary" block :href="$payoutUrl" class="mt-2">{{ translate('request_payout') }}</x-sc.button>
                        @endif
                    </x-sc.card>
                @endif

                @if ($health !== null)
                    <x-sc.card side :label="translate('account_health')">
                        <x-slot:actions>
                            <x-sc.badge :status="$health['tier'] === 'at_risk' ? 'at_risk' : ($health['tier'] === 'watch' ? 'watch' : ($health['tier'] === 'good' ? 'healthy' : 'unknown'))" />
                        </x-slot:actions>
                        <div class="sc-stack--tight">
                            @foreach ($healthRows as $row)
                                <div>
                                    <div class="sc-row" style="gap:6px">
                                        <span style="flex:1 1 auto;font-size:12px">{{ $row['label'] }}</span>
                                        <span class="sc-num sc-muted" style="font-size:11px">
                                            {{ $row['limit'] === null
                                                ? number_format((float) $row['value'] * 100, 1) . '%'
                                                : \App\Services\SellerCenter\Copy::line('value_of_limit', [
                                                    'value' => number_format((float) $row['value'] * 100, 1) . '%',
                                                    'limit' => number_format((float) $row['limit'] * 100, 1) . '%',
                                                ]) }}
                                        </span>
                                    </div>
                                    <x-sc.progress :value="$row['ratio'] * 100" :tone="$row['tone']" class="mt-1" />
                                </div>
                            @endforeach
                        </div>
                        @if ($performanceUrl = \App\Services\SellerCenter\Shell::route('seller.performance.index'))
                            <x-sc.button variant="secondary" block :href="$performanceUrl" class="mt-2">{{ translate('nav_seller_performance') }}</x-sc.button>
                        @endif
                    </x-sc.card>
                @endif
            </div>
        </div>
    </div>
@endsection
