@extends('layouts.admin.app')

@section('title', translate('seller_verification'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="fi fi-rr-shield-check"></i>
                {{ translate('seller_verification') }} <span class="fs-12 text-muted">(KYC)</span>
            </h2>
            <p class="mb-0 fs-12">{{ translate('review_seller_identity_and_business_documents') }}.</p>
        </div>

        {{-- The two settings that actually change behaviour: the payout gate and the required set. --}}
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">{{ translate('kyc_settings') }}</h5></div>
            <div class="card-body">
                <form action="{{ route('admin.marketplace.seller-verification.settings') }}" method="post" class="row g-3 align-items-end">
                    @csrf
                    <div class="col-sm-4">
                        <label class="d-flex align-items-center gap-2 mb-0">
                            <input type="checkbox" name="require_kyc_for_payout" value="1" {{ $kycRequiredForPayout ? 'checked' : '' }}>
                            {{ translate('require_kyc_verification_before_payout') }}
                        </label>
                        <small class="text-muted">{{ translate('off_by_default_turning_this_on_blocks_unverified_sellers_from_withdrawing') }}.</small>
                    </div>
                    <div class="col-sm-5">
                        <label class="form-label fs-12" for="required-docs">{{ translate('required_documents_comma_separated') }}</label>
                        <input type="text" class="form-control" id="required-docs" name="kyc_required_documents"
                               value="{{ implode(', ', $requiredDocuments) }}" placeholder="identity, business_license">
                    </div>
                    <div class="col-sm-3 d-flex justify-content-end">
                        <button class="btn btn-primary">{{ translate('save_settings') }}</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h5 class="mb-0">{{ translate('submitted_documents') }}</h5>
                <form method="get" class="d-flex gap-2">
                    <select name="status" class="form-control form-control-sm" onchange="this.form.submit()">
                        <option value="">{{ translate('all_statuses') }}</option>
                        @foreach (['pending','approved','rejected','expired'] as $s)
                            <option value="{{ $s }}" {{ $statusFilter === $s ? 'selected' : '' }}>{{ translate($s) }}</option>
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
                                <th>{{ translate('document') }}</th>
                                <th>{{ translate('number') }}</th>
                                <th>{{ translate('file') }}</th>
                                <th>{{ translate('status') }}</th>
                                <th>{{ translate('overall') }}</th>
                                <th class="text-end">{{ translate('action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($documents as $doc)
                            @php($seller = $sellers[$doc->seller_id] ?? null)
                            <tr>
                                <td>{{ $seller ? trim(($seller->f_name ?? '') . ' ' . ($seller->l_name ?? '')) : ('#' . $doc->seller_id) }}</td>
                                <td>{{ translate($doc->document_type) }}</td>
                                <td>{{ $doc->document_number ?: '—' }}</td>
                                <td>
                                    @if ($doc->file_path)
                                        <a href="{{ route('admin.marketplace.seller-verification.document', $doc->id) }}" target="_blank" rel="noopener">
                                            {{ translate('view') }}
                                        </a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @php($map = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'expired' => 'secondary'])
                                    <span class="k-badge k-badge--{{ $map[$doc->status] ?? 'secondary' }}">{{ translate($doc->status) }}</span>
                                    @if ($doc->status === 'rejected' && $doc->rejection_reason)
                                        <div class="fs-10 text-danger">{{ $doc->rejection_reason }}</div>
                                    @endif
                                    @if ($doc->status === 'approved' && $doc->expires_at)
                                        <div class="fs-10 text-muted">{{ translate('expires') }}: {{ $doc->expires_at->toDateString() }}</div>
                                    @endif
                                </td>
                                <td>
                                    @php($ov = $standing[$doc->seller_id] ?? 'unverified')
                                    @php($ovMap = ['verified' => 'success', 'pending' => 'warning', 'rejected' => 'danger', 'unverified' => 'secondary', 'not_required' => 'info'])
                                    <span class="k-badge k-badge--{{ $ovMap[$ov] ?? 'secondary' }}">{{ translate($ov) }}</span>
                                </td>
                                <td class="text-end">
                                    @if (in_array($doc->status, ['pending','rejected','expired']))
                                        <form action="{{ route('admin.marketplace.seller-verification.approve') }}" method="post" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="document_id" value="{{ $doc->id }}">
                                            <input type="date" name="expires_at" class="form-control form-control-sm d-inline-block" style="width:150px" title="{{ translate('optional_expiry') }}">
                                            <button class="btn btn-sm btn-success">{{ translate('approve') }}</button>
                                        </form>
                                    @endif
                                    @if ($doc->status !== 'rejected')
                                        <button class="btn btn-sm btn-outline-danger" type="button" data-toggle="collapse" data-target="#reject-{{ $doc->id }}">{{ translate('reject') }}</button>
                                    @endif
                                    <div class="collapse mt-1" id="reject-{{ $doc->id }}">
                                        <form action="{{ route('admin.marketplace.seller-verification.reject') }}" method="post">
                                            @csrf
                                            <input type="hidden" name="document_id" value="{{ $doc->id }}">
                                            <div class="input-group input-group-sm">
                                                <input type="text" name="rejection_reason" class="form-control" placeholder="{{ translate('reason') }}" required maxlength="512">
                                                <button class="btn btn-danger">{{ translate('confirm') }}</button>
                                            </div>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">{{ translate('no_documents_submitted_yet') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if ($documents instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $documents->hasPages())
                <div class="card-footer">{{ $documents->links() }}</div>
            @endif
        </div>
    </div>
@endsection
