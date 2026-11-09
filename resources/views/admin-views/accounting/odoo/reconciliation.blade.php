@extends('layouts.admin.app')

@section('title', translate('Odoo_Three_Way_Reconciliation'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('Odoo_Three_Way_Reconciliation')" :subtitle="translate('odoo_reconciliation_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.accounting.odoo.index') }}">{{ translate('Odoo_Accounting') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.accounting.odoo.outbox.index') }}">{{ translate('Odoo_Posting_Outbox') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        <x-k.card :title="translate('odoo_build_a_period')">
            <p class="text-muted small">{{ translate('odoo_build_a_period_help') }}</p>
            <form method="POST" action="{{ route('admin.accounting.odoo.reconciliation.build') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-3">
                    <x-k.field :label="translate('from')" name="from" required>
                        <input class="form-control" type="date" id="from" name="from" required value="{{ $from->toDateString() }}">
                    </x-k.field>
                </div>
                <div class="col-md-3">
                    <x-k.field :label="translate('to')" name="to" required>
                        <input class="form-control" type="date" id="to" name="to" required value="{{ $to->toDateString() }}">
                    </x-k.field>
                </div>
                <div class="col-md-2">
                    <x-k.field :label="translate('currency')" name="currency" required>
                        <input class="form-control" type="text" id="currency" name="currency" maxlength="3" required value="{{ $currency }}">
                    </x-k.field>
                </div>
                <div class="col-md-2">
                    <x-k.field :label="translate('odoo_narrow_to_channel')" name="channel" :hint="translate('odoo_leave_empty_for_every_channel')">
                        <input class="form-control" type="text" id="channel" name="channel" maxlength="24">
                    </x-k.field>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary btn-sm" type="submit" @disabled($connection === null)>{{ translate('odoo_build') }}</button>
                </div>
            </form>
        </x-k.card>

        <x-k.card class="mt-3" :padded="false">
            @if ($rows->isEmpty())
                <div class="p-3">
                    <x-k.empty icon="scale" :title="translate('odoo_no_reconciliation_for_this_period')" :text="translate('odoo_no_reconciliation_for_this_period_help')" />
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ translate('odoo_our_fact') }}</th>
                                <th class="text-end">{{ translate('odoo_leg_source') }}</th>
                                <th class="text-end">{{ translate('odoo_leg_ledger') }}</th>
                                <th class="text-end">{{ translate('odoo_leg_odoo') }}</th>
                                <th class="text-end">{{ translate('odoo_difference_source') }}</th>
                                <th class="text-end">{{ translate('odoo_difference_odoo') }}</th>
                                <th>{{ translate('odoo_not_posted') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr @class(['table-warning' => !$row->agrees()])>
                                    <td class="small">{{ translate('cost_component_' . $row->fact_kind) }}</td>
                                    {{-- A leg with no figure says so. Printing 0.00 would turn "nobody said" into "it was nothing". --}}
                                    <td class="text-end small">
                                        @if ($row->source_amount === null)
                                            <span class="text-muted">{{ translate('odoo_no_figure_published') }}</span>
                                        @else
                                            {{ $row->source_amount }}
                                        @endif
                                    </td>
                                    <td class="text-end small">
                                        @if ($row->ledger_amount === null)
                                            <span class="text-muted">{{ translate('odoo_nothing_settled') }}</span>
                                        @else
                                            {{ $row->ledger_amount }}
                                        @endif
                                    </td>
                                    <td class="text-end small">
                                        @if ($row->odoo_amount === null)
                                            <span class="text-muted">{{ translate('odoo_not_read') }}</span>
                                        @else
                                            {{ $row->odoo_amount }}
                                        @endif
                                    </td>
                                    <td class="text-end small">{{ $row->sourceDifference() ?? '—' }}</td>
                                    <td class="text-end small">{{ $row->odooDifference() ?? '—' }}</td>
                                    <td class="small">{{ $row->entries_not_posted > 0 ? $row->entries_not_posted : '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-k.card>

        <p class="small text-muted mt-3">{{ translate('odoo_reconciliation_closes_nothing') }}</p>
    </div>
@endsection
