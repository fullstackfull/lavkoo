@extends('layouts.admin.app')

@section('title', translate('reconciliation'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h2 class="h1 mb-0 d-flex align-items-center gap-2"><i class="fi fi-rr-balance-scale-right"></i> {{ translate('financial_reconciliation') }}</h2>
                <p class="mb-0 fs-12">{{ translate('integrity_checks_over_the_ledger_commissions_and_settlements') }}.</p>
            </div>
            <span class="k-badge k-badge--{{ $clean ? 'success' : 'danger' }} fs-14 px-3 py-2">
                {{ $clean ? translate('all_reconciled') : translate('discrepancies_found') }}
            </span>
        </div>

        @foreach ($results as $r)
            @php($ok = empty($r['discrepancies']))
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <span class="k-badge k-badge--{{ $ok ? 'success' : 'danger' }} me-2">{{ $ok ? '✓' : '!' }}</span>
                        <strong>{{ translate($r['label']) }}</strong>
                        <span class="fs-11 text-muted">— {{ translate($r['scope']) }}</span>
                    </div>
                    <span class="fs-12 text-muted">{{ $r['checked'] }} {{ translate('checked') }}</span>
                </div>
                @unless ($ok)
                    <div class="card-body p-0">
                        <div class="k-table-wrap">
                            <table class="k-table">
                                <thead><tr>
                                    <th>{{ translate('reference') }}</th>
                                    <th class="text-end">{{ translate('expected') }}</th>
                                    <th class="text-end">{{ translate('actual') }}</th>
                                    <th class="text-end">{{ translate('delta') }}</th>
                                </tr></thead>
                                <tbody>
                                @foreach ($r['discrepancies'] as $d)
                                    <tr>
                                        <td>{{ $d['ref'] }}</td>
                                        <td class="text-end">{{ $d['expected'] }}</td>
                                        <td class="text-end">{{ $d['actual'] }}</td>
                                        <td class="text-end text-danger fw-bold">{{ $d['delta'] > 0 ? '+' : '' }}{{ $d['delta'] }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @else
                    <div class="card-body py-2"><span class="fs-12 text-success">{{ translate('no_discrepancies') }}</span></div>
                @endunless
            </div>
        @endforeach

        <p class="fs-12 text-muted">{{ translate('this_report_is_read_only_and_computes_live_it_writes_nothing') }}.</p>
    </div>
@endsection
