{{-- Test-only stand-in for the digital variations the product form lists, so the file names the handler fills in can be read. --}}
digital-combinations:@foreach ($generateCombination as $combination){{ $combination['variant_key'] }}/{{ $combination['sku'] }}/{{ $combination['file'] }};@endforeach
