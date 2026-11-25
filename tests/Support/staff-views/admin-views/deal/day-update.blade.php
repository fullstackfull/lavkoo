{{-- Test-only stand-in: the deal-of-the-day row the edit form opened, and the products its picker offers. --}}
[day:{{ $deal->id }} product:{{ $deal->product_id }}]
@foreach ($products as $product)[product:{{ $product->id }}]@endforeach
