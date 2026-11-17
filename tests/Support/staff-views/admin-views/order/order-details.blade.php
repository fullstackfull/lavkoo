{{-- Test-only stand-in: the order, its neighbours and the orders of its group and of its customer that the screen shows. --}}
[order:{{ $order->id }}][previous:{{ $previousOrder?->id }}][next:{{ $nextOrder?->id }}][linked:{{ $linkedOrders->pluck('id')->implode(',') }}][customer-orders:{{ $orderCount }}]
