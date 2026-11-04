<!DOCTYPE html>
<?php
$companyName = getWebConfig(name: 'company_name');
$companyLogo = getWebConfig(name: 'company_web_logo');
$companyEmail = getWebConfig(name: 'company_email');
$lang = \App\Utils\Helpers::default_lang();
// The direction comes from the store's own language configuration, never from a guessed list of
// language codes: this store's Arabic is registered under the code "sy", so `in_array($lang,
// ['ar', ...])` would have laid the whole message out left-to-right. Read from settings rather
// than the session because this renders from a console command, where there is no request.
$languages = getWebConfig(name: 'language') ?? [];
$direction = collect(is_array($languages) ? $languages : [])
    ->firstWhere('code', $lang)['direction'] ?? 'ltr';
$direction = strtolower($direction) === 'rtl' ? 'rtl' : 'ltr';
$align = $direction === 'rtl' ? 'right' : 'left';
$opposite = $direction === 'rtl' ? 'left' : 'right';
?>
<html lang="{{ $lang }}" dir="{{ $direction }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ translate('you_left_something_in_your_cart') }}</title>
    <style>
        body {
            margin: 0;
            padding: 24px 12px;
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            font-size: 14px;
            line-height: 22px;
            color: #737883;
            background: #f7fbff;
        }
        h1, h2, h3 { color: #334257; margin: 0 0 8px; }
        .wrap { max-width: 540px; margin: 0 auto; background: #fff; border-radius: 10px; padding: 28px 24px; }
        .brand { text-align: center; margin-bottom: 20px; }
        .brand img { max-height: 46px; }
        .items { width: 100%; border-collapse: collapse; margin: 18px 0; }
        .items td { padding: 10px 6px; border-bottom: 1px solid #eef2f7; vertical-align: middle; }
        .thumb { width: 56px; }
        .thumb img { width: 56px; height: 56px; object-fit: cover; border-radius: 6px; display: block; }
        .qty { color: #9aa4b2; white-space: nowrap; }
        .total td { font-weight: 700; color: #334257; border-bottom: none; padding-top: 14px; }
        .cta { display: inline-block; padding: 12px 28px; border-radius: 6px; background: #006161;
               color: #fff !important; text-decoration: none; font-weight: 600; }
        .cta-wrap { text-align: center; margin: 22px 0 8px; }
        .foot { text-align: center; color: #9aa4b2; font-size: 12px; margin-top: 22px; line-height: 20px; }
    </style>
</head>
<body>
<div class="wrap" style="text-align: {{ $align }};">
    <div class="brand">
        @if ($companyLogo)
            {{-- company_web_logo is an array ({key, path, status}), not a string. Concatenating it
                 into a path — as one of 6Valley's own templates does — is an "Array to string
                 conversion" that takes the whole message down. getStorageImages() reads the shape. --}}
            <img src="{{ getStorageImages(path: $companyLogo, type: 'backend-logo') }}" alt="{{ $companyName }}">
        @else
            <h2>{{ $companyName }}</h2>
        @endif
    </div>

    <h3>{{ translate('you_left_something_in_your_cart') }}</h3>
    <p>{{ translate('the_items_below_are_still_waiting_for_you') }}</p>

    @if ($lines->count())
        <table class="items">
            @foreach ($lines as $line)
                <tr>
                    <td class="thumb">
                        @if ($line->thumbnail)
                            <img src="{{ getStorageImages(path: $line->thumbnail, type: 'backend-product') }}"
                                 alt="{{ $line->name ?? translate('product') }}">
                        @endif
                    </td>
                    <td>{{ $line->name ?? optional($line->product)->name }}</td>
                    <td class="qty">&times; {{ (int) $line->quantity }}</td>
                    <td style="text-align: {{ $opposite }};">
                        {{ webCurrencyConverter(amount: ((float) $line->price - (float) $line->discount) * (int) $line->quantity) }}
                    </td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="3">{{ translate('total') }}</td>
                <td style="text-align: {{ $opposite }};">
                    {{ webCurrencyConverter(amount: $reminder->cart_value) }}
                </td>
            </tr>
        </table>
    @endif

    <div class="cta-wrap">
        <a class="cta" href="{{ $recoveryUrl }}">{{ translate('return_to_my_cart') }}</a>
    </div>

    <p style="font-size: 12px; color: #9aa4b2;">
        {{ translate('prices_and_availability_are_confirmed_at_checkout') }}
    </p>

    <div class="foot">
        {{ $companyName }}@if ($companyEmail) &middot; {{ $companyEmail }} @endif<br>
        {{ translate('you_are_receiving_this_because_you_left_items_in_your_cart_on_our_store') }}
    </div>
</div>
</body>
</html>
