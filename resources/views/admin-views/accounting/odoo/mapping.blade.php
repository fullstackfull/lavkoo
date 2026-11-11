@extends('layouts.admin.app')

@section('title', translate('Odoo_Account_Mapping'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('Odoo_Account_Mapping')" :subtitle="translate('odoo_mapping_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.accounting.odoo.index') }}">{{ translate('Odoo_Accounting') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.accounting.odoo.outbox.index') }}">{{ translate('Odoo_Posting_Outbox') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        <x-k.card :title="translate('odoo_the_chart_as_read')">
            <p class="text-muted small">{{ translate('odoo_the_chart_as_read_help') }}</p>
            <div class="k-row">
                <form method="POST" action="{{ route('admin.accounting.odoo.mapping.read') }}">
                    @csrf
                    <button class="btn btn-outline-primary btn-sm" type="submit" @disabled($connection === null)>{{ translate('odoo_read_the_chart') }}</button>
                </form>
                <span class="small text-muted">
                    @if ($chart['read_at'])
                        {{ translate('odoo_chart_read_at') }}: {{ $chart['read_at'] }} ·
                        {{ count($chart['accounts']) }} {{ translate('odoo_accounts') }} ·
                        {{ count($chart['journals']) }} {{ translate('odoo_journals') }} ·
                        {{ count($chart['taxes']) }} {{ translate('odoo_taxes') }}
                    @else
                        {{ translate('odoo_chart_not_read_yet') }}
                    @endif
                </span>
            </div>
            @if ($companyId < 1)
                <p class="small text-warning mb-0 mt-2">{{ translate('odoo_choose_a_company_first_help') }}</p>
            @endif
        </x-k.card>

        <x-k.card :title="translate('odoo_what_each_fact_means')" class="mt-3">
            <p class="text-muted small">{{ translate('odoo_what_each_fact_means_help') }}</p>

            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>{{ translate('odoo_our_fact') }}</th>
                            <th>{{ translate('odoo_account') }}</th>
                            <th>{{ translate('odoo_journal') }}</th>
                            <th>{{ translate('odoo_narrowed_to') }}</th>
                            <th>{{ translate('status') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($facts as $fact)
                            @php $rows = $mappings[$fact['kind'] . ':' . $fact['code']] ?? collect(); @endphp
                            <tr>
                                <td>
                                    <div>{{ $fact['label'] }}</div>
                                    <code class="small text-muted">{{ translate('odoo_fact_kind_' . $fact['kind']) }} · {{ $fact['code'] }}</code>
                                </td>
                                <td colspan="5">
                                    @if ($rows->isEmpty())
                                        <span class="small text-muted">{{ translate('odoo_not_mapped_nothing_posts') }}</span>
                                    @endif
                                </td>
                            </tr>
                            @foreach ($rows as $row)
                                <tr>
                                    <td></td>
                                    <td class="small">{{ $row->odoo_account_code ?: $row->odoo_account_id }}</td>
                                    <td class="small">{{ $row->odoo_journal_code ?: $row->odoo_journal_id }}</td>
                                    <td class="small">
                                        {{ $row->sales_channel === '' ? translate('odoo_every_channel') : $row->sales_channel }} ·
                                        {{ $row->currency === '' ? translate('odoo_every_currency') : $row->currency }}
                                    </td>
                                    <td>
                                        <x-k.badge :tone="$row->isApproved() ? 'success' : 'warning'">
                                            {{ translate($row->isApproved() ? 'odoo_approved' : 'odoo_awaiting_approval') }}
                                        </x-k.badge>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline"
                                              action="{{ route($row->isApproved() ? 'admin.accounting.odoo.mapping.unapprove' : 'admin.accounting.odoo.mapping.approve', $row->id) }}">
                                            @csrf
                                            <button class="btn btn-outline-secondary btn-sm" type="submit">
                                                {{ translate($row->isApproved() ? 'odoo_withdraw_approval' : 'approve') }}
                                            </button>
                                        </form>
                                        <form method="POST" class="d-inline" action="{{ route('admin.accounting.odoo.mapping.destroy', $row->id) }}">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-outline-danger btn-sm" type="submit">{{ translate('remove') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-k.card>

        @if ($chart['accounts'] !== [])
            <x-k.card :title="translate('odoo_map_a_fact')" class="mt-3">
                <form method="POST" action="{{ route('admin.accounting.odoo.mapping.store') }}" class="row g-2">
                    @csrf
                    <div class="col-md-4">
                        <x-k.field :label="translate('odoo_our_fact')" name="fact_code" required>
                            <select class="form-control" id="fact_code" name="fact_code" required>
                                @foreach ($facts as $fact)
                                    <option value="{{ $fact['code'] }}" data-kind="{{ $fact['kind'] }}">{{ $fact['label'] }}</option>
                                @endforeach
                            </select>
                        </x-k.field>
                    </div>
                    <div class="col-md-2">
                        <x-k.field :label="translate('odoo_fact_kind')" name="fact_kind" required>
                            <select class="form-control" id="fact_kind" name="fact_kind" required>
                                <option value="cost_component">{{ translate('odoo_fact_kind_cost_component') }}</option>
                                <option value="counterpart">{{ translate('odoo_fact_kind_counterpart') }}</option>
                            </select>
                        </x-k.field>
                    </div>
                    <div class="col-md-3">
                        <x-k.field :label="translate('odoo_account')" name="odoo_account_id" required>
                            <select class="form-control" id="odoo_account_id" name="odoo_account_id" required>
                                @foreach ($chart['accounts'] as $account)
                                    <option value="{{ $account['id'] }}">{{ ($account['code'] ?? '') . ' — ' . ($account['name'] ?? '') }}</option>
                                @endforeach
                            </select>
                        </x-k.field>
                    </div>
                    <div class="col-md-3">
                        <x-k.field :label="translate('odoo_journal')" name="odoo_journal_id" required>
                            <select class="form-control" id="odoo_journal_id" name="odoo_journal_id" required>
                                @foreach ($chart['journals'] as $journal)
                                    <option value="{{ $journal['id'] }}">{{ ($journal['code'] ?? '') . ' — ' . ($journal['name'] ?? '') }}</option>
                                @endforeach
                            </select>
                        </x-k.field>
                    </div>
                    <div class="col-md-3">
                        <x-k.field :label="translate('odoo_tax')" name="odoo_tax_id" :hint="translate('odoo_optional')">
                            <select class="form-control" id="odoo_tax_id" name="odoo_tax_id">
                                <option value="">{{ translate('none') }}</option>
                                @foreach ($chart['taxes'] as $tax)
                                    <option value="{{ $tax['id'] }}">{{ $tax['name'] ?? '' }}</option>
                                @endforeach
                            </select>
                        </x-k.field>
                    </div>
                    <div class="col-md-3">
                        <x-k.field :label="translate('odoo_cost_centre')" name="odoo_analytic_account_id" :hint="translate('odoo_optional')">
                            <select class="form-control" id="odoo_analytic_account_id" name="odoo_analytic_account_id">
                                <option value="">{{ translate('none') }}</option>
                                @foreach ($chart['analytic'] as $analytic)
                                    <option value="{{ $analytic['id'] }}">{{ $analytic['name'] ?? '' }}</option>
                                @endforeach
                            </select>
                        </x-k.field>
                    </div>
                    <div class="col-md-3">
                        <x-k.field :label="translate('odoo_narrow_to_channel')" name="sales_channel" :hint="translate('odoo_leave_empty_for_every_channel')">
                            <input class="form-control" type="text" id="sales_channel" name="sales_channel" maxlength="24">
                        </x-k.field>
                    </div>
                    <div class="col-md-3">
                        <x-k.field :label="translate('odoo_narrow_to_currency')" name="currency" :hint="translate('odoo_leave_empty_for_every_currency')">
                            <input class="form-control" type="text" id="currency" name="currency" maxlength="8">
                        </x-k.field>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary btn-sm" type="submit">{{ translate('save') }}</button>
                        <span class="small text-muted ms-2">{{ translate('odoo_saving_is_not_approving') }}</span>
                    </div>
                </form>
            </x-k.card>
        @endif
    </div>
@endsection
