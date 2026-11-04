@extends('layouts.admin.app')

@section('title', translate('settlement') . ' ' . $settlement->reference)

@php
    $statusColours = [
        'draft' => 'secondary', 'calculated' => 'info', 'under_review' => 'warning',
        'approved' => 'accent', 'paid' => 'success', 'cancelled' => 'danger',
    ];
    $entryColours = [
        'order_earning' => 'success', 'commission_charge' => 'warning', 'refund' => 'danger',
        'return_adjustment' => 'info', 'payout' => 'accent', 'penalty' => 'danger', 'bonus' => 'success',
    ];
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
                <h2 class="h1 mb-0">{{ $settlement->reference }}</h2>
                <p class="mb-0 fs-12">
                    {{ translate('vendor') }} #{{ $settlement->seller_id }} &middot;
                    {{ optional($settlement->period_start)->format('d M Y') }}
                    &ndash;
                    {{ optional($settlement->period_end)->format('d M Y') }}
                </p>
            </div>
            <span class="k-badge k-badge--{{ $statusColours[$settlement->status] ?? 'secondary' }} fs-14">
                {{ translate($settlement->status) }}
            </span>
        </div>

        {{-- The statement reads the way an accountant expects: opening, movements, closing. --}}
        <div class="row g-3 mb-3">
            @foreach ([
                ['opening_balance', translate('opening_balance'), 'secondary'],
                ['total_credits', translate('credits'), 'success'],
                ['total_debits', translate('debits'), 'warning'],
                ['net_amount', translate('net_this_period'), 'primary'],
                ['closing_balance', translate('closing_balance'), 'dark'],
            ] as [$field, $label, $colour])
                <div class="col-6 col-lg">
                    <div class="card h-100 border-{{ $colour }}">
                        <div class="card-body">
                            <p class="mb-1 fs-12 text-muted">{{ $label }}</p>
                            <h4 class="mb-0">{{ setCurrencySymbol($settlement->$field) }}</h4>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- The two actions that move money. Approve, then pay — maker-checker, enforced by the
             engine, so a disabled button here is a courtesy and the rule is on the server. --}}
        @if (!in_array($settlement->status, ['paid', 'cancelled']))
            <div class="card mb-3">
                <div class="card-body d-flex flex-wrap gap-2">
                    @if ($settlement->status !== 'approved')
                        <form action="{{ route('admin.marketplace.settlements.approve', $settlement->id) }}" method="post"
                              onsubmit="return confirm('{{ translate('approve_this_settlement') }}?');">
                            @csrf
                            <button class="btn btn-primary"><i class="fi fi-rr-check"></i> {{ translate('approve') }}</button>
                        </form>
                        <form action="{{ route('admin.marketplace.settlements.cancel', $settlement->id) }}" method="post"
                              onsubmit="return confirm('{{ translate('cancel_and_release_its_entries') }}?');">
                            @csrf
                            <button class="btn btn-outline-danger">{{ translate('cancel') }}</button>
                        </form>
                    @else
                        <form action="{{ route('admin.marketplace.settlements.mark-paid', $settlement->id) }}" method="post"
                              class="d-flex flex-wrap gap-2 align-items-center"
                              onsubmit="return confirm('{{ translate('mark_this_settlement_paid') }}?');">
                            @csrf
                            <input type="text" name="payout_reference" class="form-control" style="max-width: 260px;"
                                   placeholder="{{ translate('payout_reference_bank_transfer_id') }}">
                            <button class="btn btn-success"><i class="fi fi-rr-coins"></i> {{ translate('mark_paid') }}</button>
                        </form>
                    @endif
                </div>
                @if ($settlement->approved_at)
                    <div class="card-footer fs-12 text-muted">
                        {{ translate('approved_by') }} #{{ $settlement->approved_by }}
                        {{ translate('on') }} {{ $settlement->approved_at->format('d M Y H:i') }}
                        @if ($settlement->paid_at)
                            &middot; {{ translate('paid') }} {{ $settlement->paid_at->format('d M Y H:i') }}
                            @if ($settlement->payout_reference) ({{ $settlement->payout_reference }}) @endif
                        @endif
                    </div>
                @endif
            </div>
        @endif

        {{-- Every ledger entry this settlement claimed. This is the audit trail: the settlement is
             not a number, it is exactly these rows. --}}
        <div class="card">
            <div class="card-header"><h5 class="mb-0">{{ translate('claimed_ledger_entries') }} ({{ $settlement->entry_count }})</h5></div>
            <div class="card-body">
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>{{ translate('type') }}</th>
                                <th>{{ translate('reference') }}</th>
                                <th class="text-end">{{ translate('credit') }}</th>
                                <th class="text-end">{{ translate('debit') }}</th>
                                <th class="text-end">{{ translate('balance_after') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($entries as $entry)
                                <tr>
                                    <td>{{ $entry->id }}</td>
                                    <td>
                                        <span class="k-badge k-badge--{{ $entryColours[$entry->entry_type] ?? 'secondary' }}">
                                            {{ translate($entry->entry_type) }}
                                        </span>
                                    </td>
                                    <td class="fs-12">
                                        {{ $entry->reference_type }} #{{ $entry->reference_id }}
                                        @if ($entry->description)<br><span class="text-muted">{{ $entry->description }}</span>@endif
                                    </td>
                                    <td class="text-end text-success">{{ $entry->credit > 0 ? setCurrencySymbol($entry->credit) : '—' }}</td>
                                    <td class="text-end text-danger">{{ $entry->debit > 0 ? setCurrencySymbol($entry->debit) : '—' }}</td>
                                    <td class="text-end">{{ setCurrencySymbol($entry->balance_after) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <a href="{{ route('admin.marketplace.settlements.index') }}" class="btn btn-outline-secondary mt-3">
            <i class="fi fi-rr-arrow-small-left"></i> {{ translate('back_to_settlements') }}
        </a>
    </div>
@endsection
