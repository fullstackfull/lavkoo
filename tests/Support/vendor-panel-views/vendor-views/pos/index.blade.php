{{-- Test-only stand-in for the vendor point of sale page, so the shop the handler hands to the page (its receipt prints the shop's name and address) can be seen without the page's catalogue, customers and shell data. --}}
pos-page-shop:{{ $shop?->id }}:{{ $shop?->name }}:{{ $shop?->address }}
