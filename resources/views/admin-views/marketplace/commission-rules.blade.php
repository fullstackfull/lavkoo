@extends('layouts.admin.app')

@section('title', translate('commission_rules'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2"><i class="fi fi-rr-badge-percent"></i> {{ translate('commission_rules') }}</h2>
            <p class="mb-0 fs-12">{{ translate('set_the_marketplace_commission_by_scope_and_priority_with_no_active_rule_the_legacy_flat_percentage_applies') }}.</p>
        </div>

        <div class="row">
            <div class="col-lg-5">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">{{ translate('add_rule') }}</h5></div>
                    <div class="card-body">
                        <form action="{{ route('admin.marketplace.commission-rules.store') }}" method="post" class="row g-2">
                            @csrf
                            <div class="col-12"><label class="form-label fs-12">{{ translate('label') }}</label>
                                <input type="text" name="label" class="form-control form-control-sm" maxlength="191" placeholder="{{ translate('e_g_default_rate') }}"></div>
                            <div class="col-6"><label class="form-label fs-12">{{ translate('scope') }} *</label>
                                <select name="scope_type" class="form-control form-control-sm">
                                    @foreach ($scopeTypes as $scope)
                                        <option value="{{ $scope }}">{{ translate($scope) }}</option>
                                    @endforeach
                                </select></div>
                            <div class="col-6"><label class="form-label fs-12">{{ translate('scope_id') }} <span class="text-muted">({{ translate('blank_for_global') }})</span></label>
                                <input type="number" name="scope_id" class="form-control form-control-sm" min="1" placeholder="—"></div>
                            <div class="col-12"><label class="form-label fs-12">{{ translate('rate_type') }} *</label>
                                <select name="rate_type" class="form-control form-control-sm">
                                    @foreach ($rateTypes as $rate)
                                        <option value="{{ $rate }}">{{ translate($rate) }}</option>
                                    @endforeach
                                </select></div>
                            <div class="col-6"><label class="form-label fs-12">{{ translate('percentage') }} (%)</label>
                                <input type="number" step="0.01" name="percentage" class="form-control form-control-sm" min="0" max="100" value="0"></div>
                            <div class="col-6"><label class="form-label fs-12">{{ translate('fixed_amount') }}</label>
                                <input type="number" step="0.01" name="fixed_amount" class="form-control form-control-sm" min="0" value="0"></div>
                            <div class="col-6"><label class="form-label fs-12">{{ translate('min_amount') }}</label>
                                <input type="number" step="0.01" name="min_amount" class="form-control form-control-sm" min="0" placeholder="—"></div>
                            <div class="col-6"><label class="form-label fs-12">{{ translate('max_amount') }}</label>
                                <input type="number" step="0.01" name="max_amount" class="form-control form-control-sm" min="0" placeholder="—"></div>
                            <div class="col-6"><label class="form-label fs-12">{{ translate('priority') }}</label>
                                <input type="number" name="priority" class="form-control form-control-sm" min="0" value="10"></div>
                            <div class="col-6 d-flex align-items-end">
                                <div class="form-check">
                                    <input type="checkbox" name="is_active" value="1" class="form-check-input" id="is_active" checked>
                                    <label class="form-check-label fs-12" for="is_active">{{ translate('active') }}</label>
                                </div>
                            </div>
                            <div class="col-6"><label class="form-label fs-12">{{ translate('starts_at') }}</label>
                                <input type="date" name="starts_at" class="form-control form-control-sm"></div>
                            <div class="col-6"><label class="form-label fs-12">{{ translate('ends_at') }}</label>
                                <input type="date" name="ends_at" class="form-control form-control-sm"></div>
                            <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary btn-sm">{{ translate('save') }}</button></div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ translate('resolution_preview') }}</h5></div>
                    <div class="card-body">
                        <form method="get" class="row g-2">
                            <div class="col-6"><input type="number" step="0.01" name="preview_amount" value="{{ $preview['input']['preview_amount'] ?? '' }}" class="form-control form-control-sm" placeholder="{{ translate('commissionable_amount') }}"></div>
                            <div class="col-6"><input type="number" name="preview_product_id" value="{{ $preview['input']['preview_product_id'] ?? '' }}" class="form-control form-control-sm" placeholder="{{ translate('product_id') }}"></div>
                            <div class="col-6"><input type="number" name="preview_seller_id" value="{{ $preview['input']['preview_seller_id'] ?? '' }}" class="form-control form-control-sm" placeholder="{{ translate('seller_id') }}"></div>
                            <div class="col-6"><input type="number" name="preview_category_id" value="{{ $preview['input']['preview_category_id'] ?? '' }}" class="form-control form-control-sm" placeholder="{{ translate('category_id') }}"></div>
                            <div class="col-12 d-flex justify-content-end"><button class="btn btn-outline-primary btn-sm">{{ translate('preview') }}</button></div>
                        </form>
                        @if ($preview)
                            <div class="mt-3 fs-12">
                                <div>{{ translate('rule') }}: <strong>{{ $preview['result']['rule_label'] ?? translate('none') }}</strong> ({{ $preview['result']['rule_scope_type'] ?? translate('legacy') }})</div>
                                <div>{{ translate('commission') }}: <strong>{{ number_format($preview['result']['commission_amount'] ?? 0, 2) }}</strong></div>
                                <div>{{ translate('seller_net') }}: <strong>{{ number_format($preview['result']['seller_net_amount'] ?? 0, 2) }}</strong></div>
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
                                <thead>
                                    <tr>
                                        <th>{{ translate('label') }}</th>
                                        <th>{{ translate('scope') }}</th>
                                        <th>{{ translate('rate') }}</th>
                                        <th>{{ translate('priority') }}</th>
                                        <th>{{ translate('status') }}</th>
                                        <th class="text-end">{{ translate('action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($rules as $rule)
                                        <tr>
                                            <td>{{ $rule->label ?: '—' }}</td>
                                            <td>{{ translate($rule->scope_type) }}{{ $rule->scope_id ? ' #'.$rule->scope_id : '' }}</td>
                                            <td>
                                                @if ($rule->rate_type === 'fixed') {{ number_format($rule->fixed_amount, 2) }}
                                                @elseif ($rule->rate_type === 'percentage_plus_fixed') {{ $rule->percentage }}% + {{ number_format($rule->fixed_amount, 2) }}
                                                @else {{ $rule->percentage }}% @endif
                                            </td>
                                            <td>{{ $rule->priority }}</td>
                                            <td>
                                                <span class="k-badge {{ $rule->is_active ? 'k-badge--success' : '' }}">
                                                    {{ $rule->is_active ? translate('active') : translate('inactive') }}
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <form action="{{ route('admin.marketplace.commission-rules.toggle', $rule->id) }}" method="post" class="d-inline">
                                                    @csrf
                                                    <button class="btn btn-sm btn-outline-secondary">{{ $rule->is_active ? translate('disable') : translate('enable') }}</button>
                                                </form>
                                                <form action="{{ route('admin.marketplace.commission-rules.destroy', $rule->id) }}" method="post" class="d-inline" onsubmit="return confirm('{{ translate('delete_this_rule') }}?')">
                                                    @csrf @method('DELETE')
                                                    <button class="btn btn-sm btn-outline-danger">{{ translate('delete') }}</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="text-center text-muted py-4">{{ translate('no_rules_yet_the_legacy_flat_percentage_applies') }}</td></tr>
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
