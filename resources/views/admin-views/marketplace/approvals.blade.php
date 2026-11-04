@extends('layouts.admin.app')

@section('title', translate('approvals'))

@php
    $statusColours = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary'];
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="fi fi-rr-shield-check"></i>
                {{ translate('approvals') }}
            </h2>
            <p class="mb-0 fs-12">{{ translate('maker_checker_queue_the_approver_is_never_the_requester') }}</p>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-sm-4">
                        <label class="form-label fs-12" for="workflow">{{ translate('workflow') }}</label>
                        <select class="form-control" id="workflow" name="workflow" onchange="this.form.submit()">
                            <option value="">{{ translate('all') }}</option>
                            @foreach ($workflows as $workflow)
                                <option value="{{ $workflow }}" {{ ($filters['workflow'] ?? '') === $workflow ? 'selected' : '' }}>
                                    {{ translate($workflow) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label fs-12" for="status">{{ translate('status') }}</label>
                        <select class="form-control" id="status" name="status" onchange="this.form.submit()">
                            <option value="pending" {{ ($filters['status'] ?? 'pending') === 'pending' ? 'selected' : '' }}>{{ translate('pending') }}</option>
                            <option value="approved" {{ ($filters['status'] ?? '') === 'approved' ? 'selected' : '' }}>{{ translate('approved') }}</option>
                            <option value="rejected" {{ ($filters['status'] ?? '') === 'rejected' ? 'selected' : '' }}>{{ translate('rejected') }}</option>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                            <tr>
                                <th>{{ translate('reference') }}</th>
                                <th>{{ translate('workflow') }}</th>
                                <th>{{ translate('subject') }}</th>
                                <th class="text-end">{{ translate('amount') }}</th>
                                <th>{{ translate('approvals') }}</th>
                                <th>{{ translate('status') }}</th>
                                <th class="text-end">{{ translate('action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($requests as $req)
                                <tr>
                                    <td class="fw-medium fs-12">{{ $req->reference }}</td>
                                    <td class="fs-12">{{ translate($req->workflow) }}</td>
                                    <td class="fs-12">
                                        @if ($req->subject_type)
                                            {{ class_basename($req->subject_type) }} #{{ $req->subject_id }}
                                        @else <span class="text-muted">—</span> @endif
                                    </td>
                                    <td class="text-end fs-12">{{ !is_null($req->amount) ? setCurrencySymbol($req->amount) : '—' }}</td>
                                    <td class="fs-12">{{ $req->approvals_count }} / {{ $req->required_approvals }}</td>
                                    <td>
                                        <span class="k-badge k-badge--{{ $statusColours[$req->status] ?? 'secondary' }}">
                                            {{ translate($req->status) }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        @if ($req->isPending())
                                            <div class="d-inline-flex gap-1">
                                                <form action="{{ route('admin.marketplace.approvals.approve', $req->id) }}" method="post">
                                                    @csrf
                                                    <button class="btn btn-sm btn-success">{{ translate('approve') }}</button>
                                                </form>
                                                <form action="{{ route('admin.marketplace.approvals.reject', $req->id) }}" method="post"
                                                      onsubmit="return confirm('{{ translate('reject_this_request') }}?');">
                                                    @csrf
                                                    <button class="btn btn-sm btn-outline-danger">{{ translate('reject') }}</button>
                                                </form>
                                            </div>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-4">{{ translate('no_requests_in_this_view') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if (method_exists($requests, 'links')) {!! $requests->links() !!} @endif
            </div>
        </div>
    </div>
@endsection
