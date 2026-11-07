<?php

namespace App\Services\OperationalCosts\Invoices;

/**
 * What a pasted block of invoice lines came to: the lines that were understood, in the shape InvoiceService::create() takes, and for each line that was not,
 * its number and why. Nothing is guessed: a line that cannot be read is reported, never half-kept.
 */
final readonly class ParsedInvoiceLines
{
    /**
     * @param list<array<string, string|null>> $items
     * @param list<array{line: int, code: string}> $errors
     */
    public function __construct(public array $items, public array $errors)
    {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
