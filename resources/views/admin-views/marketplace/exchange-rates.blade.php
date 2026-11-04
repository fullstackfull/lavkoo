@extends('layouts.admin.app')

@section('title', translate('exchange_rates'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2"><i class="fi fi-rr-exchange"></i> {{ translate('exchange_rates') }}</h2>
            <p class="mb-0 fs-12">{{ translate('update_rates_together_and_keep_an_audited_history_conversion_itself_is_unchanged') }}.</p>
        </div>

        <div class="row">
            <div class="col-lg-5">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">{{ translate('rates') }}</h5></div>
                    <div class="card-body">
                        <form action="{{ route('admin.marketplace.exchange-rates.bulk-update') }}" method="post">
                            @csrf
                            <table class="k-table">
                                <thead><tr><th>{{ translate('currency') }}</th><th style="width:160px">{{ translate('rate') }}</th></tr></thead>
                                <tbody>
                                @forelse ($currencies as $c)
                                    <tr>
                                        <td>{{ $c->code }} <span class="fs-10 text-muted">{{ $c->symbol }}</span></td>
                                        <td><input type="number" step="0.000001" min="0" name="rates[{{ $c->id }}]" value="{{ $c->exchange_rate }}" class="form-control form-control-sm"></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="2" class="text-center text-muted py-3">{{ translate('no_currencies') }}</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                            <div class="d-flex justify-content-end">
                                <button class="btn btn-primary btn-sm">{{ translate('update_rates') }}</button>
                            </div>
                            <small class="text-muted d-block mt-2">{{ translate('rates_are_relative_to_the_base_currency_only_changed_values_are_recorded') }}.</small>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ translate('change_history') }}</h5></div>
                    <div class="card-body p-0">
                        <div class="k-table-wrap">
                            <table class="k-table">
                                <thead><tr>
                                    <th>{{ translate('date') }}</th><th>{{ translate('currency') }}</th>
                                    <th class="text-end">{{ translate('from') }}</th><th class="text-end">{{ translate('to') }}</th>
                                    <th>{{ translate('source') }}</th>
                                </tr></thead>
                                <tbody>
                                @forelse ($history as $h)
                                    <tr>
                                        <td class="fs-12">{{ $h->created_at?->format('Y-m-d H:i') }}</td>
                                        <td>{{ $h->code ?: ($currencyNames[$h->currency_id] ?? ('#' . $h->currency_id)) }}</td>
                                        <td class="text-end text-muted">{{ $h->old_rate !== null ? rtrim(rtrim(number_format((float) $h->old_rate, 6), '0'), '.') : '—' }}</td>
                                        <td class="text-end fw-bold">{{ rtrim(rtrim(number_format((float) $h->new_rate, 6), '0'), '.') }}</td>
                                        <td><span class="k-badge">{{ translate($h->source) }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-4">{{ translate('no_rate_changes_recorded_yet') }}</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
