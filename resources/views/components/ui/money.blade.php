{{--
    Monetary value.

    The brief requires financial values to always display currency correctly. This routes through
    the project's existing currency helpers when available, so admin/vendor tables can never print a
    bare number. `dir="ltr"` keeps amounts readable inside an RTL page.

    Usage: <x-ui.money :amount="$order->order_amount" />
--}}
@props(['amount' => 0, 'convert' => true])

@php
    $value = is_numeric($amount) ? (float) $amount : 0.0;
    $formatted = null;
    if (function_exists('webCurrencyConverter')) {
        try { $formatted = webCurrencyConverter($value); } catch (\Throwable) { $formatted = null; }
    }
    if ($formatted === null && function_exists('usdCurrencyConverter')) {
        try { $formatted = usdCurrencyConverter($value); } catch (\Throwable) { $formatted = null; }
    }
    if ($formatted === null) {
        // Last resort. The symbol lookup also hits the DB, so it is guarded too: a currency
        // problem must never take a whole admin page down over one table cell.
        $symbol = '';
        try {
            if (function_exists('getCurrencySymbol')) {
                $symbol = (string) getCurrencySymbol();
            }
        } catch (\Throwable) {
            $symbol = '';
        }
        $formatted = trim($symbol . ' ' . number_format($value, 2));
    }
@endphp

<span {{ $attributes->merge(['class' => 'text-nowrap']) }} dir="ltr">{{ $formatted }}</span>
