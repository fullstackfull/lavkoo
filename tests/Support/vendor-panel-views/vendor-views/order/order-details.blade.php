{{-- Test-only stand-in for the vendor order page, so the drivers the handler offers for the order can be seen without the page's tables, scripts and shell data. The total is read from the edit summary the way the page's edit offcanvas reads it, with no default: a summary without it crashes this page as it crashes the real one. --}}
order-page:{{ $order['id'] }}
drivers:{{ collect($deliveryMen)->pluck('f_name')->implode(',') }}
total:{{ $editOrderSummary['order_amount'] }}
