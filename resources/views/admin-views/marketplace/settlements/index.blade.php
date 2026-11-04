@extends('layouts.admin.app')

@section('title', translate('vendor_settlements'))

@php
    $statusColours = [
        'draft' => 'secondary', 'calculated' => 'info', 'under_review' => 'warning',
        'approved' => 'accent', 'paid' => 'success', 'cancelled' => 'danger',
    ];
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="fi fi-rr-coins"></i>
                {{ translate('vendor_settlements') }}
            </h2>
            <p class="mb-0 fs-12">{{ translate('every_figure_here_comes_from_the_vendor_ledger_and_nothing_is_paid_without_approval') }}</p>
        </div>

        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <h6 class="mb-1">{{ translate('separation_of_duties') }}</h6>
                    <p class="mb-0 fs-12 text-muted">{{ translate('when_on_the_admin_who_approves_a_settlement_cannot_also_mark_it_paid_off_by_default') }}.</p>
                </div>
                <form action="{{ route('admin.marketplace.settlements.toggle-maker-checker') }}" method="post" class="d-flex align-items-center gap-2">
                    @csrf
                    <input type="hidden" name="status" value="{{ $makerChecker ? 0 : 1 }}">
                    <span class="k-badge {{ $makerChecker ? 'k-badge--success' : '' }}">
                        {{ $makerChecker ? translate('enabled') : translate('disabled') }}
                    </span>
                    <button class="btn btn-sm {{ $makerChecker ? 'btn-outline-danger' : 'btn-primary' }}">
                        {{ $makerChecker ? translate('turn_off') : translate('turn_on') }}
                    </button>
                </form>
            </div>
        </div>

        {{-- What the marketplace promises its sellers about when they are paid. Every line here was
             a constant, a settings key nothing wrote, or a value with no home at all — so changing
             the terms was a deploy rather than a decision. --}}
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">{{ translate('payment_terms') }}</h5>
                <small class="text-muted">{{ translate('what_the_marketplace_promises_its_sellers_about_when_they_are_paid') }}.</small>
            </div>
            <div class="card-body">
                <form action="{{ route('admin.marketplace.settlements.terms') }}" method="post" class="row g-3">
                    @csrf
                    @foreach ($termFields as $key => $field)
                        <div class="col-md-4">
                            <label class="form-label fs-12 mb-1" for="{{ $key }}">{{ translate($field['label']) }}</label>
                            @include('admin-views.settings.partials._policy-field', ['key' => $key, 'field' => $field, 'value' => $terms[$key]])
                            @if (!empty($field['help']))
                                <small class="text-muted d-block mt-1">{{ translate($field['help']) }}.</small>
                            @endif
                        </div>
                    @endforeach
                    <div class="col-12 d-flex justify-content-end">
                        <button class="btn btn-primary px-4">{{ translate('save') }}</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- The one number that tells the operator whether Calculate will do anything. --}}
        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="d-flex flex-wrap gap-4">
                    <div>
                        <p class="mb-0 fs-12 text-muted">{{ translate('vendors_with_money_waiting') }}</p>
                        <h3 class="mb-0">{{ $waiting['vendors'] }}</h3>
                    </div>
                    <div>
                        <p class="mb-0 fs-12 text-muted">{{ translate('net_waiting_to_be_settled') }}</p>
                        <h3 class="mb-0">{{ setCurrencySymbol($waiting['net']) }}</h3>
                    </div>
                </div>
                <form action="{{ route('admin.marketplace.settlements.calculate') }}" method="post"
                      onsubmit="return confirm('{{ translate('calculate_settlements_for_all_vendors_with_money_waiting') }}?');">
                    @csrf
                    <button type="submit" class="btn btn-primary" {{ $waiting['vendors'] === 0 ? 'disabled' : '' }}>
                        <i class="fi fi-rr-calculator"></i>
                        {{ translate('calculate_settlements') }}
                    </button>
                </form>
            </div>
        </div>

        {{-- Status filter, with live counts so the operator sees where the queue is. --}}
        <div class="d-flex flex-wrap gap-2 mb-3">
            <a href="{{ route('admin.marketplace.settlements.index') }}"
               class="btn btn-sm {{ !$status ? 'btn-primary' : 'btn-outline-primary' }}">
                {{ translate('all') }}
            </a>
            @foreach ($statusColours as $key => $colour)
                <a href="{{ route('admin.marketplace.settlements.index', ['status' => $key]) }}"
                   class="btn btn-sm {{ $status === $key ? 'btn-' . $colour : 'btn-outline-' . $colour }}">
                    {{ translate($key) }}
                    <span class="k-badge ms-1">{{ $counts[$key] ?? 0 }}</span>
                </a>
            @endforeach
        </div>

        <div class="card">
            <div class="card-body">
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                            <tr>
                                <th>{{ translate('reference') }}</th>
                                <th>{{ translate('vendor') }}</th>
                                <th>{{ translate('period') }}</th>
                                <th class="text-end">{{ translate('net_amount') }}</th>
                                <th>{{ translate('entries') }}</th>
                                <th>{{ translate('status') }}</th>
                                <th class="text-end">{{ translate('action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($settlements as $settlement)
                                <tr>
                                    <td class="fw-medium">{{ $settlement->reference }}</td>
                                    <td>
                                        <a href="{{ route('admin.marketplace.ledger', $settlement->seller_id) }}">
                                            #{{ $settlement->seller_id }}
                                        </a>
                                    </td>
                                    <td class="fs-12">
                                        {{ optional($settlement->period_start)->format('d M') }}
                                        &ndash;
                                        {{ optional($settlement->period_end)->format('d M Y') }}
                                    </td>
                                    <td class="text-end fw-semibold">{{ setCurrencySymbol($settlement->net_amount) }}</td>
                                    <td>{{ $settlement->entry_count }}</td>
                                    <td>
                                        <span class="k-badge k-badge--{{ $statusColours[$settlement->status] ?? 'secondary' }}">
                                            {{ translate($settlement->status) }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('admin.marketplace.settlements.show', $settlement->id) }}"
                                           class="btn btn-sm btn-outline-primary">{{ translate('view') }}</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        {{ translate('no_settlements_yet') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {!! $settlements->links() !!}
            </div>
        </div>
    </div>
@endsection
