{{-- Test-only stand-in: the products the SEO audit read. --}}
@foreach ($products as $product)[product:{{ $product->id }}]@endforeach
