@extends('layouts.admin.app')

@section('title', translate('MyFatoorah_Saudi_Arabia'))

@section('content')
    <div class="content container-fluid">

        <div class="mb-4 pb-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            {{-- A brand name, so it is not run through translate(): there is nothing to translate, and
                 passing an Arabic string as a translation KEY would add that string to the language
                 files as a key of its own on first render. --}}
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                MyFatoorah — ماي فاتورة السعودية
            </h2>
            <a href="{{ route('admin.third-party.payment-method.index') }}" class="btn btn-outline-primary">
                {{ translate('back_to_payment_gateways') }}
            </a>
        </div>

        {{-- Why the gateway is or is not taking payments, in one line, before anything else on the page.
             An operator who has filled the form in and sees nothing at checkout needs this sentence,
             and without it the only way to find out is to try a real payment. --}}
        <div class="alert {{ $availability['ready'] ? 'alert-success' : 'alert-warning' }} d-flex align-items-center gap-2">
            <i class="fi {{ $availability['ready'] ? 'fi-sr-check' : 'fi-sr-info' }}"></i>
            <div>
                <strong>{{ $availability['ready'] ? translate('live_on_your_checkout') : translate('not_taking_payments') }}:</strong>
                {{ $availability['reason'] }}
                @unless($availability['ready'])
                    @if($availability['status']->value === 'disabled_in_admin')
                        — {{ translate('turn_it_on_from_the') }}
                        <a href="{{ route('admin.third-party.payment-method.index') }}">{{ translate('payment_gateways_page') }}</a>.
                    @endif
                @endunless
            </div>
        </div>

        @if(!$environmentEnabled)
            <div class="alert alert-danger fs-12">
                {{ translate('this_deployment_has_myfatoorah_switched_off_at_the_environment_level') }}
                (<code>MYFATOORAH_ENABLED</code>).
                {{ translate('no_payment_or_connection_test_will_be_attempted_until_it_is_on') }}.
                {{ translate('payments_already_in_flight_are_still_verified_so_no_money_is_stranded') }}.
            </div>
        @endif

        @if(!$requireSignature)
            <div class="alert alert-danger fs-12">
                <strong>{{ translate('webhook_signature_checking_is_switched_off') }}</strong>
                (<code>MYFATOORAH_WEBHOOK_REQUIRE_SIGNATURE</code>).
                {{ translate('unsigned_payment_notifications_will_be_accepted_this_must_never_be_the_case_in_production') }}.
            </div>
        @endif

        @if($attention['mismatched'] > 0 || $attention['stuck'] > 0)
            {{-- The two states where money exists and no order was released for it. Nothing else on this
                 page matters as much, so it sits at the top and is red. --}}
            <div class="alert alert-danger">
                <strong>{{ translate('these_need_a_person') }}:</strong>
                <ul class="mb-0 mt-2 fs-12">
                    @if($attention['mismatched'] > 0)
                        <li>{{ $attention['mismatched'] }} {{ translate('payment(s)_were_paid_but_for_a_different_amount_currency_or_order_reference') }}.</li>
                    @endif
                    @if($attention['stuck'] > 0)
                        <li>{{ $attention['stuck'] }} {{ translate('payment(s)_were_confirmed_but_their_order_was_never_created') }}.</li>
                    @endif
                </ul>
            </div>
        @endif

        <div class="row g-3">

            {{-- ---------------------------------------------------------------- Credentials --}}
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0">{{ translate('account_and_credentials') }}</h4>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('admin.third-party.payment-method.myfatoorah.settings') }}" method="POST">
                            @csrf
                            @method('PUT')

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="mode">{{ translate('mode') }}</label>
                                    <select class="form-select" name="mode" id="mode">
                                        <option value="test" {{ $config['mode'] === 'test' ? 'selected' : '' }}>
                                            {{ translate('sandbox_test') }}
                                        </option>
                                        <option value="live" {{ $config['mode'] === 'live' ? 'selected' : '' }}>
                                            {{ translate('live_production') }}
                                        </option>
                                    </select>
                                    <small class="text-muted">
                                        {{ translate('sandbox') }}: <code>{{ $sandboxHost }}</code> ·
                                        {{ translate('live') }}: <code>{{ $liveHost }}</code>
                                    </small>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="gateway_title">{{ translate('name_shown_to_customers') }}</label>
                                    <input type="text" class="form-control" id="gateway_title" name="gateway_title"
                                           value="{{ $config['title'] }}" maxlength="120" required>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="currency">{{ translate('currency') }}</label>
                                    <input type="text" class="form-control" id="currency" value="{{ $config['currency'] }}" disabled>
                                    <small class="text-muted">
                                        {{ translate('fixed_for_this_integration_a_payment_in_any_other_currency_is_refused') }}.
                                    </small>
                                </div>
                            </div>

                            <hr class="my-4">

                            {{-- Write-only. A stored secret is never rendered back here, not even masked:
                                 a prefix of an API key is still a clue, and the operator has the real
                                 value in the MyFatoorah portal if they need to see it. An empty field
                                 means "leave the stored one alone", which is the normal case. --}}
                            <div class="alert alert-info fs-12 py-2">
                                {{ translate('saved_keys_are_encrypted_and_are_never_displayed_again') }}.
                                {{ translate('leave_a_field_empty_to_keep_the_key_that_is_already_saved') }}.
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="test_api_key">
                                        {{ translate('test_api_key') }}
                                        @if($config['has_api_key'] && $config['mode'] === 'test')
                                            <span class="badge bg-success">{{ translate('saved') }}</span>
                                        @endif
                                    </label>
                                    <input type="password" class="form-control" id="test_api_key" name="test_api_key"
                                           autocomplete="new-password" placeholder="{{ translate('unchanged') }}">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="live_api_key">
                                        {{ translate('live_api_key') }}
                                        @if($config['has_api_key'] && $config['mode'] === 'live')
                                            <span class="badge bg-success">{{ translate('saved') }}</span>
                                        @endif
                                    </label>
                                    <input type="password" class="form-control" id="live_api_key" name="live_api_key"
                                           autocomplete="new-password" placeholder="{{ translate('unchanged') }}">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="test_webhook_secret">{{ translate('test_webhook_secret_key') }}</label>
                                    <input type="password" class="form-control" id="test_webhook_secret" name="test_webhook_secret"
                                           autocomplete="new-password" placeholder="{{ translate('unchanged') }}">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="live_webhook_secret">
                                        {{ translate('live_webhook_secret_key') }}
                                        @if($config['has_webhook_secret'])
                                            <span class="badge bg-success">{{ translate('saved_for_current_mode') }}</span>
                                        @endif
                                    </label>
                                    <input type="password" class="form-control" id="live_webhook_secret" name="live_webhook_secret"
                                           autocomplete="new-password" placeholder="{{ translate('unchanged') }}">
                                </div>
                            </div>

                            @if(!empty($config['account_methods']))
                                <hr class="my-4">
                                <label class="form-label">{{ translate('payment_methods_to_show_on_the_hosted_page') }}</label>
                                <div class="fs-12 text-muted mb-2">
                                    {{ translate('read_from_your_myfatoorah_account_leave_all_unticked_to_show_everything_the_account_allows') }}.
                                </div>
                                <div class="row g-2">
                                    @foreach($config['account_methods'] as $method)
                                        <div class="col-md-4">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox"
                                                       name="enabled_methods[]"
                                                       value="{{ $method['api_name'] }}"
                                                       id="method-{{ $loop->index }}"
                                                       {{ in_array($method['api_name'], $config['enabled_methods'] ?? [], true) ? 'checked' : '' }}>
                                                <label class="form-check-label" for="method-{{ $loop->index }}">
                                                    {{ $method['name'] }}
                                                    <small class="text-muted">({{ $method['currency'] }})</small>
                                                </label>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            <div class="d-flex gap-2 mt-4">
                                <button type="submit" class="btn btn-primary">{{ translate('save') }}</button>
                            </div>
                        </form>

                        <hr class="my-4">

                        <div class="d-flex flex-wrap gap-2">
                            @foreach(['test' => translate('test_sandbox_connection'), 'live' => translate('test_live_connection')] as $m => $label)
                                <form action="{{ route('admin.third-party.payment-method.myfatoorah.test-connection') }}" method="POST">
                                    @csrf
                                    <input type="hidden" name="mode" value="{{ $m }}">
                                    <button type="submit" class="btn btn-outline-primary btn-sm" {{ $environmentEnabled ? '' : 'disabled' }}>
                                        {{ $label }}
                                    </button>
                                </form>
                            @endforeach

                            <form action="{{ route('admin.third-party.payment-method.myfatoorah.refresh-methods') }}" method="POST">
                                @csrf
                                <input type="hidden" name="mode" value="{{ $config['mode'] }}">
                                <button type="submit" class="btn btn-outline-secondary btn-sm" {{ $environmentEnabled ? '' : 'disabled' }}>
                                    {{ translate('refresh_payment_methods') }}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ---------------------------------------------------------------- Status + webhook --}}
            <div class="col-lg-5">
                <div class="card mb-3">
                    <div class="card-header"><h4 class="mb-0">{{ translate('account_status') }}</h4></div>
                    <div class="card-body fs-12">
                        <table class="table table-sm mb-0">
                            <tbody>
                                <tr>
                                    <th>{{ translate('gateway_switch') }}</th>
                                    <td>
                                        <span class="badge {{ $config['active'] ? 'bg-success' : 'bg-secondary' }}">
                                            {{ $config['active'] ? translate('on') : translate('off') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr><th>{{ translate('mode') }}</th><td>{{ strtoupper($config['mode']) }}</td></tr>
                                <tr>
                                    <th>{{ translate('api_key_for_this_mode') }}</th>
                                    <td>{!! $config['has_api_key'] ? '<span class="text-success">'.translate('saved').'</span>' : '<span class="text-danger">'.translate('missing').'</span>' !!}</td>
                                </tr>
                                <tr>
                                    <th>{{ translate('webhook_secret') }}</th>
                                    <td>{!! $config['has_webhook_secret'] ? '<span class="text-success">'.translate('saved').'</span>' : '<span class="text-danger">'.translate('missing').'</span>' !!}</td>
                                </tr>
                                <tr><th>{{ translate('last_connection_test') }}</th><td>{{ $config['last_test_at'] ?? '—' }}</td></tr>
                                <tr>
                                    <th>{{ translate('result') }}</th>
                                    <td>
                                        @if($config['last_test_result'])
                                            <span class="badge {{ $config['last_test_result'] === 'ok' ? 'bg-success' : 'bg-danger' }}">
                                                {{ strtoupper($config['last_test_result']) }}
                                            </span>
                                        @else — @endif
                                    </td>
                                </tr>
                                @if($config['last_test_message'])
                                    <tr><th>{{ translate('detail') }}</th><td>{{ $config['last_test_message'] }}</td></tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h4 class="mb-0">{{ translate('webhook') }}</h4></div>
                    <div class="card-body fs-12">
                        <p class="mb-2">
                            {{ translate('register_this_url_in_the_myfatoorah_portal_under_integration_settings_webhook_and_enable_the_secure_key') }}:
                        </p>
                        <div class="input-group mb-3">
                            <input type="text" class="form-control" value="{{ $webhookUrl }}" readonly id="mf-webhook-url">
                            <button class="btn btn-outline-secondary" type="button" onclick="mfCopy('mf-webhook-url')">
                                {{ translate('copy') }}
                            </button>
                        </div>
                        <p class="text-muted mb-0">
                            {{ translate('the_same_url_is_also_sent_with_every_invoice_so_notifications_work_before_it_is_registered') }}.
                            {{ translate('deliveries_without_a_valid_signature_are_refused') }}.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        {{-- ---------------------------------------------------------------- Payment monitor --}}
        <div class="card mt-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h4 class="mb-0">{{ translate('payments') }}</h4>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <form class="d-flex gap-2" method="GET" action="{{ route('admin.third-party.payment-method.myfatoorah.index') }}">
                        <input type="search" class="form-control form-control-sm" name="search"
                               value="{{ request('search') }}" placeholder="{{ translate('reference_invoice_or_payment_id') }}">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">{{ translate('all_statuses') }}</option>
                            @foreach($statuses as $status)
                                <option value="{{ $status }}" {{ request('status') === $status ? 'selected' : '' }}>
                                    {{ translate($status) }}
                                </option>
                            @endforeach
                        </select>
                        <button class="btn btn-sm btn-outline-primary" type="submit">{{ translate('filter') }}</button>
                    </form>
                    <form action="{{ route('admin.third-party.payment-method.myfatoorah.reconcile') }}" method="POST">
                        @csrf
                        <button class="btn btn-sm btn-outline-secondary" type="submit">{{ translate('reconcile_now') }}</button>
                    </form>
                </div>
            </div>
            <div class="card-body px-0 pb-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle fs-12">
                        <thead>
                            <tr>
                                <th>{{ translate('reference') }}</th>
                                <th>{{ translate('payment_request') }}</th>
                                <th>{{ translate('invoice') }} / {{ translate('payment_id') }}</th>
                                <th>{{ translate('amount') }}</th>
                                <th>{{ translate('status') }}</th>
                                <th>{{ translate('created') }}</th>
                                <th>{{ translate('last_checked') }}</th>
                                <th>{{ translate('last_error') }}</th>
                                <th class="text-end">{{ translate('actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($attempts as $attempt)
                                <tr>
                                    <td><code>{{ $attempt->external_identifier }}</code></td>
                                    <td><code>{{ Str::limit($attempt->payment_request_id, 10, '…') }}</code></td>
                                    <td>
                                        {{ $attempt->invoice_id ?? '—' }}
                                        <br><small class="text-muted">{{ $attempt->payment_id ?? '—' }}</small>
                                    </td>
                                    <td>
                                        {{ $attempt->expected_amount }} {{ $attempt->expected_currency }}
                                        @if($attempt->captured_amount !== null && (float) $attempt->captured_amount !== (float) $attempt->expected_amount)
                                            <br><small class="text-danger">
                                                {{ translate('captured') }}: {{ $attempt->captured_amount }} {{ $attempt->captured_currency }}
                                            </small>
                                        @endif
                                    </td>
                                    <td>
                                        @php($badge = match($attempt->status) {
                                            'paid' => 'bg-success',
                                            'failed', 'cancelled', 'expired' => 'bg-secondary',
                                            'mismatch' => 'bg-danger',
                                            default => 'bg-warning text-dark',
                                        })
                                        <span class="badge {{ $badge }}">{{ translate($attempt->status) }}</span>
                                        @if($attempt->status === 'paid' && $attempt->hook_completed_at === null)
                                            <br><span class="badge bg-danger mt-1">{{ translate('no_order_created') }}</span>
                                        @endif
                                        <br><small class="text-muted">{{ strtoupper($attempt->mode) }} · {{ $attempt->platform ?? '—' }}</small>
                                    </td>
                                    <td>{{ $attempt->created_at }}</td>
                                    <td>{{ $attempt->last_verified_at ?? '—' }}</td>
                                    <td>
                                        @if($attempt->last_error_code)
                                            <span class="text-danger">{{ $attempt->last_error_code }}</span>
                                            <br><small>{{ Str::limit($attempt->last_error_message, 60) }}</small>
                                        @elseif($attempt->hook_error)
                                            <span class="text-danger">{{ Str::limit($attempt->hook_error, 60) }}</span>
                                        @else — @endif
                                    </td>
                                    <td class="text-end">
                                        <form action="{{ route('admin.third-party.payment-method.myfatoorah.attempts.reverify', $attempt->id) }}"
                                              method="POST" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-primary" type="submit">{{ translate('re_check') }}</button>
                                        </form>
                                        @if($attempt->status === 'paid')
                                            <button class="btn btn-sm btn-outline-danger" type="button"
                                                    data-bs-toggle="collapse" data-bs-target="#refund-{{ $attempt->id }}">
                                                {{ translate('refund') }}
                                            </button>
                                        @endif
                                    </td>
                                </tr>

                                @if($attempt->status === 'paid')
                                    <tr class="collapse" id="refund-{{ $attempt->id }}">
                                        <td colspan="9" class="bg-light">
                                            <form action="{{ route('admin.third-party.payment-method.myfatoorah.attempts.refund', $attempt->id) }}"
                                                  method="POST" class="row g-2 align-items-end">
                                                @csrf
                                                <div class="col-md-2">
                                                    <label class="form-label">{{ translate('amount') }}</label>
                                                    <input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm"
                                                           value="{{ $attempt->captured_amount ?? $attempt->expected_amount }}" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ translate('reason_shown_on_the_refund') }}</label>
                                                    <input type="text" name="comment" class="form-control form-control-sm" maxlength="200">
                                                </div>
                                                <div class="col-md-4">
                                                    <button class="btn btn-sm btn-danger" type="submit">{{ translate('request_refund') }}</button>
                                                    <small class="text-muted d-block mt-1">
                                                        {{ translate('myfatoorah_reviews_refunds_before_executing_them_stock_is_not_returned_by_this') }}.
                                                    </small>
                                                </div>
                                            </form>

                                            @if($attempt->refunds->isNotEmpty())
                                                <table class="table table-sm mt-2 mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>{{ translate('reference') }}</th>
                                                            <th>{{ translate('amount') }}</th>
                                                            <th>{{ translate('status') }}</th>
                                                            <th>{{ translate('requested') }}</th>
                                                            <th class="text-end">{{ translate('actions') }}</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach($attempt->refunds as $refund)
                                                            <tr>
                                                                <td><code>{{ $refund->myfatoorah_reference ?? Str::limit($refund->external_identifier, 14, '…') }}</code></td>
                                                                <td>{{ $refund->amount }} {{ $refund->currency }}</td>
                                                                <td>
                                                                    <span class="badge {{ $refund->isExecuted() ? 'bg-success' : ($refund->status === 'failed' || $refund->status === 'rejected' ? 'bg-secondary' : 'bg-warning text-dark') }}">
                                                                        {{ translate($refund->status) }}
                                                                    </span>
                                                                </td>
                                                                <td>{{ $refund->requested_at ?? $refund->created_at }}</td>
                                                                <td class="text-end">
                                                                    @if(!$refund->isExecuted() && !in_array($refund->status, ['failed','rejected'], true))
                                                                        <form action="{{ route('admin.third-party.payment-method.myfatoorah.refunds.executed', $refund->id) }}"
                                                                              method="POST" class="d-inline">
                                                                            @csrf
                                                                            <button class="btn btn-sm btn-outline-success" type="submit">
                                                                                {{ translate('mark_as_executed') }}
                                                                            </button>
                                                                        </form>
                                                                    @endif
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            @endif
                                        </td>
                                    </tr>
                                @endif
                            @empty
                                <tr><td colspan="9" class="text-center py-4">{{ translate('no_payments_yet') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-3 py-2">{{ $attempts->links() }}</div>
            </div>
        </div>

        {{-- ---------------------------------------------------------------- Webhook deliveries --}}
        <div class="card mt-3">
            <div class="card-header"><h4 class="mb-0">{{ translate('recent_webhook_deliveries') }}</h4></div>
            <div class="card-body px-0 pb-0">
                <div class="table-responsive">
                    <table class="table table-sm fs-12 mb-0">
                        <thead>
                            <tr>
                                <th>{{ translate('received') }}</th>
                                <th>{{ translate('event') }}</th>
                                <th>{{ translate('invoice') }}</th>
                                <th>{{ translate('invoice_status') }}</th>
                                <th>{{ translate('transaction_status') }}</th>
                                <th>{{ translate('outcome') }}</th>
                                <th>{{ translate('note') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentEvents as $event)
                                <tr>
                                    <td>{{ $event->received_at }}</td>
                                    <td>{{ $event->event_name ?? $event->event_code }}</td>
                                    <td>{{ $event->invoice_id ?? '—' }}</td>
                                    <td>{{ $event->invoice_status ?? '—' }}</td>
                                    <td>{{ $event->transaction_status ?? '—' }}</td>
                                    <td>
                                        <span class="badge {{ in_array($event->process_result, ['settled','already_settled','duplicate'], true) ? 'bg-success' : ($event->process_result === 'mismatch' ? 'bg-danger' : 'bg-secondary') }}">
                                            {{ $event->process_result ?? '—' }}
                                        </span>
                                    </td>
                                    <td>{{ Str::limit($event->process_note, 70) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center py-4">{{ translate('no_webhook_deliveries_yet') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script>
        'use strict';
        function mfCopy(id) {
            const field = document.getElementById(id);
            if (!field) return;
            field.select();
            navigator.clipboard?.writeText(field.value);
        }
    </script>
@endpush
