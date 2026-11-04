@extends('layouts.admin.app')

@section('title', translate('vendor_ledger') . ' #' . $sellerId)

@php
    $entryColours = [
        'order_earning' => 'success', 'commission_charge' => 'warning', 'refund' => 'danger',
        'return_adjustment' => 'info', 'payout' => 'info', 'penalty' => 'danger',
        'bonus' => 'success', 'manual_adjustment' => 'secondary',
    ];
    $statusColours = ['pending' => 'warning', 'available' => 'success', 'reserved' => 'info', 'paid' => 'accent'];
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="fi fi-rr-book"></i>
                {{ translate('vendor_ledger') }} #{{ $sellerId }}
            </h2>
            <p class="mb-0 fs-12">{{ translate('every_financial_event_for_this_vendor_as_an_immutable_entry') }}</p>
        </div>

        {{-- The four buckets, kept genuinely apart. "Available" is what the vendor can actually be
             paid; "pending" is still inside the return window. --}}
        <div class="row g-3 mb-3">
            @foreach ([
                ['pending', translate('pending'), 'warning'],
                ['available', translate('available'), 'success'],
                ['reserved', translate('reserved'), 'info'],
                ['paid', translate('paid'), 'dark'],
                ['balance', translate('total_balance'), 'primary'],
            ] as [$key, $label, $colour])
                <div class="col-6 col-lg">
                    <div class="card h-100 border-{{ $colour }}">
                        <div class="card-body">
                            <p class="mb-1 fs-12 text-muted">{{ $label }}</p>
                            <h4 class="mb-0">{{ setCurrencySymbol($balances[$key]) }}</h4>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card">
            <div class="card-body">
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>{{ translate('date') }}</th>
                                <th>{{ translate('type') }}</th>
                                <th>{{ translate('reference') }}</th>
                                <th class="text-end">{{ translate('credit') }}</th>
                                <th class="text-end">{{ translate('debit') }}</th>
                                <th class="text-end">{{ translate('balance_after') }}</th>
                                <th>{{ translate('status') }}</th>
                                <th>{{ translate('settlement') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($entries as $entry)
                                <tr>
                                    <td>{{ $entry->id }}</td>
                                    <td class="fs-12">{{ $entry->created_at?->format('d M Y H:i') }}</td>
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
                                    <td class="text-end fw-medium">{{ setCurrencySymbol($entry->balance_after) }}</td>
                                    <td>
                                        <span class="k-badge k-badge--{{ $statusColours[$entry->status] ?? 'secondary' }}">
                                            {{ translate($entry->status) }}
                                        </span>
                                    </td>
                                    <td class="fs-12">
                                        @if ($entry->settlement_id)
                                            <a href="{{ route('admin.marketplace.settlements.show', $entry->settlement_id) }}">
                                                #{{ $entry->settlement_id }}
                                            </a>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">{{ translate('this_vendor_has_no_ledger_entries_yet') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {!! $entries->links() !!}
            </div>
        </div>

        <a href="{{ route('admin.marketplace.settlements.index') }}" class="btn btn-outline-secondary mt-3">
            <i class="fi fi-rr-arrow-small-left"></i> {{ translate('back_to_settlements') }}
        </a>
    </div>
@endsection
