{{-- Test-only stand-in: the products the banner edit form's picker offers. --}}
@foreach ($products as $product)[product:{{ $product->id }}]@endforeach
