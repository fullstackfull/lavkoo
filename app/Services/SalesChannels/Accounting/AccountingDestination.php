<?php

namespace App\Services\SalesChannels\Accounting;

/**
 * Where the application's books may be sent for accounting (Odoo is the intended destination; nothing is built beyond this contract yet).
 *
 * An account names its destination in `sales_channel_accounts.accounting_destination`. The default is none: a destination is a WRITE to an external system, so a real one will
 * be reached through the same switches as every other write (environment, account, recorded approval) and never posts a line it has posted before.
 */
interface AccountingDestination
{
    /** `none` for the default, `odoo` for the future one. */
    public function code(): string;

    /** Whether lines can be posted at all. */
    public function available(): bool;

    /**
     * @param list<AccountingJournalLine> $lines
     * @return array<string, string> the destination's own reference for each posted line, keyed by the line's `reference`
     */
    public function post(array $lines): array;
}
