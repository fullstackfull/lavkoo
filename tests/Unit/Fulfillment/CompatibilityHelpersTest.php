<?php

namespace Tests\Unit\Fulfillment;

use App\Services\Fulfillment\Readiness\Compatibility\ColumnExpectation;
use App\Services\Fulfillment\Readiness\Compatibility\ColumnFacts;
use App\Services\Fulfillment\Readiness\Compatibility\ColumnFamily;
use App\Services\Fulfillment\Readiness\Compatibility\CompatibilityOptions;
use App\Services\Fulfillment\Readiness\Compatibility\CompatibilitySpec;
use App\Services\Fulfillment\Readiness\Compatibility\Enumeration;
use App\Services\Fulfillment\Readiness\Compatibility\JsonShape;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyProbe;
use App\Services\Fulfillment\Readiness\PreflightCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The pure parts of the compatibility check: no database, no application. */
class CompatibilityHelpersTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>, string}> */
    public static function columnTypes(): array
    {
        return [
            'mysql bigint unsigned' => [['type_name' => 'bigint', 'type' => 'bigint unsigned'], ColumnFamily::INTEGER],
            'mysql tinyint(1)' => [['type_name' => 'tinyint', 'type' => 'tinyint(1)'], ColumnFamily::INTEGER],
            'sqlite integer' => [['type_name' => 'integer', 'type' => 'integer'], ColumnFamily::INTEGER],
            'mysql decimal' => [['type_name' => 'decimal', 'type' => 'decimal(40,20)'], ColumnFamily::DECIMAL],
            'sqlite numeric' => [['type_name' => 'numeric', 'type' => 'numeric'], ColumnFamily::DECIMAL],
            'mysql double' => [['type_name' => 'double', 'type' => 'double'], ColumnFamily::FLOAT],
            'varchar' => [['type_name' => 'varchar', 'type' => 'varchar(255)'], ColumnFamily::STRING],
            'enum' => [['type_name' => 'enum', 'type' => "enum('a','b')"], ColumnFamily::STRING],
            'longtext' => [['type_name' => 'longtext', 'type' => 'longtext'], ColumnFamily::TEXT],
            'json' => [['type_name' => 'json', 'type' => 'json'], ColumnFamily::JSON],
            'timestamp' => [['type_name' => 'timestamp', 'type' => 'timestamp'], ColumnFamily::DATETIME],
            'point is not an integer' => [['type_name' => 'point', 'type' => 'point'], ColumnFamily::OTHER],
        ];
    }

    #[DataProvider('columnTypes')]
    public function test_every_driver_type_maps_to_one_family(array $column, string $family): void
    {
        $this->assertSame($family, ColumnFamily::of($column));
    }

    public function test_only_a_varchar_has_a_length(): void
    {
        $this->assertSame(255, ColumnFamily::length(['type_name' => 'varchar', 'type' => 'varchar(255)']));
        $this->assertNull(ColumnFamily::length(['type_name' => 'decimal', 'type' => 'decimal(40,20)']));
        $this->assertNull(ColumnFamily::length(['type_name' => 'text', 'type' => 'text']));
    }

    /** @return array<string, array{string, array<string, mixed>, bool}> kind, column, whether it is a problem */
    public static function expectations(): array
    {
        return [
            'an amount held as a decimal fits' => [ColumnExpectation::MONEY, ['type_name' => 'decimal', 'type' => 'decimal(40,20)'], false],
            'an amount held as a float fits' => [ColumnExpectation::MONEY, ['type_name' => 'double', 'type' => 'double'], false],
            'an amount held as an integer loses cents' => [ColumnExpectation::MONEY, ['type_name' => 'int', 'type' => 'int'], true],
            'an amount held as text is unusable' => [ColumnExpectation::MONEY, ['type_name' => 'varchar', 'type' => 'varchar(20)'], true],
            'an id held as a bigint fits' => [ColumnExpectation::ID, ['type_name' => 'bigint', 'type' => 'bigint unsigned'], false],
            'an id held as text cannot be joined' => [ColumnExpectation::ID, ['type_name' => 'varchar', 'type' => 'varchar(36)'], true],
            'a customer reference may be text for a guest' => [ColumnExpectation::REFERENCE, ['type_name' => 'varchar', 'type' => 'varchar(15)'], false],
            'a JSON document fits a longtext' => [ColumnExpectation::TEXT, ['type_name' => 'longtext', 'type' => 'longtext'], false],
            'a JSON document fits a json column' => [ColumnExpectation::TEXT, ['type_name' => 'json', 'type' => 'json'], false],
            'a JSON document fits a long varchar' => [ColumnExpectation::TEXT, ['type_name' => 'varchar', 'type' => 'varchar(2000)'], false],
            'a JSON document is truncated by a short varchar' => [ColumnExpectation::TEXT, ['type_name' => 'varchar', 'type' => 'varchar(255)'], true],
            'a varchar of unknown length (SQLite) gets the benefit of the doubt' => [ColumnExpectation::TEXT, ['type_name' => 'varchar', 'type' => 'varchar'], false],
            'a date held as text is a problem' => [ColumnExpectation::DATETIME, ['type_name' => 'varchar', 'type' => 'varchar(30)'], true],
        ];
    }

    #[DataProvider('expectations')]
    public function test_an_expectation_names_what_does_not_fit(string $kind, array $column, bool $isProblem): void
    {
        $expectation = new ColumnExpectation(table: 't', column: 'c', kind: $kind, severity: PreflightCheck::FAIL, reason: 'Because.');

        $problem = $expectation->problem(ColumnFacts::fromSchema($column + ['name' => 'c', 'nullable' => true]));

        $this->assertSame($isProblem, $problem !== null, (string) $problem);
        if ($problem !== null) {
            $this->assertStringContainsString('Because.', $problem);
        }
    }

    public function test_a_status_column_too_short_for_the_longest_status_is_a_problem(): void
    {
        $expectation = new ColumnExpectation(table: 'orders', column: 'order_status', kind: ColumnExpectation::STRING, severity: PreflightCheck::FAIL, reason: 'Because.', minLength: 20);

        $this->assertNotNull($expectation->problem(ColumnFacts::fromSchema(['name' => 'order_status', 'type_name' => 'varchar', 'type' => 'varchar(10)'])));
        $this->assertNull($expectation->problem(ColumnFacts::fromSchema(['name' => 'order_status', 'type_name' => 'varchar', 'type' => 'varchar(255)'])));
        $this->assertNull($expectation->problem(ColumnFacts::fromSchema(['name' => 'order_status', 'type_name' => 'text', 'type' => 'text'])));
    }

    public function test_the_spec_lists_every_column_once_with_a_known_kind_and_severity(): void
    {
        $seen = [];
        $kinds = [ColumnExpectation::ID, ColumnExpectation::INTEGER, ColumnExpectation::FLAG, ColumnExpectation::REFERENCE, ColumnExpectation::MONEY, ColumnExpectation::STRING, ColumnExpectation::TEXT, ColumnExpectation::DATETIME];

        foreach (CompatibilitySpec::columns() as $expectation) {
            $key = $expectation->table . '.' . $expectation->column;
            $this->assertArrayNotHasKey($key, $seen, "{$key} is listed twice");
            $seen[$key] = true;
            $this->assertContains($expectation->kind, $kinds, $key);
            $this->assertContains($expectation->severity, [PreflightCheck::FAIL, PreflightCheck::WARN], $key);
            $this->assertNotSame('', $expectation->reason, "{$key} has no reason");
        }
    }

    public function test_the_spec_requires_the_tables_checkout_and_the_integration_read_and_treats_the_stock_ledger_as_optional(): void
    {
        $required = CompatibilitySpec::requiredColumns();

        foreach (['orders', 'order_details', 'products', 'carts', 'business_settings', 'currencies', 'admins', 'admin_roles', 'categories', 'attributes'] as $table) {
            $this->assertArrayHasKey($table, $required, $table);
        }
        $this->assertArrayNotHasKey('stock_movements', $required);
        $this->assertContains('stock_movements', array_map(static fn (ColumnExpectation $expectation): string => $expectation->table, CompatibilitySpec::columns()));
    }

    public function test_an_enumeration_prints_code_words_and_only_counts_everything_else(): void
    {
        $description = Enumeration::describe([
            'confirmed' => 10,
            'cash_on_delivery' => 5,
            'Sara Alharbi' => 2,
            'sara@example.test' => 1,
            str_repeat('x', 41) => 1,
            ReadOnlyProbe::NULL_KEY => 3,
        ], ['confirmed']);

        $this->assertSame(['confirmed' => 10, 'cash_on_delivery' => 5], $description['values']);
        $this->assertSame(['cash_on_delivery'], $description['unknown']);
        $this->assertSame(3, $description['other_values']);
        $this->assertSame(4, $description['other_rows']);
        $this->assertSame(3, $description['null_rows']);
        $this->assertSame(22, $description['total_rows']);
        $this->assertStringNotContainsString('Sara', (string) json_encode($description));
        $this->assertStringNotContainsString('example.test', (string) json_encode($description));
    }

    public function test_an_enumeration_lists_a_bounded_number_of_values(): void
    {
        $distribution = [];
        foreach (range(1, CompatibilityOptions::MAX_LISTED_VALUES + 10) as $index) {
            $distribution["value_{$index}"] = 1;
        }

        $description = Enumeration::describe($distribution, []);

        $this->assertCount(CompatibilityOptions::MAX_LISTED_VALUES, $description['values']);
        $this->assertSame(10, $description['other_values']);
        $this->assertTrue(Enumeration::hasSurprises($description));
    }

    public function test_json_is_read_the_way_the_integration_reads_it(): void
    {
        $this->assertSame(['valid' => true, 'double_encoded' => false, 'value' => ['city' => 'x']], JsonShape::decode('{"city":"x"}'));
        $this->assertFalse(JsonShape::decode('{broken')['valid']);
        $this->assertFalse(JsonShape::decode('')['valid']);
        $this->assertFalse(JsonShape::decode(null)['valid']);

        $double = JsonShape::decode(json_encode(json_encode(['city' => 'x'])));
        $this->assertTrue($double['valid']);
        $this->assertTrue($double['double_encoded']);
        $this->assertSame(['city' => 'x'], $double['value']);

        $this->assertSame('x', JsonShape::text(['city' => 'x'], 'city'));
        $this->assertNull(JsonShape::text(['city' => '  '], 'city'));
        $this->assertNull(JsonShape::text('not an array', 'city'));
    }

    public function test_the_sample_size_is_kept_within_bounds(): void
    {
        $this->assertSame(CompatibilityOptions::MIN_SAMPLE, (new CompatibilityOptions(sample: 1))->sample);
        $this->assertSame(CompatibilityOptions::MAX_SAMPLE, (new CompatibilityOptions(sample: 9_999_999))->sample);
        $this->assertSame(2000, (new CompatibilityOptions())->sample);
    }
}
