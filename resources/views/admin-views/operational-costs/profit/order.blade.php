@extends('layouts.admin.app')

@use('App\Enums\OperationalCosts\CostClass')
@use('App\Enums\OperationalCosts\CostComponent')
@use('App\Http\Controllers\Admin\OperationalCosts\CostFormat')
@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')

@section('title', translate('profit_of_a_sale') . ' ' . $document->reference)

@section('content')
    @php
        $costed = $result->buckets !== [];
        $money = static fn ($amount): string => CostFormat::amount($amount, $currency);
        $moneyIfCosted = static fn ($amount): string => $money($costed ? $amount : null);
        $missing = count($result->missingComponents);
        $pending = count($result->pendingActualComponents);
        $drillNotes = array_map(static fn (string $note): array => array_pad(explode(':', $note, 2), 2, null), $result->notes);
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="translate('profit_of_a_sale') . ' ' . $document->reference" :subtitle="CostLabels::channel($document->salesChannel) . ' · ' . CostLabels::of('sale_state', $document->state) . ' · ' . $result->occurredOn">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.profit.index') }}">{{ translate('profitability') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.entries.index', ['from' => '', 'to' => '', 'q' => $document->reference, 'seller' => 'all']) }}">{{ translate('cost_entries') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        @include('admin-views.operational-costs.partials._module-state')

        <div class="alert alert-info fs-12" role="note">{{ translate('a_marketplace_payout_is_not_revenue_note') }}</div>

        @if ($drillNotes !== [] || !$result->revenueTrusted || !$costed)
            <div class="alert alert-warning fs-12" role="alert">
                <strong>{{ translate('this_figure_is_less_than_certain') }}</strong>
                <ul class="mb-0">
                    @unless ($costed)
                        <li>{{ translate('no_costs_recorded_for_this_sale') }}</li>
                    @endunless
                    @foreach ($drillNotes as [$note, $detail])
                        <li>{{ CostLabels::of('profit_note', $note) }}@if ($detail !== null) ({{ CostComponent::tryFrom($detail) ? translate(CostComponent::from($detail)->labelKey()) : $detail }})@endif</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="k-stats mb-3">
            <x-k.stat :label="translate('recognised_revenue')" :value="$money($result->revenue)" :caption="$document->isCancelled() ? translate('a_cancelled_sale_has_no_revenue') : ''" />
            <x-k.stat :label="translate('estimated_cost')" :value="$moneyIfCosted($result->estimatedCost)" :caption="$costed ? CostLabels::completeness($result->estimatedComplete() ? 0 : 1, $missing, 0) : translate('nothing_recorded')" />
            <x-k.stat :label="translate('actual_cost')" :value="$moneyIfCosted($result->actualCost)" :caption="$costed ? CostLabels::completeness($result->actualComplete() ? 0 : 1, $missing, $pending) : translate('nothing_recorded')" />
            <x-k.stat :label="translate('estimated_profit')" :value="$moneyIfCosted($result->estimatedProfit())" :caption="$costed ? translate('margin') . ': ' . CostFormat::percent($result->estimatedMarginPercent()) : ''" />
            <x-k.stat :label="translate('actual_profit')" :value="$moneyIfCosted($result->actualProfit())" :caption="$costed ? translate('margin') . ': ' . CostFormat::percent($result->actualMarginPercent()) : ''" />
        </div>
        @if (!$result->disputedCost->isZero())
            <p class="fs-12 text-muted">{{ translate('disputed_costs_are_in_neither_figure') }}: <span class="k-num">{{ $money($result->disputedCost) }}</span></p>
        @endif

        <div class="row g-3">
            <div class="col-lg-7">
                <x-k.card :title="translate('costs_by_component')" :padded="false" class="mb-3">
                    <div class="k-table-wrap table-responsive">
                        <table class="k-table">
                            <thead><tr>
                                <th>{{ translate('component') }}</th><th class="k-table__num">{{ translate('estimated') }}</th><th class="k-table__num">{{ translate('actual') }}</th>
                                <th class="k-table__num">{{ translate('missing') }}</th><th class="k-table__num">{{ translate('pending') }}</th><th class="k-table__num">{{ translate('disputed') }}</th>
                            </tr></thead>
                            <tbody>
                            @forelse ($result->buckets as $bucket)
                                @php $costComponent = CostComponent::tryFrom($bucket->component); @endphp
                                <tr>
                                    <td>{{ $costComponent ? translate($costComponent->labelKey()) : $bucket->component }}<div class="fs-10 text-muted">{{ CostClass::tryFrom($bucket->costClass) ? translate(CostClass::from($bucket->costClass)->labelKey()) : $bucket->costClass }}</div></td>
                                    <td class="k-table__num k-num">{{ $money($bucket->estimated) }}</td>
                                    <td class="k-table__num k-num">{{ $money($bucket->actual) }}</td>
                                    <td class="k-table__num k-num">{{ $bucket->missing }}</td>
                                    <td class="k-table__num k-num">{{ $bucket->pendingActual }}</td>
                                    <td class="k-table__num k-num">{{ $bucket->disputed }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6"><x-k.empty icon="search" :title="translate('no_costs_recorded_for_this_sale')" :text="translate('no_costs_recorded_for_this_sale_help')" /></td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-k.card>

                <x-k.card :title="translate('the_cost_entries_behind_this_result')" :padded="false">
                    <div class="k-table-wrap table-responsive">
                        <table class="k-table">
                            <thead><tr>
                                <th>#</th><th>{{ translate('component') }}</th><th>{{ translate('state') }}</th><th class="k-table__num">{{ translate('amount') }}</th>
                                <th class="k-table__num">{{ translate('base_amount') }}</th><th>{{ translate('source') }}</th>
                            </tr></thead>
                            <tbody>
                            @forelse ($entries as $entry)
                                <tr>
                                    <td><a href="{{ route('admin.operational-costs.entries.show', $entry->id) }}">#{{ $entry->id }}</a></td>
                                    <td>{{ $entry->component() ? translate($entry->component()->labelKey()) : $entry->cost_component }}</td>
                                    <td>@include('admin-views.operational-costs.partials._cost-state', ['state' => $entry->state])</td>
                                    <td class="k-table__num">@include('admin-views.operational-costs.partials._entry-amount', ['entry' => $entry])</td>
                                    <td class="k-table__num k-num">{{ CostFormat::amount($entry->base_amount, $entry->base_currency) }}</td>
                                    <td class="fs-12">{{ CostLabels::of('entry_source', $entry->source) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6"><x-k.empty icon="search" :title="translate('no_costs_recorded_for_this_sale')" :text="translate('no_costs_recorded_for_this_sale_help')" /></td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-k.card>
            </div>

            <div class="col-lg-5">
                <x-k.card :title="translate('how_the_revenue_is_made_up')" class="mb-3">
                    <dl class="row mb-0 fs-12">
                        <dt class="col-sm-7">{{ translate('goods_sold') }}</dt>
                        <dd class="col-sm-5 k-num">{{ CostFormat::amount($document->linesRevenue(), $document->currency) }}</dd>
                        <dt class="col-sm-7">{{ translate('shipping_charged_to_the_customer') }}</dt>
                        <dd class="col-sm-5 k-num">{{ CostFormat::amount($document->shippingCharged, $document->currency) }}</dd>
                        <dt class="col-sm-7">{{ translate('discounts_on_the_whole_order') }}</dt>
                        <dd class="col-sm-5 k-num">{{ CostFormat::amount($document->orderDiscounts, $document->currency) }}</dd>
                        <dt class="col-sm-7">{{ translate('refunds') }}</dt>
                        <dd class="col-sm-5 k-num">{{ CostFormat::amount($document->refundsTotal(), $document->currency) }}</dd>
                        <dt class="col-sm-7 fw-semibold">{{ translate('net_revenue') }}</dt>
                        <dd class="col-sm-5 k-num fw-semibold">{{ CostFormat::amount($document->netRevenue(), $document->currency) }}</dd>
                    </dl>
                </x-k.card>

                <x-k.card :title="translate('lines_of_the_sale')" :padded="false">
                    <div class="k-table-wrap table-responsive">
                        <table class="k-table">
                            <thead><tr><th>{{ translate('product') }}</th><th class="k-table__num">{{ translate('quantity') }}</th><th class="k-table__num">{{ translate('revenue') }}</th><th class="k-table__num">{{ translate('refunded') }}</th></tr></thead>
                            <tbody>
                            @foreach ($document->lines as $line)
                                <tr>
                                    <td>@if ($line->productId)#{{ $line->productId }}@else—@endif @if ($line->variantKey !== '')<span class="text-muted">· {{ $line->variantKey }}</span>@endif</td>
                                    <td class="k-table__num k-num">{{ $line->quantity }}</td>
                                    <td class="k-table__num k-num">{{ CostFormat::amount($line->revenue, $document->currency) }}</td>
                                    <td class="k-table__num k-num">{{ CostFormat::amount($line->refunded, $document->currency) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-k.card>
            </div>
        </div>
    </div>
@endsection
