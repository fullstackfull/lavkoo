@php
    use App\Models\BusinessSetting;

    // Scheduler heartbeat: the bootstrap/app.php schedule writes scheduler_last_run_at every 5 min.
    // If it is missing or older than ~15 min, the server cron `schedule:run` is not firing — which
    // silently stops settlement maturation, payouts and reminder emails.
    $lastRun = BusinessSetting::where('type', 'scheduler_last_run_at')->value('value');
    $schedulerStale = !$lastRun || \Carbon\Carbon::parse($lastRun)->lt(now()->subMinutes(15));

    /*
     * Static-OTP guard: outside APP_MODE=live the app accepts fixed OTP codes (123456 / 1234).
     *
     * Read through config(), not env(). Laravel stops loading the .env file once config:cache has
     * run — which is the documented production step — so env() answers null there and a correctly
     * configured live shop showed this warning permanently, which is how a real warning gets
     * ignored.
     */
    $appMode = config('app.mode', config('app.mode'));
    $otpUnsafe = $appMode !== 'live';
@endphp

@if ($schedulerStale || $otpUnsafe)
    <div class="mb-3 d-flex flex-column gap-2">
        @if ($schedulerStale)
            <div class="alert alert-danger d-flex align-items-start gap-2 mb-0" role="alert">
                <i class="tio-warning mt-1"></i>
                <div>
                    <strong>{{ translate('scheduler_is_not_running') }}.</strong>
                    {{ translate('vendor_earnings_will_not_mature_and_no_settlement_or_reminder_will_run_until_the_server_cron_is_installed') }}.
                    <span class="d-block mt-1 fs-12 opacity-75">
                        {{ translate('add_this_cron') }}: <code>* * * * * cd {{ base_path() }} &amp;&amp; php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</code>
                        @if ($lastRun) · {{ translate('last_run') }}: {{ $lastRun }} @endif
                    </span>
                </div>
            </div>
        @endif
        @if ($otpUnsafe)
            <div class="alert alert-warning d-flex align-items-start gap-2 mb-0" role="alert">
                <i class="tio-shield-outlined mt-1"></i>
                <div>
                    <strong>{{ translate('insecure_APP_MODE') }} ({{ $appMode ?: 'unset' }}).</strong>
                    {{ translate('OTP_and_password-reset_codes_are_fixed_test_values_until_APP_MODE_is_set_to_live_in_the_env_file') }}.
                </div>
            </div>
        @endif
    </div>
@endif
