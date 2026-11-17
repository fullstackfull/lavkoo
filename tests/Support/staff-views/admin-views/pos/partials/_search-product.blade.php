{{-- Test-only stand-in: the products a POS search offers. --}}
@foreach ($products as $product)[product:{{ $product->id }}]@endforeach
