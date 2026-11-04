@extends('layouts.admin.app')

@section('title', translate('authentication_security'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2"><i class="fi fi-rr-shield-check"></i> {{ translate('authentication_security') }}</h2>
            <p class="mb-0 fs-12">{{ translate('the_bot_defence_on_your_sign_in_forms_and_how_a_customer_recovers_their_account') }}.</p>
        </div>

        <form action="{{ route('admin.settings.authentication.update') }}" method="post">
            @csrf

            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h5 class="mb-0">{{ translate('recaptcha') }}</h5>
                        <small class="text-muted">{{ translate('applies_to_every_sign_in_and_password_reset_form_admin_vendor_and_customer') }}.</small>
                    </div>
                    <span class="k-badge k-badge--{{ $enforced ? 'success' : 'secondary' }}">
                        {{ $enforced ? translate('enforced') : translate('not_enforced') }}
                    </span>
                </div>
                <div class="card-body row g-3 align-items-end">
                    @if (!$enforced)
                        <div class="col-12">
                            <div class="alert alert-info fs-12 mb-0">
                                {{ translate('while_this_is_off_the_sign_in_forms_are_protected_by_rate_limiting_alone') }}.
                                {{ translate('the_limit_is_on_the_platform_policies_page_under_access_policy') }}.
                            </div>
                        </div>
                    @endif

                    <div class="col-sm-3">
                        <label class="form-label fs-12" for="recaptcha-status">{{ translate('status') }}</label>
                        <select id="recaptcha-status" name="status" class="form-control">
                            <option value="0" {{ (int) ($recaptcha['status'] ?? 0) === 0 ? 'selected' : '' }}>{{ translate('off') }}</option>
                            <option value="1" {{ (int) ($recaptcha['status'] ?? 0) === 1 ? 'selected' : '' }}>{{ translate('on') }}</option>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label fs-12" for="recaptcha-site-key">{{ translate('site_key') }}</label>
                        <input id="recaptcha-site-key" name="site_key" class="form-control" maxlength="191"
                               value="{{ old('site_key', $recaptcha['site_key'] ?? '') }}">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label fs-12" for="recaptcha-secret-key">{{ translate('secret_key') }}</label>
                        <input id="recaptcha-secret-key" name="secret_key" class="form-control" maxlength="191"
                               value="{{ old('secret_key', $recaptcha['secret_key'] ?? '') }}">
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label fs-12" for="recaptcha-score">{{ translate('lowest_score_let_through') }} (0–1)</label>
                        <input id="recaptcha-score" name="recaptcha_minimum_score" type="number" step="0.01" min="0" max="1"
                               class="form-control" value="{{ old('recaptcha_minimum_score', $minimumScore) }}">
                        <small class="text-muted">{{ translate('higher_turns_away_more_bots_and_more_people') }}.</small>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="mb-0">{{ translate('customer_password_recovery') }}</h5>
                    <small class="text-muted">{{ translate('the_vendor_and_delivery_man_equivalents_are_on_their_own_settings_pages') }}.</small>
                </div>
                <div class="card-body row g-3 align-items-end">
                    <div class="col-sm-4">
                        <label class="form-label fs-12" for="reset-channel">{{ translate('send_the_reset_through') }}</label>
                        <select id="reset-channel" name="forgot_password_verification" class="form-control">
                            <option value="email" {{ $resetChannel === 'email' ? 'selected' : '' }}>{{ translate('email') }}</option>
                            <option value="phone" {{ $resetChannel === 'phone' ? 'selected' : '' }}>{{ translate('sms_otp') }}</option>
                        </select>
                    </div>
                    <div class="col-sm-8">
                        <small class="text-muted d-block">
                            {{ translate('this_value_is_also_shipped_to_the_mobile_apps_in_the_config_payload_so_they_ask_for_the_same_thing_the_website_does') }}.
                        </small>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end">
                <button class="btn btn-primary px-4">{{ translate('save') }}</button>
            </div>
        </form>
    </div>
@endsection
