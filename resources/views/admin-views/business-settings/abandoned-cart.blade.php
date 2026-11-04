@extends('layouts.admin.app')

@section('title', translate('abandoned_cart_recovery'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('business_Setup') }}
            </h2>
        </div>
        @include('admin-views.business-settings.business-setup-inline-menu')

        <div class="row g-3 mb-3">
            @foreach ([
                ['label' => 'reminders_sent', 'value' => number_format($stats['sent']), 'icon' => 'fi-rr-envelope'],
                ['label' => 'reminders_clicked', 'value' => number_format($stats['clicked']), 'icon' => 'fi-rr-cursor-finger'],
                ['label' => 'carts_recovered', 'value' => number_format($stats['recovered']), 'icon' => 'fi-rr-shopping-cart-check'],
                ['label' => 'value_recovered', 'value' => webCurrencyConverter(amount: $stats['recovered_value']), 'icon' => 'fi-rr-coins'],
            ] as $card)
                <div class="col-sm-6 col-xl-3">
                    <div class="card h-100">
                        <div class="card-body d-flex align-items-center gap-3">
                            <span class="d-flex align-items-center justify-content-center bg-section rounded"
                                  style="width:44px;height:44px;" aria-hidden="true">
                                <i class="fi {{ $card['icon'] }}"></i>
                            </span>
                            <span class="min-w-0">
                                <h4 class="mb-0 text-truncate">{{ $card['value'] }}</h4>
                                <p class="mb-0 fs-12 text-truncate">{{ translate($card['label']) }}</p>
                            </span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($stats['sent'] === 0)
            <div class="alert alert-secondary fs-12 mb-3">
                {{ translate('no_reminders_have_been_sent_yet') }}
            </div>
        @endif

        <form action="{{ route('admin.business-settings.abandoned-cart.update') }}" method="post">
            @csrf

            <div class="card mb-3">
                <div class="card-body">
                    <div class="mb-3 mb-sm-20">
                        <h3>{{ translate('abandoned_cart_recovery') }}</h3>
                        <p class="mb-0 fs-12">
                            {{ translate('send_reminder_emails_to_customers_who_left_items_in_their_cart') }}
                        </p>
                    </div>

                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="row g-4">
                            <div class="col-xl-6 col-md-6">
                                <label class="d-flex justify-content-between align-items-start gap-3 border rounded p-3 user-select-none h-100 bg-white"
                                       for="abandoned-cart-status">
                                    <span>
                                        <h5 class="fw-medium text-dark fs-14 mb-1">{{ translate('abandoned_cart_reminder_status') }}</h5>
                                        <p class="mb-0 fs-12">
                                            {{ translate('send_reminder_emails_to_customers_who_left_items_in_their_cart') }}
                                        </p>
                                    </span>
                                    <span class="switcher">
                                        <input type="checkbox" class="switcher_input" id="abandoned-cart-status"
                                               name="status" value="1" {{ $enabled ? 'checked' : '' }}>
                                        <span class="switcher_control"></span>
                                    </span>
                                </label>
                            </div>

                            <div class="col-xl-6 col-md-6">
                                <label class="d-flex justify-content-between align-items-start gap-3 border rounded p-3 user-select-none h-100 bg-white"
                                       for="abandoned-cart-guests">
                                    <span>
                                        <h5 class="fw-medium text-dark fs-14 mb-1">{{ translate('include_guest_carts') }}</h5>
                                        <p class="mb-0 fs-12">
                                            {{ translate('guest_reminders_use_the_email_typed_at_checkout_enable_only_if_your_policy_allows_it') }}
                                        </p>
                                    </span>
                                    <span class="switcher">
                                        <input type="checkbox" class="switcher_input" id="abandoned-cart-guests"
                                               name="include_guests" value="1" {{ $includeGuests ? 'checked' : '' }}>
                                        <span class="switcher_control"></span>
                                    </span>
                                </label>
                            </div>

                            <div class="col-xl-4 col-md-6">
                                <label class="form-label" for="idle-hours">
                                    {{ translate('idle_hours_before_a_cart_is_considered_abandoned') }}
                                </label>
                                <input type="number" class="form-control" id="idle-hours" name="idle_hours"
                                       min="1" max="720" required
                                       value="{{ old('idle_hours', $idleHours) }}">
                            </div>

                            <div class="col-xl-4 col-md-6">
                                <label class="form-label" for="max-age-hours">
                                    {{ translate('stop_reminding_after_hours') }}
                                </label>
                                <input type="number" class="form-control" id="max-age-hours" name="max_age_hours"
                                       min="2" max="8760" required
                                       value="{{ old('max_age_hours', $maxAgeHours) }}">
                            </div>

                            <div class="col-xl-4 col-md-6">
                                <label class="form-label" for="minimum-cart-value">
                                    {{ translate('minimum_cart_value_to_send_a_reminder') }}
                                </label>
                                <input type="number" step="0.01" min="0" class="form-control"
                                       id="minimum-cart-value" name="minimum_cart_value" required
                                       value="{{ old('minimum_cart_value', $minimumValue) }}">
                            </div>
                        </div>

                        @if (!is_null($pending))
                            <p class="mb-0 mt-3 fs-12">
                                {{ translate('carts_matching_these_thresholds_right_now') }}:
                                <strong>{{ number_format($pending) }}</strong>
                            </p>
                        @endif
                    </div>

                    <div class="p-12 p-sm-20 mt-3 bg-section rounded">
                        <h5 class="fw-medium text-dark fs-14 mb-2">{{ translate('test_without_sending') }}</h5>
                        <div class="overflow-x-auto">
                            <pre class="mb-2 fs-12"><code>php artisan cart:remind-abandoned --dry-run</code></pre>
                        </div>
                        <h5 class="fw-medium text-dark fs-14 mb-2">{{ translate('run_it_from_cron') }}</h5>
                        <div class="overflow-x-auto">
                            <pre class="mb-0 fs-12"><code>0 * * * * cd {{ base_path() }} &amp;&amp; php artisan cart:remind-abandoned</code></pre>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-3 mt-3">
                        <button type="reset" class="btn btn-secondary">{{ translate('reset') }}</button>
                        <button type="submit" class="btn btn-primary">{{ translate('save') }}</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection
