{{-- Test-only stand-in: the products the clearance search offers. --}}
@foreach ($products as $product)[product:{{ $product->id }}]@endforeach
