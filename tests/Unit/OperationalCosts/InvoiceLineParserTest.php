<?php

namespace Tests\Unit\OperationalCosts;

use App\Services\OperationalCosts\Invoices\InvoiceLineParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvoiceLineParserTest extends TestCase
{
    private InvoiceLineParser $parser;

    protected function setUp(): void
    {
        $this->parser = new InvoiceLineParser();
    }

    public function test_a_line_has_a_reference_a_date_a_component_an_amount_a_currency_and_a_quantity(): void
    {
        $result = $this->parser->parse('TP-1001;2026-11-10;shipping;12.5;SAR;2');

        $this->assertFalse($result->hasErrors());
        $this->assertSame([[
            'external_reference' => 'TP-1001', 'service_date' => '2026-11-10', 'cost_component' => 'shipping', 'amount' => '12.5', 'currency' => 'SAR', 'quantity' => '2',
        ]], $result->items);
    }

    public function test_what_is_left_empty_is_null_and_not_a_default(): void
    {
        $result = $this->parser->parse(';;special_fee;5;;');

        $this->assertSame([['external_reference' => null, 'service_date' => null, 'cost_component' => 'special_fee', 'amount' => '5', 'currency' => null, 'quantity' => null]], $result->items);
    }

    public function test_the_last_columns_may_be_left_off(): void
    {
        $result = $this->parser->parse("TP-1;2026-11-10;shipping;12");

        $this->assertSame('12', $result->items[0]['amount']);
        $this->assertNull($result->items[0]['currency']);
        $this->assertNull($result->items[0]['quantity']);
    }

    public function test_cells_copied_out_of_a_spreadsheet_are_separated_by_tabs(): void
    {
        $result = $this->parser->parse("TP-1\t2026-11-10\tshipping\t12.5\tSAR\t1\nTP-2\t\tpicking\t3\t\t");

        $this->assertFalse($result->hasErrors());
        $this->assertSame(['TP-1', 'TP-2'], array_column($result->items, 'external_reference'));
        $this->assertSame(['12.5', '3'], array_column($result->items, 'amount'));
    }

    public function test_blank_lines_and_the_heading_row_are_skipped_and_cells_are_trimmed(): void
    {
        $result = $this->parser->parse("reference;date;component;amount;currency;quantity\r\n\r\n  TP-1 ; 2026-11-10 ; SHIPPING ; 12.5 ; sar ; 1 \r\n   \r\n");

        $this->assertFalse($result->hasErrors());
        $this->assertCount(1, $result->items);
        $this->assertSame('shipping', $result->items[0]['cost_component'], 'A component is matched without regard to case.');
        $this->assertSame('SAR', $result->items[0]['currency']);
        $this->assertSame('TP-1', $result->items[0]['external_reference']);
    }

    public function test_a_credit_is_a_negative_amount(): void
    {
        $this->assertSame('-4.25', $this->parser->parse('CN-1;;shipping;-4.25')->items[0]['amount']);
    }

    /** @return array<string, array{string, string}> */
    public static function badLines(): array
    {
        return [
            'too few columns' => ['TP-1;2026-11-10;shipping', 'too_few_columns'],
            'only a reference' => ['TP-1', 'too_few_columns'],
            'too many columns' => ['TP-1;2026-11-10;shipping;1;SAR;1;extra', 'too_many_columns'],
            'a date that is not a date' => ['TP-1;10/11/2026;shipping;1', 'invalid_date'],
            'a date that does not exist' => ['TP-1;2026-02-31;shipping;1', 'invalid_date'],
            'an unknown component' => ['TP-1;2026-11-10;flying;1', 'unknown_component'],
            'an empty component' => ['TP-1;2026-11-10;;1', 'unknown_component'],
            'text for an amount' => ['TP-1;2026-11-10;shipping;twelve', 'invalid_amount'],
            'an empty amount' => ['TP-1;2026-11-10;shipping;', 'invalid_amount'],
            'a decimal comma' => ['TP-1;2026-11-10;shipping;12,5', 'invalid_amount'],
            'scientific notation' => ['TP-1;2026-11-10;shipping;1e3', 'invalid_amount'],
            'too many decimals' => ['TP-1;2026-11-10;shipping;1.1234567', 'invalid_amount'],
            'a bad currency' => ['TP-1;2026-11-10;shipping;1;S', 'invalid_currency'],
            'a negative quantity' => ['TP-1;2026-11-10;shipping;1;SAR;-1', 'invalid_quantity'],
            'a quantity in words' => ['TP-1;2026-11-10;shipping;1;SAR;two', 'invalid_quantity'],
        ];
    }

    #[DataProvider('badLines')]
    public function test_a_line_that_cannot_be_read_is_reported_with_its_number_and_why_and_is_not_kept(string $line, string $code): void
    {
        $result = $this->parser->parse("TP-0;2026-11-10;shipping;1\n" . $line);

        $this->assertSame([['line' => 2, 'code' => $code]], $result->errors);
        $this->assertCount(1, $result->items, 'The good line before it is read; the bad one is not half-kept.');
    }

    public function test_every_bad_line_is_reported_not_only_the_first(): void
    {
        $result = $this->parser->parse("a;;flying;1\nb;;shipping;2\nc;;shipping;x");

        $this->assertSame([['line' => 1, 'code' => 'unknown_component'], ['line' => 3, 'code' => 'invalid_amount']], $result->errors);
        $this->assertSame(['b'], array_column($result->items, 'external_reference'));
    }

    public function test_the_line_number_counts_blank_lines_so_it_matches_what_the_person_sees(): void
    {
        $result = $this->parser->parse("TP-1;;shipping;1\n\n\nTP-2;;flying;1");

        $this->assertSame([['line' => 4, 'code' => 'unknown_component']], $result->errors);
    }

    public function test_a_first_line_that_is_a_real_line_is_not_taken_for_a_heading(): void
    {
        $this->assertCount(1, $this->parser->parse('shipping-ref;;shipping;5')->items);
    }

    public function test_a_paste_beyond_the_limit_is_cut_off_and_says_so(): void
    {
        $text = implode("\n", array_fill(0, InvoiceLineParser::MAX_LINES + 5, 'TP;;shipping;1'));

        $result = $this->parser->parse($text);

        $this->assertCount(InvoiceLineParser::MAX_LINES, $result->items);
        $this->assertSame([['line' => InvoiceLineParser::MAX_LINES + 1, 'code' => 'too_many_lines']], $result->errors);
    }

    public function test_nothing_pasted_is_nothing_read(): void
    {
        $result = $this->parser->parse("  \n\n");

        $this->assertSame([], $result->items);
        $this->assertFalse($result->hasErrors());
    }
}
