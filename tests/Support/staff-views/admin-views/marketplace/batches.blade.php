{{-- Test-only stand-in: the batches the screen lists and the two counters above them. --}}
@foreach ($batches as $batch)[batch:{{ $batch->id }} product:{{ $batch->product_id }}]@endforeach
[expiring:{{ $expiringCount }}][expired:{{ $expiredCount }}]
