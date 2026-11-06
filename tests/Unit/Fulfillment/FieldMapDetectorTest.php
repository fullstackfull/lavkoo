<?php

namespace Tests\Unit\Fulfillment;

use App\Services\Fulfillment\Providers\TorodPoint\TorodFieldMaps;
use App\Services\Fulfillment\Support\FieldMap;
use App\Services\Fulfillment\Support\FieldMapDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What it takes for a field map to be verified against a sample, and in particular the difference between a field that is present but
 * empty (the warehouse's historical orders carry `invoiceNumber: null`) and a field that is not there at all (what a wrong path looks like).
 */
class FieldMapDetectorTest extends TestCase
{
    private const REQUIRED = ['invoice_number', 'reference'];
    private const NULLABLE = ['invoice_number'];

    private function detector(): FieldMapDetector
    {
        return new FieldMapDetector();
    }

    private function map(array $overrides = []): FieldMap
    {
        return new FieldMap($overrides + ['invoice_number' => 'invoiceNumber', 'reference' => 'barcode', 'status' => 'status']);
    }

    /** @return list<array<string, mixed>> orders the way the warehouse lists the historical ones: a barcode, a status, and no invoice number */
    private function historicalOrders(int $count = 5): array
    {
        return array_map(static fn (int $n): array => ['id' => 9000 + $n, 'barcode' => 'O00' . $n, 'invoiceNumber' => null, 'status' => 'DELIVERED'], range(1, $count));
    }

    private function unresolved(array $sample, ?FieldMap $map = null, array $nullable = self::NULLABLE): array
    {
        return $this->detector()->unresolved(map: $map ?? $this->map(), sample: $sample, required: self::REQUIRED, nullable: $nullable);
    }

    // ---- present but null is not missing -----------------------------------------------------------------------------------

    public function test_orders_whose_invoice_number_is_null_resolve_when_that_field_may_be_empty(): void
    {
        $this->assertSame([], $this->unresolved($this->historicalOrders()));
    }

    public function test_the_same_orders_do_not_resolve_when_the_field_is_not_declared_empty_able(): void
    {
        $this->assertSame(['invoice_number'], $this->unresolved($this->historicalOrders(), nullable: []), 'a strict field still needs a value in most records');
    }

    public function test_an_empty_string_is_present_but_empty_just_like_a_null(): void
    {
        $sample = array_map(static fn (array $order): array => ['invoiceNumber' => ''] + $order, $this->historicalOrders());

        $this->assertSame([], $this->unresolved($sample));
    }

    public function test_a_field_that_is_not_in_the_records_is_not_a_null_one(): void
    {
        $sample = array_map(static fn (array $order): array => array_diff_key($order, ['invoiceNumber' => true]), $this->historicalOrders());

        $this->assertSame(['invoice_number'], $this->unresolved($sample));
    }

    public function test_a_wrong_path_is_a_missing_field_even_though_the_right_one_is_null(): void
    {
        $this->assertSame(['invoice_number'], $this->unresolved($this->historicalOrders(), $this->map(['invoice_number' => 'invoiceNumbr'])));
        $this->assertSame(['invoice_number'], $this->unresolved($this->historicalOrders(), $this->map(['invoice_number' => 'invoice_number'])));
    }

    public function test_a_nested_path_below_a_null_parent_is_a_missing_field(): void
    {
        $sample = array_map(static fn (array $order): array => ['invoice' => null] + $order, $this->historicalOrders());

        $this->assertSame(['invoice_number'], $this->unresolved($sample, $this->map(['invoice_number' => 'invoice.number'])));
    }

    public function test_most_of_the_records_must_carry_the_key(): void
    {
        $withoutKey = static fn (array $order): array => array_diff_key($order, ['invoiceNumber' => true]);
        $orders = $this->historicalOrders();

        $fourOfFive = [$orders[0], $orders[1], $orders[2], $orders[3], $withoutKey($orders[4])];
        $threeOfFive = [$orders[0], $orders[1], $orders[2], $withoutKey($orders[3]), $withoutKey($orders[4])];

        $this->assertSame([], $this->unresolved($fourOfFive), '80% of the sample is enough, as for every other field');
        $this->assertSame(['invoice_number'], $this->unresolved($threeOfFive));
    }

    public function test_a_list_or_an_object_is_not_an_empty_value(): void
    {
        $sample = array_map(static fn (array $order): array => ['invoiceNumber' => ['1-TP1']] + $order, $this->historicalOrders());

        $this->assertSame(['invoice_number'], $this->unresolved($sample));
    }

    public function test_valid_invoice_numbers_resolve_with_or_without_the_allowance(): void
    {
        $sample = array_map(static fn (array $order, int $n): array => ['invoiceNumber' => $n . '-TP1'] + $order, $this->historicalOrders(), range(1, 5));

        $this->assertSame([], $this->unresolved($sample));
        $this->assertSame([], $this->unresolved($sample, nullable: []));
    }

    // ---- everything else stays strict --------------------------------------------------------------------------------------

    public function test_the_order_barcode_stays_strict_even_when_the_invoice_number_may_be_empty(): void
    {
        $sample = array_map(static fn (array $order): array => ['barcode' => null] + $order, $this->historicalOrders());

        $this->assertSame(['reference'], $this->unresolved($sample), 'a null barcode is not an identifier');
    }

    public function test_an_unset_path_is_unresolved_whatever_the_allowance(): void
    {
        $this->assertSame(['invoice_number', 'reference'], $this->unresolved($this->historicalOrders(), new FieldMap([])));
    }

    public function test_an_empty_sample_resolves_nothing(): void
    {
        $this->assertSame(['invoice_number', 'reference'], $this->unresolved([]));
    }

    public function test_only_the_first_twenty_five_records_are_judged(): void
    {
        $sample = array_merge($this->historicalOrders(25), array_map(static fn (int $n): array => ['id' => $n], range(1, 50)));

        $this->assertSame([], $this->unresolved($sample), 'the records after the first twenty-five are not looked at');
    }

    // ---- what counts as a value ------------------------------------------------------------------------------------------------

    /** @return iterable<string, array{mixed}> */
    public static function notAValue(): iterable
    {
        yield 'a flag that is off' => [false];
        yield 'a flag that is on' => [true];
        yield 'blanks' => ['   '];
        yield 'a tab and a newline' => ["\t\n"];
    }

    #[DataProvider('notAValue')]
    public function test_the_strict_barcode_does_not_count_a_flag_or_blanks_as_a_value(mixed $barcode): void
    {
        $sample = array_map(static fn (array $order): array => ['barcode' => $barcode] + $order, $this->historicalOrders());

        $this->assertSame(['reference'], $this->unresolved($sample));
    }

    public function test_zero_and_the_text_zero_are_values(): void
    {
        $zero = array_map(static fn (array $order): array => ['barcode' => 0] + $order, $this->historicalOrders());
        $text = array_map(static fn (array $order): array => ['barcode' => '0'] + $order, $this->historicalOrders());

        $this->assertSame([], $this->unresolved($zero));
        $this->assertSame([], $this->unresolved($text));
    }

    #[DataProvider('notAValue')]
    public function test_an_invoice_number_that_is_not_a_value_passes_as_present_but_is_not_counted_as_filled(mixed $invoiceNumber): void
    {
        $sample = array_map(static fn (array $order): array => ['invoiceNumber' => $invoiceNumber] + $order, $this->historicalOrders());

        $this->assertSame([], $this->unresolved($sample));
        $this->assertSame(0, $this->detector()->coverage($this->map(), $sample, self::NULLABLE)['non_empty']['invoice_number'], 'so the page cannot say it is populated');
    }

    // ---- what the sample showed, kept next to the verification --------------------------------------------------------------

    public function test_the_coverage_tells_a_filled_field_from_an_empty_one(): void
    {
        $orders = $this->historicalOrders();
        $orders[0]['invoiceNumber'] = '1-TP1';
        $orders[1]['invoiceNumber'] = '2-TP1';
        $orders[2]['invoiceNumber'] = '';

        $coverage = $this->detector()->coverage($this->map(), $orders, self::NULLABLE);

        $this->assertSame(['sampled' => 5, 'non_empty' => ['invoice_number' => 2]], $coverage);
    }

    public function test_the_coverage_of_a_field_without_a_path_is_zero(): void
    {
        $this->assertSame(['sampled' => 5, 'non_empty' => ['invoice_number' => 0]], $this->detector()->coverage(new FieldMap([]), $this->historicalOrders(), self::NULLABLE));
    }

    // ---- proposals ---------------------------------------------------------------------------------------------------------

    public function test_the_invoice_number_is_proposed_from_its_own_key_even_when_every_value_is_null(): void
    {
        $proposal = $this->detector()->detect($this->historicalOrders(), TorodFieldMaps::CANDIDATES[TorodFieldMaps::ORDERS], TorodFieldMaps::NULLABLE[TorodFieldMaps::ORDERS]);

        $this->assertSame('invoiceNumber', $proposal['invoice_number']);
        $this->assertSame('barcode', $proposal['reference']);
    }

    public function test_the_invoice_number_is_never_proposed_from_the_barcode_or_the_id(): void
    {
        $withoutInvoiceKey = array_map(static fn (array $order): array => array_diff_key($order, ['invoiceNumber' => true]), $this->historicalOrders());

        $proposal = $this->detector()->detect($withoutInvoiceKey, TorodFieldMaps::CANDIDATES[TorodFieldMaps::ORDERS], TorodFieldMaps::NULLABLE[TorodFieldMaps::ORDERS]);

        $this->assertArrayNotHasKey('invoice_number', $proposal, 'an order list with ids and barcodes but no invoice field proposes nothing for the invoice number');
        foreach (TorodFieldMaps::CANDIDATES[TorodFieldMaps::ORDERS]['invoice_number'] as $candidate) {
            $this->assertNotContains($candidate, ['id', 'barcode', 'orderBarcode']);
        }
    }

    public function test_a_strict_field_is_still_proposed_only_from_filled_values(): void
    {
        $sample = array_map(static fn (array $order): array => ['barcode' => null] + $order, $this->historicalOrders());

        $proposal = $this->detector()->detect($sample, TorodFieldMaps::CANDIDATES[TorodFieldMaps::ORDERS], TorodFieldMaps::NULLABLE[TorodFieldMaps::ORDERS]);

        $this->assertNotSame('barcode', $proposal['reference'] ?? null, 'an empty barcode is not proposed as the order reference');
    }

    public function test_no_quantity_is_ever_proposed_as_the_stock(): void
    {
        $warehouseProduct = ['id' => 1, 'name' => 'A', 'quantity' => 0, 'originalQuantity' => 0, 'committedQuantity' => 0, 'allocatedQuantity' => 0, 'onHandQuantity' => 15, 'sellableQuantity' => 0];

        $proposal = $this->detector()->detect([$warehouseProduct], TorodFieldMaps::CANDIDATES[TorodFieldMaps::PRODUCTS]);

        $this->assertArrayNotHasKey('stock', $proposal, 'which of the warehouse quantities the storefront may sell from is decided by a person, never picked because it is filled in');
        $this->assertSame([], TorodFieldMaps::CANDIDATES[TorodFieldMaps::PRODUCTS]['stock']);
    }
}
