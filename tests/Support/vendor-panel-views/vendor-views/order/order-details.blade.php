{{-- Test-only stand-in for the vendor order page, so the drivers the handler offers for the order can be seen without the page's tables, scripts and shell data. --}}
order-page:{{ $order['id'] }}
drivers:{{ collect($deliveryMen)->pluck('f_name')->implode(',') }}
