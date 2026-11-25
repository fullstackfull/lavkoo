{{-- Test-only stand-in: the banners the screen lists and the products its picker offers. --}}
@foreach ($banners as $banner)[banner:{{ $banner->id }}]@endforeach
@foreach ($products as $product)[product:{{ $product->id }}]@endforeach
