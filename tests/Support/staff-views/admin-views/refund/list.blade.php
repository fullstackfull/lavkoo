{{-- Test-only stand-in: the refund requests the screen lists, each with the order it asks about. --}}
@foreach ($refundList as $refund)[refund:{{ $refund->id }} order:{{ $refund->order?->id }}]@endforeach
