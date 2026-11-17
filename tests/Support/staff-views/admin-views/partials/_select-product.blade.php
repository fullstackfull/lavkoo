{{-- Test-only stand-in: the products a deal page shows as selected. --}}
@foreach ($selectedProducts as $product)[product:{{ $product->id }}]@endforeach
