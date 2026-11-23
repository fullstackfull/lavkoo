<?php

namespace App\Services\Catalog;

use App\Services\Storefront\StorefrontCatalog;
use RuntimeException;

/** The live catalogue holds a code or a barcode twice inside one country, so the unique index cannot be created over it. */
final class ProductCodeIndexBlocked extends RuntimeException
{
    public function __construct(public readonly string $kind, public readonly int $groups)
    {
        parent::__construct(sprintf(
            '%d %s(s) are held more than once inside one country, so the unique index on (%s, %s) cannot be created. '
            . 'Run `php artisan gcc:catalog:code-readiness` to list them. No product row has been changed: which of the duplicates to keep is the owner\'s decision.',
            $groups,
            $kind,
            StorefrontCatalog::COLUMN,
            $kind,
        ));
    }
}
