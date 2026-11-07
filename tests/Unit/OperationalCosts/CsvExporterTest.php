<?php

namespace Tests\Unit\OperationalCosts;

use App\Enums\OperationalCosts\CostComponent;
use App\Services\OperationalCosts\Reports\CsvExporter;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CsvExporterTest extends TestCase
{
    /** @param list<list<mixed>> $rows */
    private function write(array $headings, array $rows): string
    {
        $stream = fopen('php://memory', 'w+b');
        (new CsvExporter())->write($stream, $headings, $rows);
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /** @return list<list<string>> the rows of a file, without its byte order mark */
    private function parse(string $csv): array
    {
        return array_map('str_getcsv', explode("\r\n", rtrim(substr($csv, strlen(CsvExporter::BYTE_ORDER_MARK)), "\r\n")));
    }

    public function test_a_file_starts_with_the_utf8_byte_order_mark_so_excel_reads_arabic_as_arabic(): void
    {
        $csv = $this->write(['اسم', 'المبلغ'], [['شحن', Money::of('12.5')]]);

        $this->assertSame("\xEF\xBB\xBF", substr($csv, 0, 3));
        $this->assertSame([['اسم', 'المبلغ'], ['شحن', '12.500000']], $this->parse($csv), 'Arabic is written as it is.');
        $this->assertTrue(mb_check_encoding($csv, 'UTF-8'));
    }

    public function test_rows_end_with_a_carriage_return_and_a_line_feed_and_the_number_of_data_rows_is_returned(): void
    {
        $stream = fopen('php://memory', 'w+b');

        $written = (new CsvExporter())->write($stream, ['a'], [['1'], ['2'], ['3']]);
        rewind($stream);

        $this->assertSame(3, $written);
        $this->assertSame("\xEF\xBB\xBFa\r\n1\r\n2\r\n3\r\n", stream_get_contents($stream));
    }

    public function test_an_empty_report_still_has_its_headings(): void
    {
        $this->assertSame([['a', 'b']], $this->parse($this->write(['a', 'b'], [])));
    }

    /** @return array<string, array{string}> */
    public static function formulaStarts(): array
    {
        return [
            'equals' => ['=1+1'], 'plus' => ['+1+1'], 'minus' => ['-2+3'], 'at' => ['@SUM(A1)'], 'tab' => ["\t=cmd"], 'carriage return' => ["\r=cmd"],
            'a function' => ['=HYPERLINK("http://evil.test","click")'], 'a dde command' => ["=cmd|' /C calc'!A0"],
        ];
    }

    #[DataProvider('formulaStarts')]
    public function test_a_text_cell_that_a_spreadsheet_would_run_gets_a_quote_in_front_of_it(string $text): void
    {
        $exporter = new CsvExporter();

        $this->assertSame("'" . $text, $exporter->cell($text));
        $this->assertSame([["'" . $text]], array_slice($this->parse($this->write(['x'], [[$text]])), 1), 'And it survives the CSV quoting.');
    }

    #[DataProvider('formulaStarts')]
    public function test_a_heading_is_neutralised_like_any_other_cell(string $text): void
    {
        $this->assertSame([["'" . $text]], array_slice($this->parse($this->write([$text], [])), 0, 1));
    }

    public function test_text_that_is_harmless_is_left_alone(): void
    {
        $exporter = new CsvExporter();

        foreach (['', 'plain', '12 =', 'a=b', 'x-y', 'email@example.test', " leading space =1", '١٢٣', 'شحن = سريع', "'already quoted"] as $text) {
            $this->assertSame($text, $exporter->cell($text), $text);
        }
    }

    public function test_an_amount_is_the_exact_decimal_it_is_and_a_negative_one_is_a_number_not_a_command(): void
    {
        $exporter = new CsvExporter();

        $this->assertSame('-5.000000', $exporter->cell(Money::of('-5')));
        $this->assertSame('0.100000', $exporter->cell(Money::of('0.1')));
        $this->assertSame('0.300000', $exporter->cell(Money::of('0.1')->plus('0.2')), 'No float noise.');
        $this->assertSame('1234567890123.123457', $exporter->cell(Money::of('1234567890123.1234567')));
        $this->assertSame('-5', $exporter->cell(-5));
        $this->assertSame('7', $exporter->cell(7));
    }

    public function test_nothing_known_is_empty_and_never_a_zero(): void
    {
        $exporter = new CsvExporter();

        $this->assertSame('', $exporter->cell(null));
        $this->assertSame('0.000000', $exporter->cell(Money::zero()), 'A zero is written only when the value really is one.');
    }

    public function test_booleans_dates_and_enums_are_written_plainly(): void
    {
        $exporter = new CsvExporter();

        $this->assertSame('1', $exporter->cell(true));
        $this->assertSame('0', $exporter->cell(false));
        $this->assertSame('2026-11-05 09:30:00', $exporter->cell(CarbonImmutable::parse('2026-11-05 09:30:00')));
        $this->assertSame('shipping', $exporter->cell(CostComponent::Shipping));
    }

    public function test_a_float_is_refused_because_money_never_travels_as_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CsvExporter())->cell(0.1 + 0.2);
    }

    public function test_commas_quotes_and_line_breaks_inside_a_cell_are_quoted(): void
    {
        $rows = [['a,b', 'say "hi"', "two\nlines"]];

        $this->assertSame($rows, array_slice($this->parse($this->write(['x', 'y', 'z'], $rows)), 1));
    }
}
