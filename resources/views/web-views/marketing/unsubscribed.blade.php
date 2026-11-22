{{--
    The page an unsubscribe link lands on.

    Standalone, like the e-mail templates, and for the same reason: it is opened by somebody who is not signed in, from
    a mail client, and it must not depend on a theme's layout, its session or its navigation. It alters no existing
    layout, and both themes reach it unchanged.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ session('direction') === 'rtl' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    {{-- The URL of this page IS a token, and the signature deliberately never expires, so it must not be handed to
         anything the visitor clicks through to and must not reach a web-server log of another host. --}}
    <meta name="referrer" content="no-referrer">
    <title>{{ translate('you_have_been_unsubscribed') }}</title>
    <style>
        body { margin: 0; padding: 32px 16px; background: #f4f6f8; color: #1f2937; font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
        .card { max-width: 520px; margin: 0 auto; padding: 32px 24px; background: #ffffff; border-radius: 12px; text-align: center; }
        h1 { margin: 0 0 12px; font-size: 20px; }
        p { margin: 0 0 8px; font-size: 14px; line-height: 1.6; color: #4b5563; }
        a { color: #0d6efd; }
    </style>
</head>
<body>
<div class="card">
    <h1>{{ translate('you_have_been_unsubscribed') }}</h1>
    <p>{{ translate('you_will_no_longer_receive_marketing_messages_of_this_kind') }}</p>
    <p>{{ translate('messages_about_your_orders_payments_and_account_security_are_not_affected') }}</p>
    <p><a href="{{ route('home') }}">{{ translate('return_to_the_store') }}</a></p>
</div>
</body>
</html>
