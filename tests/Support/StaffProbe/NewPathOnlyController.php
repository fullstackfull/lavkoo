<?php

namespace Tests\Support\StaffProbe;

/** An action that names a product only in its path: the scan reads the path too. */
class NewPathOnlyController
{
    public function show(string $productId): string
    {
        return $productId;
    }

    public function harmless(): string
    {
        return 'nothing here';
    }
}
