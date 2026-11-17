{{-- Test-only stand-in for the combinations of a product that exists, so the values the handler fills in can be read without the form's currency data. --}}
edit-combinations:@foreach ($combinations as $combination){{ $combination['sku'] }}/{{ $combination['qty'] }}/{{ $combination['price'] }};@endforeach
