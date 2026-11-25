{{-- Test-only stand-in: the reviews the list shows, and the shops of the vendor picker with their review counts. --}}
@foreach ($reviews as $review)[review:{{ $review->id }} product:{{ $review->product_id }}]@endforeach
