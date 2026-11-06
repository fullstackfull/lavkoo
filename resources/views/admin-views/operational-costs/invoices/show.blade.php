@extends('layouts.admin.app')

@use('App\Enums\OperationalCosts\CostComponent')
@use('App\Models\OperationalCostInvoice')
@use('App\Http\Controllers\Admin\OperationalCosts\CostFormat')
@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')
@use('App\Http\Controllers\Admin\OperationalCosts\CostTones')

@section('title', translate('invoice') . ' ' . $invoice->invoice_number)

@section('content')
    @php
        $isPurchase = $invoice->kind === 'purchase';
        $canApprove = in_array($invoice->status, [OperationalCostInvoice::STATUS_DRAFT, OperationalCostInvoice::STATUS_UNDER_REVIEW], true);
        $canVoid = in_array($invoice->status, [OperationalCostInvoice::STATUS_DRAFT, OperationalCostInvoice::STATUS_UNDER_REVIEW, OperationalCostInvoice::STATUS_DISPUTED], true);
        $isApproved = $invoice->status === OperationalCostInvoice::STATUS_APPROVED;
        $itemsById = $items->keyBy('id');
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="translate('invoice') . ' ' . $invoice->invoice_number" :subtitle="$invoice->party_name . ' · ' . CostLabels::of('invoice_kind', $invoice->kind)">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.invoices.index') }}">{{ translate('back') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        @if ($invoice->status === OperationalCostInvoice::STATUS_VOID)
            <div class="alert alert-secondary fs-12" role="note">{{ translate('this_invoice_is_void_and_counts_for_nothing') }}</div>
        @endif

        @if ($twin)
            <div class="alert alert-warning fs-12" role="alert">
                <strong>{{ translate('this_invoice_looks_like_another') }}</strong>
                <a href="{{ route('admin.operational-costs.invoices.show', $twin->id) }}">{{ $twin->invoice_number }} (#{{ $twin->id }})</a> —
                {{ translate('same_company_currency_total_and_period') }} {{ translate('it_can_not_be_approved_until_a_person_confirms_it_is_not_a_copy') }}
                <form method="POST" action="{{ route('admin.operational-costs.invoices.not-duplicate', $invoice->id) }}" class="mt-2">@csrf
                    <button class="btn btn-outline-warning btn-sm" type="submit">{{ translate('this_is_not_a_duplicate') }}</button>
                </form>
            </div>
        @endif

        @unless ($itemsAddUp)
            <div class="alert alert-warning fs-12" role="alert">
                <strong>{{ translate('the_lines_do_not_add_up_to_the_invoice') }}</strong>
                {{ translate('lines_add_up_to') }} <span class="k-num">{{ CostFormat::amount($itemsTotal, $invoice->currency) }}</span>,
                {{ translate('the_invoice_says') }} <span class="k-num">{{ CostFormat::amount($invoice->subtotal ?? $invoice->total, $invoice->currency) }}</span>.
            </div>
        @endunless

        <div class="row g-3">
            <div class="col-lg-8">
                <x-k.card :title="translate('the_invoice')" class="mb-3">
                    <dl class="row mb-0 fs-12">
                        <dt class="col-sm-4">{{ translate('status') }}</dt>
                        <dd class="col-sm-8"><x-k.badge :tone="CostTones::invoice($invoice->status)">{{ CostLabels::of('invoice_status', $invoice->status) }}</x-k.badge></dd>
                        <dt class="col-sm-4">{{ translate('company_name') }}</dt>
                        <dd class="col-sm-8">{{ $invoice->party_name }} <span class="text-muted" dir="ltr">· {{ $invoice->provider_code }} · {{ CostLabels::of('party_type', $invoice->party_type) }}</span></dd>
                        <dt class="col-sm-4">{{ translate('invoice_date') }}</dt>
                        <dd class="col-sm-8 k-num">{{ $invoice->invoice_date->toDateString() }}</dd>
                        <dt class="col-sm-4">{{ translate('period') }}</dt>
                        <dd class="col-sm-8 k-num">@if ($invoice->period_start && $invoice->period_end){{ $invoice->period_start->toDateString() }} → {{ $invoice->period_end->toDateString() }}@else—@endif</dd>
                        <dt class="col-sm-4">{{ translate('subtotal') }}</dt>
                        <dd class="col-sm-8 k-num">{{ CostFormat::amount($invoice->subtotal, $invoice->currency) }}</dd>
                        <dt class="col-sm-4">{{ translate('tax_total') }}</dt>
                        <dd class="col-sm-8 k-num">{{ CostFormat::amount($invoice->tax_total, $invoice->currency) }}</dd>
                        <dt class="col-sm-4">{{ translate('total') }}</dt>
                        <dd class="col-sm-8 k-num fw-semibold">{{ CostFormat::amount($invoice->total, $invoice->currency) }}</dd>
                        @if ($location)
                            <dt class="col-sm-4">{{ translate('location_the_goods_arrived_at') }}</dt>
                            <dd class="col-sm-8">{{ $location->name }}</dd>
                        @endif
                        <dt class="col-sm-4">{{ translate('books_of') }}</dt>
                        <dd class="col-sm-8">@include('admin-views.operational-costs.partials._seller', ['seller' => $invoice->seller_id])</dd>
                        <dt class="col-sm-4">{{ translate('source') }}</dt>
                        <dd class="col-sm-8">{{ $invoice->source }}</dd>
                        @if ($invoice->approved_at)
                            <dt class="col-sm-4">{{ translate('approved') }}</dt>
                            <dd class="col-sm-8 k-num">{{ $invoice->approved_at->format('Y-m-d H:i') }}</dd>
                        @endif
                        @if ($invoice->notes)
                            <dt class="col-sm-4">{{ translate('notes') }}</dt>
                            <dd class="col-sm-8 text-break" style="white-space: pre-line">{{ $invoice->notes }}</dd>
                        @endif
                    </dl>
                </x-k.card>

                <x-k.card :title="translate('invoice_lines')" :padded="false" class="mb-3">
                    <div class="k-table-wrap table-responsive">
                        <table class="k-table">
                            <thead><tr>
                                <th>#</th><th>{{ translate('description') }}</th><th>{{ translate('cost_component') }}</th><th class="k-table__num">{{ translate('quantity') }}</th>
                                <th class="k-table__num">{{ translate('amount') }}</th><th>{{ translate('linked_to') }}</th><th>{{ translate('state') }}</th><th>{{ translate('cost_entry') }}</th>
                            </tr></thead>
                            <tbody>
                            @forelse ($items as $item)
                                <tr>
                                    <td class="k-num">{{ $item->line_no }}</td>
                                    <td>{{ $item->description ?? '—' }}<div class="fs-10 text-muted text-break" dir="ltr">{{ $item->external_reference }}@if ($item->service_date) · {{ $item->service_date->toDateString() }}@endif</div></td>
                                    <td>{{ CostComponent::tryFrom($item->cost_component) ? translate(CostComponent::from($item->cost_component)->labelKey()) : $item->cost_component }}</td>
                                    <td class="k-table__num k-num">{{ $item->quantity === null ? '—' : CostFormat::figure($item->quantity) }}</td>
                                    <td class="k-table__num k-num">{{ CostFormat::amount($item->amount, $item->currency) }}</td>
                                    <td class="fs-12">
                                        @if ($item->provider_fulfillment_id)
                                            {{ translate('shipment') }}: <a href="{{ route('admin.fulfillment-providers.fulfillments.show', $item->provider_fulfillment_id) }}">{{ $shipments[$item->provider_fulfillment_id] ?? '#' . $item->provider_fulfillment_id }}</a>
                                        @elseif ($item->external_order_mapping_id)
                                            {{ translate('channel_order') }}: <a href="{{ route('admin.operational-costs.profit.order', ['type' => 'channel_order', 'id' => $item->external_order_mapping_id]) }}">{{ $channelOrders[$item->external_order_mapping_id]->external_order_id ?? '#' . $item->external_order_mapping_id }}</a>
                                        @elseif ($item->order_id)
                                            {{ translate('order') }}: <a href="{{ route('admin.operational-costs.profit.order', ['type' => 'order', 'id' => $item->order_id]) }}">#{{ $item->order_id }}</a>
                                        @elseif ($item->product_id)
                                            {{ translate('product') }} #{{ $item->product_id }}
                                        @else
                                            <x-k.badge tone="warning" :dot="false">{{ translate('not_linked') }}</x-k.badge>
                                            @if ($item->external_reference)<div class="fs-10 text-muted">{{ translate('the_reference_did_not_identify_exactly_one_shipment_or_order') }}</div>@endif
                                        @endif
                                    </td>
                                    <td><x-k.badge :tone="$item->match_status === 'matched' ? 'success' : ($item->match_status === 'unmatched' ? 'neutral' : 'warning')" :dot="false">{{ CostLabels::of('invoice_item_match', $item->match_status) }}</x-k.badge></td>
                                    <td>@if ($item->entry_id)<a href="{{ route('admin.operational-costs.entries.show', $item->entry_id) }}">#{{ $item->entry_id }}</a>@else—@endif</td>
                                </tr>
                            @empty
                                <tr><td colspan="8"><x-k.empty icon="orders" :title="translate('this_invoice_has_no_lines')" :text="translate('this_invoice_has_no_lines_help')" /></td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    <x-slot:footer><span class="fs-12 text-muted">{{ translate('lines_add_up_to') }} <span class="k-num">{{ CostFormat::amount($itemsTotal, $invoice->currency) }}</span></span></x-slot:footer>
                </x-k.card>

                @if ($isPurchase)
                    <x-k.card :title="translate('stock_cost_layers_created_by_this_invoice')" :padded="false" class="mb-3">
                        <div class="k-table-wrap table-responsive">
                            <table class="k-table">
                                <thead><tr><th>#</th><th>{{ translate('product') }}</th><th class="k-table__num">{{ translate('quantity') }}</th><th class="k-table__num">{{ translate('unit_cost') }}</th><th class="k-table__num">{{ translate('landed_cost_per_unit') }}</th></tr></thead>
                                <tbody>
                                @forelse ($layers as $layer)
                                    <tr>
                                        <td><a href="{{ route('admin.operational-costs.product-costs.show', $layer->id) }}">#{{ $layer->id }}</a></td>
                                        <td>#{{ $layer->product_id }}@if ($layer->variant_key !== '') · {{ $layer->variant_key }}@endif</td>
                                        <td class="k-table__num k-num">{{ $layer->quantity_received }}</td>
                                        <td class="k-table__num k-num">{{ CostFormat::amount($layer->unit_cost, $layer->currency) }}</td>
                                        <td class="k-table__num k-num">{{ CostFormat::amount($layer->landed_unit_cost, $layer->currency) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="fs-12 text-muted">{{ translate('no_cost_layers_until_the_invoice_is_approved') }}</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </x-k.card>
                @else
                    <x-k.card :title="translate('reconciliation_billed_against_estimated')" :padded="false" class="mb-3">
                        <div class="p-3 fs-12 text-muted">{{ translate('reconciliation_explained') }} {{ translate('system_default_tolerance') }}: <span class="k-num">{{ $defaultTolerance }}%</span></div>
                        <div class="k-table-wrap table-responsive">
                            <table class="k-table">
                                <thead><tr>
                                    <th>#</th><th class="k-table__num">{{ translate('estimated') }}</th><th class="k-table__num">{{ translate('billed') }}</th><th class="k-table__num">{{ translate('difference') }}</th>
                                    <th class="k-table__num">{{ translate('tolerance') }}</th><th>{{ translate('status') }}</th><th>{{ translate('decision') }}</th>
                                </tr></thead>
                                <tbody>
                                @forelse ($reconciliations as $row)
                                    @php $isOpen = in_array($row->status, $openReconciliations, true); @endphp
                                    <tr>
                                        <td>
                                            {{ $itemsById[$row->invoice_item_id]->line_no ?? '' }}
                                            <div class="fs-10 text-muted">{{ $itemsById[$row->invoice_item_id]->description ?? '' }}</div>
                                        </td>
                                        <td class="k-table__num k-num">{{ CostFormat::amount($row->estimated_amount, $row->currency) }}</td>
                                        <td class="k-table__num k-num">{{ CostFormat::amount($row->actual_amount, $row->currency) }}</td>
                                        <td class="k-table__num k-num">
                                            {{ CostFormat::amount($row->variance_amount, $row->currency) }}
                                            @if ($row->variance_percent !== null)<div class="fs-10 text-muted">{{ CostFormat::percent($row->variance_percent) }}</div>@endif
                                        </td>
                                        <td class="k-table__num k-num">{{ CostFormat::percent($row->tolerance_percent) }}</td>
                                        <td><x-k.badge :tone="CostTones::reconciliation($row->status)">{{ CostLabels::of('reconciliation_status', $row->status) }}</x-k.badge></td>
                                        <td>
                                            @if ($row->resolution)
                                                {{ CostLabels::of('reconciliation_resolution', $row->resolution) }}
                                                <div class="fs-10 text-muted">{{ $row->note }}</div>
                                            @elseif ($isOpen)
                                                <form method="POST" action="{{ route('admin.operational-costs.invoices.resolve', [$invoice->id, $row->id]) }}" class="d-flex flex-column gap-1">@csrf
                                                    <select class="form-select form-select-sm" name="resolution" required aria-label="{{ translate('decision') }}">
                                                        @foreach (CostLabels::values('reconciliation_resolution') as $resolution)
                                                            <option value="{{ $resolution }}">{{ CostLabels::of('reconciliation_resolution', $resolution) }}</option>
                                                        @endforeach
                                                    </select>
                                                    <input class="form-control form-control-sm" name="note" required maxlength="500" aria-label="{{ translate('note') }}" placeholder="{{ translate('what_was_checked_and_decided') }}">
                                                    <button class="btn btn-outline-primary btn-sm" type="submit">{{ translate('resolve') }}</button>
                                                </form>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7"><x-k.empty icon="check" :title="$isApproved ? translate('not_reconciled_yet') : translate('approve_the_invoice_first')" :text="$isApproved ? translate('not_reconciled_yet_help') : translate('approve_the_invoice_first_help')" /></td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </x-k.card>

                    <x-k.card :title="translate('estimates_in_the_period_that_no_line_charged')" :padded="false">
                        <div class="p-3 fs-12 text-muted">{{ translate('unbilled_estimates_help') }}</div>
                        <div class="k-table-wrap table-responsive">
                            <table class="k-table">
                                <thead><tr><th>{{ translate('date') }}</th><th>{{ translate('subject') }}</th><th>{{ translate('component') }}</th><th class="k-table__num">{{ translate('estimated') }}</th></tr></thead>
                                <tbody>
                                @forelse ($unbilled as $estimate)
                                    <tr>
                                        <td class="fs-12 k-num">{{ $estimate->period_date->toDateString() }}</td>
                                        <td><a href="{{ route('admin.operational-costs.entries.show', $estimate->id) }}">{{ CostLabels::of('entry_subject', $estimate->subject_type) }} #{{ $estimate->subject_id }}</a><div class="fs-10 text-muted text-break">{{ $estimate->external_reference }}</div></td>
                                        <td>{{ $estimate->component() ? translate($estimate->component()->labelKey()) : $estimate->cost_component }}</td>
                                        <td class="k-table__num k-num">{{ CostFormat::amount($estimate->amount, $estimate->currency) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="fs-12 text-muted">{{ translate('every_estimate_of_the_period_was_charged') }}</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                        @if ($unbilledCount > $unbilled->count())
                            <x-slot:footer><span class="fs-12 text-muted">{{ CostLabels::filled(translate('only_the_first_estimates_are_listed'), ['shown' => $unbilled->count(), 'total' => $unbilledCount]) }}</span></x-slot:footer>
                        @endif
                    </x-k.card>
                @endif
            </div>

            <div class="col-lg-4">
                <x-k.card :title="translate('what_can_be_done')">
                    @if ($canApprove)
                        <form method="POST" action="{{ route('admin.operational-costs.invoices.approve', $invoice->id) }}" class="mb-3">@csrf
                            <p class="fs-12 text-muted">{{ $isPurchase ? translate('approving_a_purchase_invoice_help') : translate('approving_a_service_invoice_help') }}</p>
                            <button class="btn btn-success btn-sm w-100" type="submit" @disabled($invoice->duplicate_of_id !== null)>{{ translate('approve_invoice') }}</button>
                        </form>
                    @endif

                    @if ($isApproved && !$isPurchase)
                        <form method="POST" action="{{ route('admin.operational-costs.invoices.reconcile', $invoice->id) }}" class="mb-3">@csrf
                            <p class="fs-12 text-muted">{{ translate('reconcile_help') }}</p>
                            <button class="btn btn-primary btn-sm w-100" type="submit">{{ translate('reconcile_with_the_estimates') }}</button>
                        </form>
                    @endif

                    @if ($canVoid)
                        <form method="POST" action="{{ route('admin.operational-costs.invoices.void', $invoice->id) }}">@csrf
                            <label class="form-label fs-12" for="void-reason">{{ translate('reason_for_voiding') }}</label>
                            <input class="form-control form-control-sm mb-1" id="void-reason" name="reason" required maxlength="500">
                            <button class="btn btn-outline-danger btn-sm w-100" type="submit">{{ translate('void_invoice') }}</button>
                        </form>
                    @endif

                    @if ($isApproved)
                        <p class="fs-12 text-muted mb-0">{{ translate('an_approved_invoice_is_never_edited_or_voided_note') }}</p>
                    @endif
                </x-k.card>
            </div>
        </div>
    </div>
@endsection
