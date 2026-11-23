{{-- Test-only stand-in for the products picked for the clearance sale, so the names the handler lists can be read without the card's currency and image data. --}}
@foreach ($selectedProducts as $product)selected-product:{{ $product['name'] }};@endforeach
