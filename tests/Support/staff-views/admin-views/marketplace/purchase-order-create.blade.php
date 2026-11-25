{{-- Test-only stand-in: the suppliers the create form offers. --}}
@foreach ($suppliers as $supplier)[supplier:{{ $supplier->id }}]@endforeach
