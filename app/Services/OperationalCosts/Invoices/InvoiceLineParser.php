<?php

namespace App\Services\OperationalCosts\Invoices;

use App\Enums\OperationalCosts\CostComponent;

/**
 * Reads invoice lines pasted from a spreadsheet: one line per item, `reference;date;component;amount;currency;quantity`.
 *
 * The cells may be separated by a semicolon or by a tab (which is what copying cells out of Excel produces). The reference, date, currency and quantity may
 * be empty; the component and the amount may not. An amount is a plain decimal, a credit is negative. A heading row is skipped.
 *
 * It reads and checks; it does not decide anything about the invoice (the currency of a line, whether the invoice adds up) - InvoiceService does.
 */
class InvoiceLineParser
{
    public const MAX_LINES = 500;

    private const COLUMNS = ['external_reference', 'service_date', 'cost_component', 'amount', 'currency', 'quantity'];

    private const REQUIRED_COLUMNS = 4;

    private const AMOUNT_PATTERN = '/^-?\d{1,14}(\.\d{1,6})?$/';

    private const QUANTITY_PATTERN = '/^\d{1,12}(\.\d{1,6})?$/';

    private const CURRENCY_PATTERN = '/^[A-Za-z]{3,8}$/';

    public function parse(string $text): ParsedInvoiceLines
    {
        $items = [];
        $errors = [];
        $seenFirstLine = false;

        foreach (preg_split('/\R/u', $text) ?: [] as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $number = $index + 1;
            $cells = array_map('trim', str_contains($line, "\t") ? explode("\t", $line) : explode(';', $line));
            $isFirstLine = !$seenFirstLine;
            $seenFirstLine = true;

            if ($isFirstLine && $this->isHeading($cells)) {
                continue;
            }
            if (count($items) + count($errors) >= self::MAX_LINES) {
                $errors[] = ['line' => $number, 'code' => 'too_many_lines'];
                break;
            }

            $code = $this->problem($cells);
            if ($code !== null) {
                $errors[] = ['line' => $number, 'code' => $code];

                continue;
            }
            $items[] = $this->item($cells);
        }

        return new ParsedInvoiceLines($items, $errors);
    }

    /** A first line whose component and amount cells are not what a line holds is the column titles. */
    private function isHeading(array $cells): bool
    {
        return count($cells) >= self::REQUIRED_COLUMNS
            && CostComponent::tryFrom(strtolower($cells[2])) === null
            && preg_match(self::AMOUNT_PATTERN, $cells[3]) !== 1;
    }

    /** @param list<string> $cells */
    private function problem(array $cells): ?string
    {
        return match (true) {
            count($cells) < self::REQUIRED_COLUMNS => 'too_few_columns',
            count($cells) > count(self::COLUMNS) => 'too_many_columns',
            !$this->isDate($cells[1]) => 'invalid_date',
            CostComponent::tryFrom(strtolower($cells[2])) === null => 'unknown_component',
            preg_match(self::AMOUNT_PATTERN, $cells[3]) !== 1 => 'invalid_amount',
            ($cells[4] ?? '') !== '' && preg_match(self::CURRENCY_PATTERN, $cells[4]) !== 1 => 'invalid_currency',
            ($cells[5] ?? '') !== '' && preg_match(self::QUANTITY_PATTERN, $cells[5]) !== 1 => 'invalid_quantity',
            default => null,
        };
    }

    private function isDate(string $cell): bool
    {
        if ($cell === '') {
            return true;
        }

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $cell, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /**
     * @param list<string> $cells
     * @return array<string, string|null>
     */
    private function item(array $cells): array
    {
        $cells = array_pad($cells, count(self::COLUMNS), '');
        $item = array_combine(self::COLUMNS, $cells);
        $item['cost_component'] = strtolower($item['cost_component']);
        $item['currency'] = strtoupper($item['currency']);

        return array_map(static fn (string $cell): ?string => $cell === '' ? null : $cell, $item);
    }
}
