{{-- Test-only stand-in for the combinations of a product that does not exist yet (the add form). --}}
new-combinations:@foreach ($combinations as $combination){{ $combination['sku'] }}/{{ $combination['qty'] }}/{{ $combination['price'] }};@endforeach
