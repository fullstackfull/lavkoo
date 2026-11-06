<?php

namespace Tests\Feature\Fulfillment;

use App\Services\Fulfillment\Readiness\Compatibility\CompatibilitySpec;
use App\Services\Fulfillment\Support\LegacyColumnWidener;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\BuildsPilotServerSchema;

/**
 * The two compatibility migrations against a real MySQL or MariaDB table shaped like the pilot server's order_details: they change a
 * column's width and nothing else, run twice safely, never narrow, and refuse (before altering anything) a definition they cannot restate.
 * SQLite has no declared lengths, so everything but the "does nothing elsewhere" test is skipped there.
 */
class PilotServerColumnMigrationTest extends FulfillmentTestCase
{
    use BuildsPilotServerSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usePilotServerSchema();
    }

    private function requireMysqlOrMariaDb(): void
    {
        if (!$this->engineEnforcesColumnLengths()) {
            $this->markTestSkipped('The migrations change MySQL and MariaDB columns only.');
        }
    }

    /** @return array<string, array<string, mixed>> every property of every column that a change of width must leave as it was, by column name */
    private function columns(string $table = 'order_details'): array
    {
        $facts = [];
        foreach (DB::select(
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt, CHARACTER_SET_NAME AS charset, COLLATION_NAME AS collation, COLUMN_COMMENT AS comment, EXTRA AS extra, ORDINAL_POSITION AS position '
            . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$table],
        ) as $row) {
            $facts[(string) $row->name] = (array) $row;
        }

        return $facts;
    }

    /** @return list<array<string, mixed>> */
    private function indexes(string $table = 'order_details'): array
    {
        return array_map(
            static fn (object $row): array => (array) $row,
            DB::select(
                'SELECT INDEX_NAME AS name, SEQ_IN_INDEX AS position, COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique, SUB_PART AS sub_part '
                . 'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
                [$table],
            ),
        );
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return array_map(static fn (object $row): array => (array) $row, DB::table('order_details')->orderBy('id')->get()->all());
    }

    private function insertLine(string $status, ?string $variation): void
    {
        DB::table('order_details')->insert(['order_id' => 1, 'product_id' => 1, 'qty' => 1, 'delivery_status' => $status, 'variation' => $variation]);
    }

    public function test_widening_the_status_column_changes_its_width_and_nothing_else(): void
    {
        $this->requireMysqlOrMariaDb();
        DB::statement("ALTER TABLE order_details MODIFY COLUMN delivery_status VARCHAR(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'pending' COMMENT 'copy of the order status'");
        foreach (['pending', 'delivered', 'canceled'] as $status) {
            $this->insertLine($status, null);
        }
        [$columnsBefore, $indexesBefore, $rowsBefore] = [$this->columns(), $this->indexes(), $this->rows()];

        $outcome = LegacyColumnWidener::widenString(table: 'order_details', column: 'delivery_status', length: 30);

        $this->assertSame(LegacyColumnWidener::WIDENED, $outcome);
        $expected = $columnsBefore;
        $expected['delivery_status']['type'] = 'varchar(30)';
        $this->assertEquals($expected, $this->columns(), 'every column keeps its position, nullability, default, collation, comment and extras; only the type of this one changed');
        $this->assertSame('utf8mb4_bin', $this->columns()['delivery_status']['collation']);
        $this->assertSame('copy of the order status', $this->columns()['delivery_status']['comment']);
        $this->assertEquals($indexesBefore, $this->indexes(), 'the four indexes of the platform are still there, unchanged');
        $this->assertSame(4, count(array_filter($this->indexes(), static fn (array $index): bool => $index['column_name'] === 'delivery_status')));
        $this->assertEquals($rowsBefore, $this->rows(), 'no row was written');
    }

    public function test_running_the_status_migration_again_changes_nothing(): void
    {
        $this->requireMysqlOrMariaDb();
        LegacyColumnWidener::widenString(table: 'order_details', column: 'delivery_status', length: 30);
        [$columns, $indexes] = [$this->columns(), $this->indexes()];

        $again = LegacyColumnWidener::widenString(table: 'order_details', column: 'delivery_status', length: 30);

        $this->assertSame(LegacyColumnWidener::ALREADY_WIDE, $again);
        $this->assertEquals($columns, $this->columns());
        $this->assertEquals($indexes, $this->indexes());
    }

    public function test_a_column_that_is_already_wider_than_asked_is_never_narrowed(): void
    {
        $this->requireMysqlOrMariaDb();
        DB::statement("ALTER TABLE order_details MODIFY COLUMN delivery_status VARCHAR(50) NOT NULL DEFAULT 'pending'");

        $outcome = LegacyColumnWidener::widenString(table: 'order_details', column: 'delivery_status', length: 30);

        $this->assertSame(LegacyColumnWidener::ALREADY_WIDE, $outcome);
        $this->assertSame('varchar(50)', $this->columns()['delivery_status']['type']);
    }

    public function test_the_variation_column_becomes_text_with_its_data_and_properties_kept(): void
    {
        $this->requireMysqlOrMariaDb();
        DB::statement("ALTER TABLE order_details MODIFY COLUMN variation VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'options chosen'");
        $arabic = json_encode([['name' => 'الحجم', 'option' => 'كبير جدا']]);
        $this->insertLine('pending', null);
        $this->insertLine('pending', '[]');
        $this->insertLine('pending', $arabic);
        $this->insertLine('pending', str_repeat('x', 255));
        [$columnsBefore, $indexesBefore, $rowsBefore] = [$this->columns(), $this->indexes(), $this->rows()];

        $outcome = LegacyColumnWidener::convertToText(table: 'order_details', column: 'variation');

        $this->assertSame(LegacyColumnWidener::WIDENED, $outcome);
        $expected = $columnsBefore;
        $expected['variation']['type'] = 'text';
        $this->assertEquals($expected, $this->columns());
        $this->assertSame('utf8mb4_bin', $this->columns()['variation']['collation']);
        $this->assertSame('options chosen', $this->columns()['variation']['comment']);
        $this->assertEquals($indexesBefore, $this->indexes());
        $this->assertEquals($rowsBefore, $this->rows(), 'every value, the NULL included, reads back exactly as it was');
    }

    public function test_converting_the_variation_column_again_changes_nothing(): void
    {
        $this->requireMysqlOrMariaDb();
        LegacyColumnWidener::convertToText(table: 'order_details', column: 'variation');
        $columns = $this->columns();

        $again = LegacyColumnWidener::convertToText(table: 'order_details', column: 'variation');

        $this->assertSame(LegacyColumnWidener::ALREADY_WIDE, $again);
        $this->assertEquals($columns, $this->columns());
    }

    public function test_a_column_that_is_already_a_larger_text_type_is_left_alone(): void
    {
        $this->requireMysqlOrMariaDb();
        DB::statement('ALTER TABLE order_details MODIFY COLUMN variation LONGTEXT NULL');

        $outcome = LegacyColumnWidener::convertToText(table: 'order_details', column: 'variation');

        $this->assertSame(LegacyColumnWidener::ALREADY_WIDE, $outcome);
        $this->assertSame('longtext', $this->columns()['variation']['type']);
    }

    /** @return iterable<string, array{string, string}> a way the pilot column could differ from what was seen, and the part of the message that says why it is left alone */
    public static function definitionsLeftAlone(): iterable
    {
        yield 'an index on it' => ['ALTER TABLE order_details ADD INDEX idx_variation_probe (variation)', 'an index covers it'];
        yield 'a default value' => ["ALTER TABLE order_details MODIFY COLUMN variation VARCHAR(255) NOT NULL DEFAULT 'none'", 'default value'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('definitionsLeftAlone')]
    public function test_a_variation_column_the_migration_cannot_restate_safely_is_refused_before_anything_is_altered(string $change, string $because): void
    {
        $this->requireMysqlOrMariaDb();
        DB::statement($change);
        [$columns, $indexes] = [$this->columns(), $this->indexes()];

        try {
            LegacyColumnWidener::convertToText(table: 'order_details', column: 'variation');
            $this->fail('a definition that cannot be restated must be refused');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString($because, $refusal->getMessage());
            $this->assertStringContainsString('Nothing was altered', $refusal->getMessage());
        }

        $this->assertEquals($columns, $this->columns());
        $this->assertEquals($indexes, $this->indexes());
    }

    public function test_a_column_that_is_not_a_varchar_is_refused_not_converted(): void
    {
        $this->requireMysqlOrMariaDb();
        DB::statement('ALTER TABLE order_details MODIFY COLUMN delivery_status INT NOT NULL DEFAULT 0');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a varchar');

        LegacyColumnWidener::widenString(table: 'order_details', column: 'delivery_status', length: 30);
    }

    public function test_a_generated_column_is_never_rewritten(): void
    {
        $this->requireMysqlOrMariaDb();
        DB::statement('ALTER TABLE order_details ADD COLUMN status_copy VARCHAR(15) GENERATED ALWAYS AS (delivery_status) VIRTUAL');
        $columns = $this->columns();

        try {
            LegacyColumnWidener::widenString(table: 'order_details', column: 'status_copy', length: 30);
            $this->fail('a generated column must be refused');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('generated', $refusal->getMessage());
        }

        $this->assertEquals($columns, $this->columns());
    }

    public function test_a_compressed_column_is_refused_rather_than_silently_decompressed(): void
    {
        $this->requireMysqlOrMariaDb();
        try {
            DB::statement('ALTER TABLE order_details MODIFY COLUMN variation VARCHAR(255) COMPRESSED NULL');
        } catch (QueryException) {
            $this->markTestSkipped('Compressed columns are a MariaDB feature.');
        }
        $columns = $this->columns();

        try {
            LegacyColumnWidener::convertToText(table: 'order_details', column: 'variation');
            $this->fail('a column with attributes the change would drop must be refused');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('says more than a width', $refusal->getMessage());
        }

        $this->assertEquals($columns, $this->columns());
    }

    public function test_an_expression_default_is_refused_not_guessed_at(): void
    {
        $this->requireMysqlOrMariaDb();
        DB::statement("ALTER TABLE order_details MODIFY COLUMN delivery_status VARCHAR(15) NOT NULL DEFAULT (CONCAT('pen', 'ding'))");
        $columns = $this->columns();

        try {
            LegacyColumnWidener::widenString(table: 'order_details', column: 'delivery_status', length: 30);
            $this->fail('an expression default must be refused');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('was not changed', $refusal->getMessage());
        }

        $this->assertEquals($columns, $this->columns());
    }

    public function test_a_table_or_column_that_is_not_there_is_not_applicable(): void
    {
        $this->requireMysqlOrMariaDb();

        $this->assertSame(LegacyColumnWidener::NOT_APPLICABLE, LegacyColumnWidener::widenString(table: 'order_details', column: 'no_such_column', length: 30));
        $this->assertSame(LegacyColumnWidener::NOT_APPLICABLE, LegacyColumnWidener::convertToText(table: 'no_such_table', column: 'variation'));
    }

    public function test_the_migrations_run_as_part_of_the_normal_migrate_and_survive_being_run_twice(): void
    {
        $this->requireMysqlOrMariaDb();

        $this->runCompatibilityMigrations();
        $this->runCompatibilityMigrations();

        $this->assertSame('varchar(30)', $this->columns()['delivery_status']['type']);
        $this->assertSame('text', $this->columns()['variation']['type']);
    }

    public function test_what_the_plan_predicts_is_what_the_migrations_then_do(): void
    {
        $this->requireMysqlOrMariaDb();
        $planned = [];
        foreach (CompatibilitySpec::WIDENED_COLUMNS['order_details'] as $column => $width) {
            $planned[$column] = LegacyColumnWidener::preview('order_details', $column, $width)['statement'];
        }
        $this->assertNotContains(null, $planned);

        $this->runCompatibilityMigrations();

        $columns = $this->columns();
        $this->assertSame('varchar(' . CompatibilitySpec::WIDENED_COLUMNS['order_details']['delivery_status'] . ')', $columns['delivery_status']['type']);
        $this->assertStringContainsString('VARCHAR(30)', $planned['delivery_status']);
        $this->assertSame('text', $columns['variation']['type']);
        $this->assertStringContainsString(' TEXT ', $planned['variation']);
    }

    public function test_on_any_other_database_the_migrations_do_nothing_and_do_not_fail(): void
    {
        if ($this->engineEnforcesColumnLengths()) {
            $this->markTestSkipped('This proves the behaviour on a database that is neither MySQL nor MariaDB.');
        }

        $this->runCompatibilityMigrations();

        $this->assertSame(LegacyColumnWidener::NOT_APPLICABLE, LegacyColumnWidener::widenString(table: 'order_details', column: 'delivery_status', length: 30));
        $this->assertSame(LegacyColumnWidener::NOT_APPLICABLE, LegacyColumnWidener::convertToText(table: 'order_details', column: 'variation'));
    }
}
