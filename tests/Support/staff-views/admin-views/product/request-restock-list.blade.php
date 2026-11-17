{{-- Test-only stand-in: the restock requests listed, each with the product it asks for. --}}
@foreach ($restockProducts as $restock)[restock:{{ $restock->id }} product:{{ $restock->product?->id }}]@endforeach
[total:{{ $totalRestockProducts }}]
