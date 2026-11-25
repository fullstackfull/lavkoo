{{-- Test-only stand-in: the purchase orders the screen lists. --}}
@foreach ($orders as $order)[po:{{ $order->id }}]@endforeach
