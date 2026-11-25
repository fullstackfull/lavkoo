{{-- Test-only stand-in: one settlement and its entries. --}}
[settlement:{{ $settlement->id }}]@foreach ($entries as $entry)[entry:{{ $entry->id }}]@endforeach
