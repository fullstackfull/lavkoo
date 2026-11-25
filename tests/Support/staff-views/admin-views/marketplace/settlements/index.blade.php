{{-- Test-only stand-in: the settlements the screen lists. --}}
@foreach ($settlements as $settlement)[settlement:{{ $settlement->id }} seller:{{ $settlement->seller_id }}]@endforeach
