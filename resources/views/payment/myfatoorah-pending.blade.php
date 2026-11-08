{{--
    "We are confirming your payment."

    Standalone rather than extending a storefront theme layout: this page is reached on the way back from
    an external payment page, sometimes in a different browser from the one that started the checkout, and
    it must render even if a theme is mid-switch or a session has gone. It has no dependency beyond the
    translate() helper.

    It reloads itself a few times and then stops. Reloading forever would hammer MyFatoorah through the
    verification on each load, and a payment that has not resolved in a minute needs the webhook or the
    reconciliation sweep, not a browser.
--}}
<!DOCTYPE html>
<html lang="{{ str_starts_with(app()->getLocale(), 'ar') ? 'ar' : 'en' }}"
      dir="{{ str_starts_with(app()->getLocale(), 'ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ translate('confirming_your_payment') }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 24px; background: #f6f7fb; color: #1b1f2a;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Tahoma, Arial, sans-serif;
        }
        .card {
            background: #fff; border-radius: 16px; padding: 32px 28px; max-width: 420px; width: 100%;
            text-align: center; box-shadow: 0 10px 30px rgba(17, 24, 39, .08);
        }
        .spinner {
            width: 44px; height: 44px; margin: 0 auto 20px; border-radius: 50%;
            border: 4px solid #e5e7eb; border-top-color: #2563eb; animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) { .spinner { animation: none; } }
        h1 { font-size: 20px; margin: 0 0 12px; }
        p { font-size: 14px; line-height: 1.6; color: #4b5563; margin: 0 0 10px; }
        .amount { font-size: 18px; font-weight: 700; margin: 16px 0; }
        .ref { font-size: 12px; color: #6b7280; word-break: break-all; margin-top: 18px; }
        .btn {
            display: inline-block; margin-top: 18px; padding: 10px 20px; border-radius: 10px;
            background: #2563eb; color: #fff; text-decoration: none; font-size: 14px; font-weight: 600;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #0f1117; color: #e5e7eb; }
            .card { background: #171a22; box-shadow: none; }
            p { color: #9ca3af; }
            .spinner { border-color: #2b2f3a; border-top-color: #60a5fa; }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="spinner" role="status" aria-live="polite"></div>

        <h1>{{ translate('we_are_confirming_your_payment') }}</h1>

        <p>
            {{ translate('your_bank_or_wallet_has_not_finished_telling_us_the_result_yet_this_page_will_update_by_itself') }}.
        </p>
        <p>
            <strong>{{ translate('please_do_not_pay_again') }}.</strong>
            {{ translate('if_the_money_has_left_your_account_your_order_will_be_created_automatically') }}.
        </p>

        <div class="amount">{{ $amount }} {{ $currency }}</div>

        <a class="btn" href="{{ $retryUrl }}">{{ translate('check_again') }}</a>

        <div class="ref">{{ translate('reference') }}: {{ $reference }}</div>
    </div>

    <script>
        'use strict';
        // Five automatic checks, then stop and leave it to the button. Each reload re-verifies with
        // MyFatoorah, so an unbounded loop would be a self-inflicted rate limit.
        (function () {
            var key = 'mf-pending-' + @json($reference);
            var tries = 0;
            try { tries = parseInt(sessionStorage.getItem(key) || '0', 10) || 0; } catch (e) { tries = 0; }

            if (tries < 5) {
                try { sessionStorage.setItem(key, String(tries + 1)); } catch (e) {}
                setTimeout(function () { window.location.replace(@json($retryUrl)); }, 6000);
            }
        })();
    </script>
</body>
</html>
