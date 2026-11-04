@extends('layouts.admin.app')

@section('title', translate('product_moderation'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="fi fi-rr-shield-check"></i>
                {{ translate('product_moderation') }}
            </h2>
            <p class="mb-0 fs-12">{{ translate('approve_reject_or_request_changes_with_a_reason_the_seller_can_see') }}</p>
        </div>

        <div class="card">
            <div class="card-body">
                <p class="fs-12 text-muted">
                    {{ translate('moderate_a_product_by_id') }}. {{ translate('every_decision_is_recorded_with_its_reason_and_appears_on_the_audit_log') }}.
                </p>
                <form action="{{ route('admin.marketplace.product-moderation.moderate') }}" method="post" class="row g-3">
                    @csrf
                    <div class="col-sm-3">
                        <label class="form-label" for="product_id">{{ translate('product_id') }}</label>
                        <input type="number" class="form-control" id="product_id" name="product_id" min="1" required>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label" for="action">{{ translate('action') }}</label>
                        <select class="form-control" id="action" name="action" required>
                            <option value="approved">{{ translate('approved') }}</option>
                            <option value="needs_changes">{{ translate('needs_changes') }}</option>
                            <option value="rejected">{{ translate('rejected') }}</option>
                            <option value="suspended">{{ translate('suspended') }}</option>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label">{{ translate('reasons') }}</label>
                        <div class="d-flex flex-wrap gap-2">
                            @foreach ($reasons as $reason)
                                <label class="d-flex align-items-center gap-1 fs-12 border rounded px-2 py-1">
                                    <input type="checkbox" name="reason_codes[]" value="{{ $reason }}">
                                    {{ translate($reason) }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="note">{{ translate('note_to_the_seller') }}</label>
                        <textarea class="form-control" id="note" name="note" rows="2"
                                  placeholder="{{ translate('the_exact_correction_required') }}"></textarea>
                    </div>
                    <div class="col-12 d-flex justify-content-end">
                        <button class="btn btn-primary">{{ translate('record_decision') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
