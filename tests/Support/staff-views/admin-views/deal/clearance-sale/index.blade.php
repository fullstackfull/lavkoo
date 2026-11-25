{{-- Test-only stand-in: the products the clearance picker offers, and the offers the screen lists. --}}
@foreach ($products as $product)[product:{{ $product->id }}]@endforeach
@foreach ($stockClearanceProduct as $offer)[offer:{{ $offer->id }} product:{{ $offer->product_id }}]@endforeach
