{{-- Test-only stand-in: the brand claims the queue lists. --}}
@foreach ($claims as $claim)[claim:{{ $claim->id }} brand:{{ $claim->brand_id }}]@endforeach
