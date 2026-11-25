{{-- Test-only stand-in: the warehouses the screen lists and the per-shelf breakdown of the product it was asked about. --}}
@foreach ($warehouses as $warehouse)[warehouse:{{ $warehouse->id }}]@endforeach
@foreach (($breakdown['by_warehouse'] ?? []) as $warehouseId => $quantity)[shelf:{{ $warehouseId }} qty:{{ $quantity }}]@endforeach
@if ($product)[product:{{ $product->id }}]@endif
