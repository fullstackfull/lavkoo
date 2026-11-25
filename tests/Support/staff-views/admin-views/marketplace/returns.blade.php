{{-- Test-only stand-in: the returns the queue lists. --}}
@foreach ($returns as $rma)[return:{{ $rma->id }} order:{{ $rma->order_id }} product:{{ $rma->product_id }}]@endforeach
