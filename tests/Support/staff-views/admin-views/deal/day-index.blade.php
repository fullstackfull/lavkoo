{{-- Test-only stand-in: the deal-of-the-day rows the screen lists, and the products its picker offers. --}}
@foreach ($deals as $deal)[day:{{ $deal->id }} product:{{ $deal->product_id }}]@endforeach
@foreach ($products as $product)[product:{{ $product->id }}]@endforeach
