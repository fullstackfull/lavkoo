{{-- Test-only stand-in: each deal and how many products the screen says it holds, so a test reads the count the controller computed. --}}
@foreach ($flashDeals as $deal)[deal:{{ $deal->id }} products:{{ $deal->products_count }}]@endforeach
