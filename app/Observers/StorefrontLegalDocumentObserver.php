<?php

namespace App\Observers;

use App\Models\StorefrontLegalDocument;
use App\Services\Storefront\StorefrontLegalPages;

/**
 * The business-page list of a storefront is built from its legal texts, so a text that is saved, published, revoked or deleted through the
 * model must not leave the old list in the cache. A write through the query builder raises no event and has to clear the lists itself.
 */
class StorefrontLegalDocumentObserver
{
    public function __construct(private readonly StorefrontLegalPages $storefrontLegalPages)
    {
    }

    public function saved(StorefrontLegalDocument $document): void
    {
        $this->storefrontLegalPages->forgetStorefrontLists();
    }

    public function deleted(StorefrontLegalDocument $document): void
    {
        $this->storefrontLegalPages->forgetStorefrontLists();
    }
}
