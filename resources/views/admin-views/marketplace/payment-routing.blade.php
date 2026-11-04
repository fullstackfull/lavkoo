@extends('layouts.admin.app')

@section('title', translate('payment_routing'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2"><i class="fi fi-rr-shuffle"></i> {{ translate('payment_orchestration') }}</h2>
            <p class="mb-0 fs-12">{{ translate('hide_or_prefer_gateways_by_amount_and_country_no_rule_means_the_gateways_are_offered_as_today') }}.</p>
        </div>

        <div class="row">
            <div class="col-lg-5">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">{{ translate('add_rule') }}</h5></div>
                    <div class="card-body">
                        <form action="{{ route('admin.marketplace.payment-routing.store') }}" method="post" class="row g-2">
                            @csrf
                            <div class="col-12"><label class="form-label fs-12">{{ translate('name') }} *</label>
                                <input type="text" name="name" class="form-control form-control-sm" required maxlength="191"></div>
                            <div class="col-7"><label class="form-label fs-12">{{ translate('gateway') }} *</label>
                                <input list="gateway-codes" name="gateway_code" class="form-control form-control-sm" required maxlength="60">
                                <datalist id="gateway-codes">
                                    @foreach ($gateways as $g)<option value="{{ $g }}">@endforeach
                                </datalist></div>
                            <div class="col-5"><label class="form-label fs-12">{{ translate('action') }}</label>
                                <select name="action" class="form-control form-control-sm">
                                    <option value="hide">{{ translate('hide') }}</option>
                                    <option value="prefer">{{ translate('prefer') }}</option>
                                </select></div>
                            <div class="col-6"><label class="form-label fs-12">{{ translate('min_amount') }}</label>
                                <input type="number" step="0.01" name="min_amount" class="form-control form-control-sm" min="0" placeholder="—"></div>
                            <div class="col-6"><label class="form-label fs-12">{{ translate('max_amount') }}</label>
                                <input type="number" step="0.01" name="max_amount" class="form-control form-control-sm" min="0" placeholder="—"></div>
                            <div class="col-8"><label class="form-label fs-12">{{ translate('country') }}</label>
                                <input type="text" name="country" class="form-control form-control-sm" placeholder="{{ translate('any') }}"></div>
                            <div class="col-4"><label class="form-label fs-12">{{ translate('priority') }}</label>
                                <input type="number" name="priority" class="form-control form-control-sm" min="0" value="10"></div>
                            <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary btn-sm">{{ translate('save') }}</button></div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ translate('resolution_preview') }}</h5></div>
                    <div class="card-body">
                        <form method="get" class="row g-2">
                            <div class="col-6"><input type="number" step="0.01" name="amount" value="{{ $preview['input']['amount'] ?? '' }}" class="form-control form-control-sm" placeholder="{{ translate('order_amount') }}"></div>
                            <div class="col-6"><input type="text" name="country" value="{{ $preview['input']['country'] ?? '' }}" class="form-control form-control-sm" placeholder="{{ translate('country') }}"></div>
                            <div class="col-12 d-flex justify-content-end"><button class="btn btn-outline-primary btn-sm">{{ translate('preview') }}</button></div>
                        </form>
                        @if ($preview)
                            <hr class="my-2">
                            <div class="fs-12 text-muted mb-1">{{ translate('gateways_offered_in_order') }}:</div>
                            <div class="d-flex flex-wrap gap-1">
                                @forelse ($preview['result'] as $g)
                                    <span class="k-badge border">{{ $g }}</span>
                                @empty
                                    <span class="text-danger fs-12">{{ translate('none') }}</span>
                                @endforelse
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ translate('rules') }}</h5></div>
                    <div class="card-body p-0">
                        <div class="k-table-wrap">
                            <table class="k-table">
                                <thead><tr>
                                    <th>{{ translate('name') }}</th><th>{{ translate('gateway') }}</th><th>{{ translate('action') }}</th>
                                    <th>{{ translate('conditions') }}</th><th>{{ translate('status') }}</th><th></th>
                                </tr></thead>
                                <tbody>
                                @forelse ($rules as $r)
                                    <tr>
                                        <td>{{ $r->name }} <span class="fs-10 text-muted">p{{ $r->priority }}</span></td>
                                        <td>{{ $r->gateway_code }}</td>
                                        <td><span class="k-badge k-badge--{{ $r->action === 'hide' ? 'danger' : 'success' }}">{{ translate($r->action) }}</span></td>
                                        <td class="fs-12">
                                            @if($r->min_amount !== null || $r->max_amount !== null){{ translate('amount') }}: {{ $r->min_amount !== null ? number_format((float)$r->min_amount,0) : '0' }}–{{ $r->max_amount !== null ? number_format((float)$r->max_amount,0) : '∞' }}@endif
                                            @if($r->country) · {{ $r->country }}@endif
                                            @if($r->min_amount === null && $r->max_amount === null && !$r->country)<span class="text-muted">{{ translate('always') }}</span>@endif
                                        </td>
                                        <td><span class="k-badge k-badge--{{ $r->status === 'active' ? 'success' : 'secondary' }}">{{ translate($r->status) }}</span></td>
                                        <td class="text-end"><form action="{{ route('admin.marketplace.payment-routing.destroy', $r->id) }}" method="post" onsubmit="return confirm('{{ translate('are_you_sure') }}')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">{{ translate('delete') }}</button></form></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-4">{{ translate('no_rules_yet_gateways_are_offered_as_today') }}</td></tr>
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
