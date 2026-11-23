<?php

namespace App\Services\Catalog;

use App\Models\Product;

/**
 * What a copy into another country actually carried (GCC-14b slice 3, B-07).
 *
 * A copy drops whatever would name something that is already named in the target country — a code, a barcode, a variant SKU — and whatever it
 * could not read, which is an image file. Each of those is a thing the admin has to type again, so the action answers with the list rather than
 * leaving it to be discovered on the edit form: the panel turns it into one warning beside the "set the price" message, and the audit line keeps
 * the same facts for whoever reads the record later.
 */
final readonly class ProductCopy
{
    /**
     * @param list<string> $variantSkusNotKept the SKUs a product of the target country already holds, blanked on the copy
     * @param list<string> $imagesNotCopied the image columns whose source file could not be read
     */
    public function __construct(
        public Product $product,
        public bool $codeKept,
        public bool $barcodeKept,
        public array $variantSkusNotKept = [],
        public array $imagesNotCopied = [],
    ) {
    }

    public function carriedEverythingItCould(): bool
    {
        return $this->variantSkusNotKept === [] && $this->imagesNotCopied === [];
    }
}
