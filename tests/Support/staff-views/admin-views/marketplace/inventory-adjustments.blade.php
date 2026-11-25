{{-- Test-only stand-in: the stock movements the screen lists. --}}
@foreach ($movements ?? [] as $movement)[movement:{{ $movement->id }} product:{{ $movement->product_id }}]@endforeach
