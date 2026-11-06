<?php

namespace App\Services\SalesChannels\Accounting;

use LogicException;

/** The destination every account has until an accounting integration is built: it posts nothing and says so. */
final class NullAccountingDestination implements AccountingDestination
{
    public function code(): string
    {
        return 'none';
    }

    public function available(): bool
    {
        return false;
    }

    public function post(array $lines): array
    {
        throw new LogicException('No accounting destination is configured; nothing was posted.');
    }
}
