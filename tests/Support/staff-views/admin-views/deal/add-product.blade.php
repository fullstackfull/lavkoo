{{-- Test-only stand-in: the products the picker offers, and the ones the deal already holds. --}}
@foreach ($products as $product)[product:{{ $product->id }}]@endforeach
@foreach ($dealProducts as $dealProduct)[in-deal:{{ $dealProduct->id }}]@endforeach
