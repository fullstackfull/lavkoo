<?php

namespace App\Services\OperationalCosts\Reports;

use App\Services\OperationalCosts\Support\Money;
use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Writes a report as CSV that Excel opens correctly and that cannot run anything on the machine of whoever opens it.
 *
 *  - A UTF-8 byte order mark comes first, so Excel reads Arabic as Arabic instead of guessing a legacy code page.
 *  - A text cell that a spreadsheet would read as a formula (it starts with = + - @, a tab or a carriage return) is prefixed with a quote and stays text.
 *  - An amount is written as the exact decimal it is (a Money), never through a float. It cannot start a formula either: a negative amount is a number, not a command.
 */
final class CsvExporter
{
    public const BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param resource $stream
     * @param list<string> $headings
     * @param iterable<list<mixed>> $rows
     * @return int the number of data rows written
     */
    public function write($stream, array $headings, iterable $rows): int
    {
        fwrite($stream, self::BYTE_ORDER_MARK);
        $this->put($stream, $headings);

        $written = 0;
        foreach ($rows as $row) {
            $this->put($stream, $row);
            $written++;
        }

        return $written;
    }

    public function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof Money => $value->toStorage(),
            is_int($value) => (string) $value,
            is_bool($value) => $value ? '1' : '0',
            is_float($value) => throw new InvalidArgumentException('A report never carries a float: pass an amount as a Money.'),
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof BackedEnum => $this->text((string) $value->value),
            default => $this->text((string) $value),
        };
    }

    private function text(string $text): string
    {
        return $text !== '' && in_array($text[0], self::FORMULA_STARTS, true) ? "'" . $text : $text;
    }

    /**
     * @param resource $stream
     * @param list<mixed> $row
     */
    private function put($stream, array $row): void
    {
        fputcsv($stream, array_map($this->cell(...), array_values($row)), ',', '"', '', "\r\n");
    }
}
