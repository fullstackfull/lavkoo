{{-- Test-only stand-in: the audit lines the screen lists, and the modules its filter offers. --}}
@foreach ($entries as $entry)[audit:{{ $entry->id }} {{ $entry->storefront_country ?? 'none' }}]@endforeach
@foreach ($modules as $module)[module:{{ $module }}]@endforeach
