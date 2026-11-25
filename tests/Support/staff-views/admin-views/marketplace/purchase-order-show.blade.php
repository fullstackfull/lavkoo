{{-- Test-only stand-in: the lines of one purchase order. --}}
[po:{{ $order->id }}]@foreach ($order->items as $item)[line:{{ $item->id }} product:{{ $item->product_id ?? 'none' }}]@endforeach
