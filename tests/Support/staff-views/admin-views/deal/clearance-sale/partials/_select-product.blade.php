{{-- Test-only stand-in: the products the clearance screen was asked the details of. --}}
@foreach ($selectedProducts as $product)[product:{{ $product->id }}]@endforeach
