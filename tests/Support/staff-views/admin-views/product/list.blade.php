{{-- Test-only stand-in: the ids of the products the screen lists, so a test reads what the controller gave the page and not the markup of the shell. --}}
@foreach ($products as $product)[product:{{ $product->id }}]@endforeach
