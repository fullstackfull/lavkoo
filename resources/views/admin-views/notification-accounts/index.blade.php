@extends('layouts.admin.app')

@section('title', translate('notification_accounts_per_market'))

@section('content')
    @php
        $result = session('mail_account_test_result');
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="translate('notification_accounts_per_market')"
                         :subtitle="translate('each_market_can_send_through_its_own_account_and_a_market_without_one_sends_through_the_shared_account')" />

        @unless ($accountsPerMarketAreOn)
            <div class="alert alert-info fs-12" role="note">{{ translate('accounts_per_market_are_switched_off_so_nothing_reads_these_accounts_yet') }}</div>
        @endunless

        @if ($errors->any())
            <div class="alert alert-danger fs-12" role="alert">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        @if (is_array($result))
            <div class="alert {{ $result['sent'] ? 'alert-success' : 'alert-danger' }} fs-12" role="alert" id="mail-account-test-result">
                <div class="fw-semibold">
                    {{ $result['sent'] ? translate('the_test_email_was_accepted_by_the_mail_server') : translate('the_test_email_was_not_sent') }}
                </div>
                <div>{{ translate('market') }}: {{ $result['market'] }}</div>
                <div>{{ $result['own_account'] ? translate('it_left_through_this_markets_own_account') : translate('it_left_through_the_shared_account_because_this_market_has_none_of_its_own') }}</div>
                @if ($result['host'])<div>{{ translate('host') }}: {{ $result['host'] }}</div>@endif
                @if ($result['from'])<div>{{ translate('from_address') }}: {{ $result['from'] }}</div>@endif
                @if ($result['error'])<div class="mt-1">{{ translate('the_mail_server_answered') }}: {{ $result['error'] }}</div>@endif
            </div>
        @endif

        @if (count($rows) === 0)
            <x-k.empty :title="translate('no_market_is_yours_to_configure')" />
        @endif

        <div class="row g-3">
            @foreach ($rows as $row)
                <div class="col-xl-6">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                                <div>
                                    <h3 class="mb-0">{{ $row['country'] }}</h3>
                                    <p class="fs-12 mb-0">
                                        {{ $row['is_shared'] ? translate('this_is_the_shared_account_every_market_without_one_of_its_own_uses') : translate('this_market_has_an_account_of_its_own') }}
                                    </p>
                                </div>
                                <span class="badge bg-{{ $row['channels'][$mailChannel]['configured'] ? ($row['channels'][$mailChannel]['active'] ? 'success' : 'secondary') : 'light text-dark' }}">
                                    {{ $row['channels'][$mailChannel]['configured'] ? ($row['channels'][$mailChannel]['active'] ? translate('in_use') : translate('switched_off')) : translate('not_configured') }}
                                </span>
                            </div>

                            <div class="table-responsive mb-3">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>{{ translate('channel') }}</th>
                                            <th>{{ translate('settings') }}</th>
                                            <th>{{ translate('secrets_stored') }}</th>
                                            {{-- I1-09: an account can be configured, active and WRONG, and that used to read here exactly
                                                 like a working one, because both senders swallowed their failure where it happened. --}}
                                            <th>{{ translate('undelivered') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($channels as $channel)
                                            @php($account = $row['channels'][$channel])
                                            <tr>
                                                <td class="text-capitalize">{{ translate($channel) }}</td>
                                                <td class="fs-12">
                                                    @if ($account['configured'] && count($account['settings']) > 0)
                                                        @foreach ($account['settings'] as $name => $value)
                                                            <div>{{ $name }}: {{ is_scalar($value) ? $value : json_encode($value) }}</div>
                                                        @endforeach
                                                    @else
                                                        <span class="text-muted">{{ translate('nothing_is_stored') }}</span>
                                                    @endif
                                                </td>
                                                <td class="fs-12">
                                                    @if (count($account['credentials_present']) > 0)
                                                        @foreach ($account['credentials_present'] as $name)
                                                            <div>{{ $name }}: &bull;&bull;&bull;&bull;&bull;&bull;</div>
                                                        @endforeach
                                                    @else
                                                        <span class="text-muted">{{ translate('nothing_is_stored') }}</span>
                                                    @endif
                                                </td>
                                                <td class="fs-12">
                                                    @if (!($row['recording_attempts'] ?? false))
                                                        <span class="text-muted">{{ translate('not_recorded_yet') }}</span>
                                                    @elseif (($account['undelivered'] ?? 0) > 0)
                                                        <span class="badge badge-soft-danger">{{ $account['undelivered'] }}</span>
                                                    @else
                                                        <span class="text-muted">0</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            @php($mail = $row['channels'][$mailChannel])
                            <form action="{{ route('admin.notification-accounts.mail.save') }}" method="post" class="mb-3">
                                @csrf
                                <input type="hidden" name="country" value="{{ $row['country'] }}">
                                <h4 class="fs-14">{{ translate('mail_account') }}</h4>
                                <div class="row g-2">
                                    <div class="col-sm-8">
                                        <label class="form-label" for="host-{{ $row['country'] }}">{{ translate('host') }}</label>
                                        <input type="text" class="form-control" id="host-{{ $row['country'] }}" name="host" value="{{ $mail['settings']['host'] ?? '' }}">
                                    </div>
                                    <div class="col-sm-4">
                                        <label class="form-label" for="port-{{ $row['country'] }}">{{ translate('port') }}</label>
                                        <input type="number" class="form-control" id="port-{{ $row['country'] }}" name="port" value="{{ $mail['settings']['port'] ?? '' }}">
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label" for="encryption-{{ $row['country'] }}">{{ translate('encryption') }}</label>
                                        <select class="form-control" id="encryption-{{ $row['country'] }}" name="encryption">
                                            <option value="">{{ translate('none') }}</option>
                                            <option value="tls" {{ ($mail['settings']['encryption'] ?? '') === 'tls' ? 'selected' : '' }}>tls</option>
                                            <option value="ssl" {{ ($mail['settings']['encryption'] ?? '') === 'ssl' ? 'selected' : '' }}>ssl</option>
                                        </select>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label" for="from-{{ $row['country'] }}">{{ translate('from_address') }}</label>
                                        <input type="email" class="form-control" id="from-{{ $row['country'] }}" name="from_address" value="{{ $mail['settings']['from_address'] ?? '' }}">
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label" for="username-{{ $row['country'] }}">{{ translate('username') }}</label>
                                        <input type="text" class="form-control" id="username-{{ $row['country'] }}" name="username" autocomplete="off"
                                               placeholder="{{ in_array('username', $mail['credentials_present'], true) ? translate('stored_leave_blank_to_keep_it') : '' }}">
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label" for="password-{{ $row['country'] }}">{{ translate('password') }}</label>
                                        <input type="password" class="form-control" id="password-{{ $row['country'] }}" name="password" autocomplete="new-password"
                                               placeholder="{{ in_array('password', $mail['credentials_present'], true) ? translate('stored_leave_blank_to_keep_it') : '' }}">
                                    </div>
                                    <div class="col-12">
                                        <p class="fs-12 text-muted mb-1">{{ translate('a_stored_secret_is_never_shown_again_leave_the_field_blank_to_keep_it') }}</p>
                                        @foreach ($mail['credentials_present'] as $name)
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="forget[]" value="{{ $name }}" id="forget-{{ $row['country'] }}-{{ $name }}">
                                                <label class="form-check-label fs-12" for="forget-{{ $row['country'] }}-{{ $name }}">{{ translate('clear_the_stored_secret') }}: {{ $name }}</label>
                                            </div>
                                        @endforeach
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="active" value="1" id="active-{{ $row['country'] }}"
                                                   {{ $mail['active'] || !$mail['configured'] ? 'checked' : '' }}>
                                            <label class="form-check-label fs-12" for="active-{{ $row['country'] }}">{{ translate('use_this_account_for_this_markets_email') }}</label>
                                        </div>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary mt-3">{{ translate('save') }}</button>
                            </form>

                            <form action="{{ route('admin.notification-accounts.mail.test') }}" method="post">
                                @csrf
                                <input type="hidden" name="country" value="{{ $row['country'] }}">
                                <h4 class="fs-14">{{ translate('send_test_mail') }}</h4>
                                <p class="fs-12 text-muted">{{ translate('the_mail_servers_own_answer_is_shown_whether_it_accepted_the_message_or_refused_it') }}</p>
                                <div class="d-flex flex-wrap gap-2 align-items-end">
                                    <div class="flex-grow-1">
                                        <label class="form-label" for="test-email-{{ $row['country'] }}">{{ translate('type_mail_address') }}</label>
                                        <input type="email" class="form-control" id="test-email-{{ $row['country'] }}" name="email" required>
                                    </div>
                                    <button type="submit" class="btn btn-outline-primary">{{ translate('send_mail') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach

            {{-- The messages that used up the whole attempt budget and still did not go, narrowed to this employee's own markets. It
                 names a template key and the provider's own words, and never an address: the log stores a digest of the recipient. --}}
            @if (!empty($undelivered))
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">{{ translate('messages_that_were_never_delivered') }}</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>{{ translate('storefront') }}</th>
                                            <th>{{ translate('channel') }}</th>
                                            <th>{{ translate('template') }}</th>
                                            <th>{{ translate('recipient') }}</th>
                                            <th>{{ translate('attempts') }}</th>
                                            <th>{{ translate('provider_answer') }}</th>
                                            <th>{{ translate('when') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($undelivered as $attempt)
                                            <tr>
                                                <td>{{ $attempt['storefront_country'] ?? translate('every_storefront') }}</td>
                                                <td class="text-capitalize">{{ translate($attempt['channel']) }}</td>
                                                <td class="fs-12">{{ $attempt['template_key'] }}</td>
                                                <td class="fs-12 text-capitalize">{{ $attempt['recipient_type'] ?? '-' }}</td>
                                                <td>{{ $attempt['attempt'] }}</td>
                                                <td class="fs-12">{{ $attempt['provider_error'] ?? '-' }}</td>
                                                <td class="fs-12">{{ $attempt['created_at'] ?? '-' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
