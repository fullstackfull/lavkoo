@extends('layouts.vendor.app')

@section('title', translate('verification'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0">{{ translate('account_verification') }} <span class="fs-12 text-muted">(KYC)</span></h2>
            <p class="mb-0 fs-12">{{ translate('submit_your_identity_and_business_documents_for_review') }}.</p>
        </div>

        @php($ovMap = ['verified' => 'success', 'pending' => 'warning', 'rejected' => 'danger', 'unverified' => 'secondary', 'not_required' => 'info'])
        <div class="alert alert-{{ $ovMap[$overallStatus] ?? 'secondary' }} d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span>
                {{ translate('verification_status') }}:
                <strong>{{ translate($overallStatus) }}</strong>
            </span>
            @if ($kycRequiredForPayout && $overallStatus !== 'verified' && $overallStatus !== 'not_required')
                <span class="fs-12">{{ translate('verification_is_required_before_you_can_withdraw') }}.</span>
            @endif
        </div>

        <div class="row">
            <div class="col-lg-5">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">{{ translate('submit_a_document') }}</h5></div>
                    <div class="card-body">
                        <form action="{{ route('vendor.business-settings.seller-verification.store') }}" method="post" enctype="multipart/form-data">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label fs-12" for="doc-type">{{ translate('document_type') }}</label>
                                <input list="required-doc-list" class="form-control" id="doc-type" name="document_type" required maxlength="60"
                                       placeholder="identity">
                                <datalist id="required-doc-list">
                                    @foreach ($requiredDocuments as $rd)
                                        <option value="{{ $rd }}">{{ translate($rd) }}</option>
                                    @endforeach
                                </datalist>
                                @if (!empty($requiredDocuments))
                                    <small class="text-muted">{{ translate('required') }}: {{ implode(', ', $requiredDocuments) }}</small>
                                @endif
                            </div>
                            <div class="mb-3">
                                <label class="form-label fs-12" for="doc-number">{{ translate('document_number') }} <span class="text-muted">({{ translate('optional') }})</span></label>
                                <input type="text" class="form-control" id="doc-number" name="document_number" maxlength="120">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fs-12" for="doc-expiry">{{ translate('expiry_date') }} <span class="text-muted">({{ translate('optional') }})</span></label>
                                <input type="date" class="form-control" id="doc-expiry" name="expires_at">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fs-12" for="doc-file">{{ translate('file') }} <span class="text-muted">(pdf, jpg, png — {{ translate('optional') }})</span></label>
                                <input type="file" class="form-control" id="doc-file" name="document_file" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                            <button class="btn btn-primary w-100">{{ translate('submit_for_review') }}</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <x-k.card :title="translate('your_documents')" :padded="false">
                    <div class="k-table-wrap">
                        <table class="k-table">
                            <thead>
                                <tr>
                                    <th>{{ translate('document') }}</th>
                                    <th>{{ translate('submitted') }}</th>
                                    <th>{{ translate('status') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                            @php($docTones = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'expired' => 'neutral'])
                            @foreach ($documents as $doc)
                                <tr>
                                    <td>
                                        {{ translate($doc->document_type) }}
                                        @if ($doc->document_number)<div class="k-text-subtle k-num">{{ $doc->document_number }}</div>@endif
                                        @if ($doc->file_path && Route::has('vendor.business-settings.seller-verification.document'))
                                            <a class="d-inline-block mt-1" target="_blank"
                                               href="{{ route('vendor.business-settings.seller-verification.document', $doc->id) }}">
                                                {{ translate('view_file') }}
                                            </a>
                                        @endif
                                    </td>
                                    <td><span class="k-num">{{ $doc->created_at?->toDateString() }}</span></td>
                                    <td>
                                        <x-k.badge :tone="$docTones[$doc->status] ?? 'neutral'">{{ translate($doc->status) }}</x-k.badge>
                                        @if ($doc->status === 'rejected' && $doc->rejection_reason)
                                            <div class="text-danger">{{ $doc->rejection_reason }}</div>
                                        @endif
                                        @if ($doc->status === 'approved' && $doc->expires_at)
                                            <div class="k-text-subtle">{{ translate('expires') }}: <span class="k-num">{{ $doc->expires_at->toDateString() }}</span></div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($documents->isEmpty())
                        <x-k.empty icon="info" :title="translate('you_have_not_submitted_any_documents_yet')" />
                    @endif
                </x-k.card>
            </div>
        </div>
    </div>
@endsection
