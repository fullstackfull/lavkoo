@extends('layouts.admin.app')

@section('title', translate('brand_registry'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="fi fi-rr-shield-check"></i>
                {{ translate('brand_registry') }}
            </h2>
            <p class="mb-0 fs-12">{{ translate('decide_who_may_sell_under_a_brand_and_on_what_evidence') }}.</p>
        </div>

        {{-- The one switch that changes what every seller can do. Kept apart from the queue, and off
             until somebody arms it with the affected counts in front of them. --}}
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">{{ translate('enforcement') }}</h5></div>
            <div class="card-body">
                <form action="{{ route('admin.marketplace.brand-registry.enforcement') }}" method="post" class="row g-3 align-items-end">
                    @csrf
                    <div class="col-sm-9">
                        <label class="d-flex align-items-center gap-2 mb-0">
                            <input type="checkbox" name="enforce" value="1" {{ $enforcing ? 'checked' : '' }}>
                            {{ translate('refuse_listings_under_a_brand_the_seller_is_not_entitled_to_sell') }}
                        </label>
                        <small class="text-muted">
                            {{ translate('off_by_default_while_off_mismatches_are_reported_to_sellers_but_nothing_is_blocked') }}.
                            {{ translate('a_brand_nobody_has_claimed_stays_open_to_everybody') }}.
                        </small>
                    </div>
                    <div class="col-sm-3 d-flex justify-content-end">
                        <button class="btn btn-primary">{{ translate('save_settings') }}</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h5 class="mb-0">{{ translate('brand_claims') }}</h5>
                <form method="get" class="d-flex gap-2">
                    <select name="status" class="form-control form-control-sm" onchange="this.form.submit()">
                        <option value="">{{ translate('all_statuses') }}</option>
                        @foreach (['submitted','under_review','approved','rejected','revoked','draft'] as $status)
                            <option value="{{ $status }}" {{ $statusFilter === $status ? 'selected' : '' }}>{{ translate($status) }}</option>
                        @endforeach
                    </select>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                            <tr>
                                <th>{{ translate('seller') }}</th>
                                <th>{{ translate('brand') }}</th>
                                <th>{{ translate('claims_to_be') }}</th>
                                <th>{{ translate('evidence') }}</th>
                                <th>{{ translate('listings_affected') }}</th>
                                <th>{{ translate('status') }}</th>
                                <th class="text-end">{{ translate('decision') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($claims as $claim)
                                <tr>
                                    <td>{{ $sellers[$claim->seller_id]->f_name ?? '#' . $claim->seller_id }}</td>
                                    <td>{{ $claim->brand?->name ?? '#' . $claim->brand_id }}</td>
                                    <td>
                                        {{ translate('claim_type_' . $claim->claim_type) }}
                                        @if ($claim->statement)
                                            <div class="fs-12 text-muted">{{ \Illuminate\Support\Str::limit($claim->statement, 120) }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        @forelse ($claim->documents as $document)
                                            <div>
                                                <a href="{{ route('admin.marketplace.brand-registry.document', $document->id) }}" target="_blank">
                                                    {{ translate($document->document_type) }}
                                                </a>
                                                @if ($document->expires_at)
                                                    <span class="fs-12 text-muted">
                                                        ({{ translate('expires') }} {{ $document->expires_at->toDateString() }})
                                                    </span>
                                                @endif
                                            </div>
                                        @empty
                                            <span class="fs-12 text-muted">{{ translate('none') }}</span>
                                        @endforelse
                                    </td>
                                    <td>
                                        {{-- What approving this would affect, counted before the click. --}}
                                        <div>{{ translate('theirs') }}: {{ $exposure[$claim->id]['own'] ?? 0 }}</div>
                                        @if (($exposure[$claim->id]['others'] ?? 0) > 0)
                                            <div class="text-warning">
                                                {{ translate('other_sellers') }}: {{ $exposure[$claim->id]['others'] }}
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge badge-soft-secondary">{{ translate($claim->status) }}</span>
                                        @if ($claim->review_note)
                                            <div class="fs-12 text-muted">{{ \Illuminate\Support\Str::limit($claim->review_note, 80) }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if (in_array($claim->status, ['submitted', 'under_review'], true))
                                            <form action="{{ route('admin.marketplace.brand-registry.approve') }}" method="post" class="d-inline">
                                                @csrf
                                                <input type="hidden" name="claim_id" value="{{ $claim->id }}">
                                                <input type="date" name="expires_at" class="form-control form-control-sm d-inline-block w-auto"
                                                       title="{{ translate('when_this_authority_runs_out_optional') }}">
                                                <button class="btn btn-sm btn-success">{{ translate('approve') }}</button>
                                            </form>
                                            <form action="{{ route('admin.marketplace.brand-registry.reject') }}" method="post" class="d-inline">
                                                @csrf
                                                <input type="hidden" name="claim_id" value="{{ $claim->id }}">
                                                <button class="btn btn-sm btn-outline-danger">{{ translate('reject') }}</button>
                                            </form>
                                        @elseif ($claim->status === 'approved')
                                            <form action="{{ route('admin.marketplace.brand-registry.revoke') }}" method="post" class="d-inline">
                                                @csrf
                                                <input type="hidden" name="claim_id" value="{{ $claim->id }}">
                                                <button class="btn btn-sm btn-outline-danger">{{ translate('revoke') }}</button>
                                            </form>
                                        @else
                                            <span class="fs-12 text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        {{ translate('no_brand_claims_yet') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if ($claims instanceof \Illuminate\Contracts\Pagination\Paginator || $claims instanceof \Illuminate\Pagination\LengthAwarePaginator)
                <div class="card-footer">{{ $claims->links() }}</div>
            @endif
        </div>
    </div>
@endsection
