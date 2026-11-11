<?php

namespace App\Services\SalesChannels\Amazon;

/**
 * A tab-delimited Amazon report document, read by its OWN header row.
 *
 * **Amazon does not document the header.** Both report pages give their column lists in a collapsible "list of report
 * attributes" block, and neither says that list is the file's header row, gives the order, or even says a header row is
 * present. So the names below are what we EXPECT; the file decides. A column we cannot find is reported by name, and a header
 * we cannot recognise at all is a refusal — never an empty result, because an empty parse of a listings report reads as "this
 * seller has no listings", which is the same shape of mistake as reading UNKNOWN as ABSENT.
 *
 * ## Three hazards, every one of them documented by Amazon and every one a silent wrong answer
 *
 *  1. **A UTF-8 BOM.** "This report includes a UTF-8 Byte Order Mark (BOM) as the first 3 bytes. Ensure that you account for
 *     the BOM when you consume the report." It rides on the FIRST header name, so `item-name` arrives as
 *     `\xEF\xBB\xBFitem-name` and every lookup of the first column misses — quietly, since a missing column reads as an empty
 *     cell.
 *  2. **Headers in another language.** "Reports are not cached by locale. Requesting the same report within the cache period
 *     (between one and six hours depending on the number of entries) may return headers in a different locale than
 *     requested." A translated header matches nothing, so this is exactly the case that must refuse rather than return no rows.
 *  3. **Money in local notation.** "amounts are displayed in local currency formats (for example, `95,00` for EU instead of
 *     `95.00`)." `(float) '1.234,56'` is `1.234` in PHP: a figure wrong by three orders of magnitude, in somebody's accounts,
 *     with nothing raised. `amount()` is the only way a cell becomes a number here, and it refuses what it cannot read rather
 *     than casting it.
 *
 * It holds the whole document in memory, as the HTTP client hands it over. `AmazonReports` caps the size before this is
 * reached, so the cap rather than this class is what protects the process.
 */
final readonly class AmazonFlatFile
{
    /** @var list<string> the header, in file order, as the file spells it (BOM stripped, trimmed, lowercased) */
    public array $header;

    /** @var list<array<string, string>> every data row, keyed by the file's own header names */
    public array $rows;

    /**
     * @param list<string> $header
     * @param list<array<string, string>> $rows
     * @param string|null $refusal why the document could not be read, where it could not
     */
    private function __construct(array $header, array $rows, public ?string $refusal = null)
    {
        $this->header = $header;
        $this->rows = $rows;
    }

    /**
     * Reads a document, checking that it carries at least the columns the caller cannot work without.
     *
     * @param list<string> $required the columns this reader needs, in the names Amazon's documentation gives
     */
    public static function read(string $contents, array $required = []): self
    {
        // The BOM first, before anything looks at the first column name.
        $text = self::withoutBom($contents);
        if (trim($text) === '') {
            return new self([], [], 'amazon_report_document_is_empty');
        }

        $lines = preg_split('/\R/u', $text) ?: [];
        $headerLine = null;
        foreach ($lines as $position => $line) {
            if (trim($line) !== '') {
                $headerLine = $line;
                $lines = array_slice($lines, $position + 1);
                break;
            }
        }
        if ($headerLine === null) {
            return new self([], [], 'amazon_report_document_is_empty');
        }

        $header = array_map(
            static fn (string $cell): string => mb_strtolower(trim($cell, " \t\"'")),
            explode("\t", $headerLine),
        );
        if (count($header) < 2) {
            // One cell on the first line is not a tab-delimited header. Refusing beats reading the whole file as one column.
            return new self([], [], 'amazon_report_header_is_not_tab_delimited');
        }

        $missing = array_values(array_diff(array_map('mb_strtolower', $required), $header));
        if ($missing !== []) {
            // THE LOCALE CASE, and it must refuse. A translated header matches none of our names, so parsing on would hand back
            // rows whose every interesting cell was empty -- which is indistinguishable from a seller with nothing to report.
            return new self($header, [], 'amazon_report_columns_not_found:' . implode(',', $missing));
        }

        return new self($header, self::dataRows($lines, $header));
    }

    public function isReadable(): bool
    {
        return $this->refusal === null;
    }

    /** One cell, or null when the row does not carry that column at all (which is not the same as an empty cell). */
    public function cell(array $row, string $column): ?string
    {
        $key = mb_strtolower($column);

        return array_key_exists($key, $row) ? $row[$key] : null;
    }

    /**
     * A money cell as an exact decimal string, or null when it is empty — and a REFUSAL, never a guess, when it cannot be read.
     *
     * Amazon writes amounts in the marketplace's own notation, so both `1,234.56` and `1.234,56` arrive. They are told apart by
     * the LAST separator in the cell, which is the decimal one in either notation; a cell with one separator and exactly three
     * digits after it (`1,234`) is ambiguous on its face and is read as a thousands separator, because Amazon's own example of
     * a decimal comma is `95,00` — two digits. A cell that is neither shape returns null with the reason, and the caller names
     * the row rather than booking a number nobody can justify.
     *
     * @return array{amount: string|null, problem: string|null}
     */
    public static function amount(?string $cell): array
    {
        $text = trim((string) $cell);
        if ($text === '') {
            return ['amount' => null, 'problem' => null];
        }

        $negative = str_starts_with($text, '-') || (str_starts_with($text, '(') && str_ends_with($text, ')'));
        $digits = preg_replace('/[^0-9.,]/', '', $text) ?? '';
        if ($digits === '' || !preg_match('/\d/', $digits)) {
            return ['amount' => null, 'problem' => 'amazon_amount_has_no_digits'];
        }

        $lastComma = strrpos($digits, ',');
        $lastDot = strrpos($digits, '.');
        $decimalAt = match (true) {
            $lastComma === false && $lastDot === false => null,
            $lastComma === false => $lastDot,
            $lastDot === false => $lastComma,
            default => max($lastComma, $lastDot),
        };

        if ($decimalAt === null) {
            $whole = $digits;
            $fraction = '';
        } else {
            $fraction = substr($digits, $decimalAt + 1);
            // Three digits after the only separator is a thousands group, not a fraction: `1,234` is one thousand two hundred
            // and thirty-four everywhere Amazon sells.
            if (strlen($fraction) === 3 && substr_count($digits, ',') + substr_count($digits, '.') === 1) {
                $whole = $digits;
                $fraction = '';
            } else {
                $whole = substr($digits, 0, $decimalAt);
            }
        }

        $whole = preg_replace('/[.,]/', '', $whole) ?? '';
        if ($fraction !== '' && !preg_match('/^\d{1,6}$/', $fraction)) {
            return ['amount' => null, 'problem' => 'amazon_amount_not_understood'];
        }
        if ($whole === '' && $fraction === '') {
            return ['amount' => null, 'problem' => 'amazon_amount_not_understood'];
        }

        $value = ($whole === '' ? '0' : $whole) . ($fraction === '' ? '' : '.' . $fraction);

        return ['amount' => ($negative ? '-' : '') . $value, 'problem' => null];
    }

    /**
     * The rows, each keyed by the header.
     *
     * A row with fewer cells than the header keeps the columns it has; a row with more keeps the extras under their position, so
     * nothing is thrown away silently. Amazon does not document either case, which is exactly why neither is an exception here.
     *
     * @param list<string> $lines
     * @param list<string> $header
     * @return list<array<string, string>>
     */
    private static function dataRows(array $lines, array $header): array
    {
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = explode("\t", $line);
            $row = [];
            foreach ($cells as $position => $cell) {
                $name = $header[$position] ?? ('column_' . ($position + 1));
                $row[$name] = trim($cell, " \t\r\"");
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** The three bytes Amazon's own page warns about. */
    private static function withoutBom(string $text): string
    {
        return str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
    }
}
