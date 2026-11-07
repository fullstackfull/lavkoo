<?php

namespace App\Services\OperationalCosts\Invoices;

use App\Models\OperationalCostInvoice;

/** What creating an invoice came to: the invoice (new or the one it duplicates) and anything a person should look at. */
final readonly class InvoiceResult
{
    public const CREATED = 'created';
    /** An invoice with this company and number already exists: nothing was written. */
    public const DUPLICATE = 'duplicate';
    /** Written, but it looks like another invoice and waits for a person. */
    public const POSSIBLE_DUPLICATE = 'possible_duplicate';
    /** Written, but a line looks like goods a purchase order already received and waits for a person to link it or say it is a separate purchase. */
    public const RESEMBLES_RECEIPT = 'resembles_receipt';

    /** @param list<string> $warnings */
    public function __construct(
        public string $result,
        public OperationalCostInvoice $invoice,
        public array $warnings = [],
    ) {
    }
}
