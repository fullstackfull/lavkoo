{{-- Test-only stand-in: the fulfilments the queue lists. --}}
@foreach ($fulfillments as $fulfillment)[fulfillment:{{ $fulfillment->id }} order:{{ $fulfillment->order_id }}]@endforeach
