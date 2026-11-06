@extends('layouts.admin.app')

@use('App\Http\Controllers\Admin\OperationalCosts\CostFormat')
@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')
@use('App\Http\Controllers\Admin\OperationalCosts\CostTones')

@section('title', translate('cost_layer') . ' #' . $layer->id)

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('cost_layer') . ' #' . $layer->id" :subtitle="($productName ?? '#' . $layer->product_id) . ' · ' . ($location?->name ?? '#' . $layer->inventory_location_id)">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.product-costs.index') }}">{{ translate('back') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        <div class="alert alert-info fs-12" role="note">{{ translate('a_layer_is_never_edited_its_cost_reaches_the_books_when_a_sale_takes_units_from_it') }}</div>

        <x-k.card :title="translate('cost_layer')" class="mb-3">
            <dl class="row mb-0 fs-12">
                <dt class="col-sm-3">{{ translate('status') }}</dt>
                <dd class="col-sm-9"><x-k.badge :tone="CostTones::layer($layer->status)">{{ CostLabels::of('layer_status', $layer->status) }}</x-k.badge></dd>
                <dt class="col-sm-3">{{ translate('product') }}</dt>
                <dd class="col-sm-9">{{ $productName ?? '#' . $layer->product_id }} <span class="text-muted">#{{ $layer->product_id }}@if ($layer->variant_key !== '') · {{ $layer->variant_key }}@endif</span></dd>
                <dt class="col-sm-3">{{ translate('location') }}</dt>
                <dd class="col-sm-9">{{ $location?->name ?? '#' . $layer->inventory_location_id }}</dd>
                <dt class="col-sm-3">{{ translate('source') }}</dt>
                <dd class="col-sm-9">{{ CostLabels::of('layer_source', $layer->source_type) }} · {{ $layer->source_ref ?? '—' }}@if ($layer->supplier_name) · {{ $layer->supplier_name }}@endif</dd>
                <dt class="col-sm-3">{{ translate('received') }}</dt>
                <dd class="col-sm-9 k-num">{{ $layer->received_at?->format('Y-m-d H:i') }}</dd>
                <dt class="col-sm-3">{{ translate('quantity_remaining_of_received') }}</dt>
                <dd class="col-sm-9 k-num">{{ $layer->quantity_remaining }} / {{ $layer->quantity_received }}</dd>
                <dt class="col-sm-3">{{ translate('unit_cost') }}</dt>
                <dd class="col-sm-9 k-num">{{ CostFormat::amount($layer->unit_cost, $layer->currency) }}</dd>
                <dt class="col-sm-3">{{ translate('landed_cost_per_unit') }}</dt>
                <dd class="col-sm-9 k-num">{{ CostFormat::amount($layer->landed_unit_cost, $layer->currency) }}</dd>
                @if ($layer->original_currency)
                    <dt class="col-sm-3">{{ translate('paid_in') }}</dt>
                    <dd class="col-sm-9 k-num">{{ CostFormat::amount($layer->original_unit_cost, $layer->original_currency) }} · {{ translate('rate') }} {{ CostFormat::figure($layer->fx_rate) }}</dd>
                @endif
                <dt class="col-sm-3">{{ translate('books_of') }}</dt>
                <dd class="col-sm-9">@include('admin-views.operational-costs.partials._seller', ['seller' => $layer->seller_id])</dd>
            </dl>
        </x-k.card>

        <x-k.card :title="translate('units_taken_from_this_layer')" :padded="false">
            <div class="k-table-wrap table-responsive">
                <table class="k-table">
                    <thead><tr>
                        <th>{{ translate('taken') }}</th><th>{{ translate('taken_by') }}</th><th class="k-table__num">{{ translate('quantity') }}</th>
                        <th class="k-table__num">{{ translate('cost_taken') }}</th><th>{{ translate('state') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($consumptions as $consumption)
                        <tr @class(['text-muted' => $consumption->reversed_at !== null])>
                            <td class="fs-12 k-num">{{ $consumption->consumed_at?->format('Y-m-d H:i') }}</td>
                            <td>
                                {{ CostLabels::of('consumer_type', $consumption->consumer_type) }} #{{ $consumption->consumer_id }}
                                <div class="fs-10 text-muted">{{ translate('generation') }} {{ $consumption->generation }}</div>
                            </td>
                            <td class="k-table__num k-num">{{ $consumption->quantity }}</td>
                            <td class="k-table__num k-num">{{ CostFormat::amount($consumption->total_cost, $consumption->currency) }}</td>
                            <td>
                                @if ($consumption->reversed_at)
                                    <x-k.badge tone="neutral">{{ translate('put_back') }}</x-k.badge>
                                    <div class="fs-10 text-muted k-num">{{ $consumption->reversed_at->format('Y-m-d H:i') }}</div>
                                @else
                                    <x-k.badge tone="success">{{ translate('counted') }}</x-k.badge>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-k.empty icon="orders" :title="translate('nothing_has_been_taken_from_this_layer')" :text="translate('nothing_has_been_taken_from_this_layer_help')" /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($consumptionCount > $consumptionLimit)
                <x-slot:footer><span class="fs-12 text-muted">{{ CostLabels::filled(translate('only_the_latest_rows_are_listed'), ['shown' => $consumptionLimit, 'total' => $consumptionCount]) }}</span></x-slot:footer>
            @endif
        </x-k.card>
    </div>
@endsection
