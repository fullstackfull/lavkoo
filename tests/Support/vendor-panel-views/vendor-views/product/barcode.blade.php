{{-- Test-only stand-in for the vendor barcode sheet, so the handler's own decision can be seen without the sheet's currency and barcode renderer. --}}
barcode-sheet:{{ $product['name'] }}:{{ $product['code'] }}:{{ count($barcodes, COUNT_RECURSIVE) }}
