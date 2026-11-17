<?php

namespace Tests\Feature\Catalog;

use App\Services\Catalog\ProductCodeSites;
use Tests\TestCase;

/**
 * docs/gcc/GCC-14B-COUNTRY-SCOPED-CODES.md is what the owner reads before the switch is turned on. Its table of the survey sites must say what the
 * registry (and so the readiness command) says, and the decisions it records must still be true of the tree.
 */
class ProductCodeDocTest extends TestCase
{
    private const DOC = 'docs/gcc/GCC-14B-COUNTRY-SCOPED-CODES.md';

    private const LABELS = [
        ProductCodeSites::COVERED => 'covered',
        ProductCodeSites::PARTIAL => 'partial',
        ProductCodeSites::LABELLED => 'labelled',
        ProductCodeSites::ID_KEYED => 'not needed (id-keyed)',
        ProductCodeSites::NOT_COVERED => 'NOT covered',
        ProductCodeSites::DEFERRED => 'deferred (owner)',
    ];

    private function doc(): string
    {
        return (string) file_get_contents(base_path(self::DOC));
    }

    public function test_the_site_table_of_the_doc_says_what_the_registry_says(): void
    {
        $doc = $this->doc();

        foreach (ProductCodeSites::all() as $site) {
            $this->assertMatchesRegularExpression(
                '/^\| ' . preg_quote($site['id'], '/') . ' \| `[^|]*` \| ' . preg_quote(self::LABELS[$site['status']], '/') . ' \| /m',
                $doc,
                "{$site['id']} is {$site['status']} in ProductCodeSites and the doc says otherwise (or has no row for it)",
            );
        }
    }

    public function test_the_doc_states_the_totals_of_the_registry(): void
    {
        $counts = ProductCodeSites::counts();

        $this->assertStringContainsString(
            sprintf('**%d covered, %d labelled, %d not needed (id-keyed), %d open**', $counts['covered'], $counts['labelled'], $counts['id_keyed'], $counts['open']),
            $this->doc(),
            'the totals line of the doc is out of step with ProductCodeSites::counts()',
        );
        $this->assertStringContainsString(sprintf('(%d rows below)', array_sum($counts)), $this->doc());
    }

    public function test_the_doc_has_no_row_for_a_site_the_registry_does_not_know(): void
    {
        preg_match_all('/^\| ([A-G]\d+) \| `/m', $this->doc(), $rows);

        $this->assertEqualsCanonicalizing(array_column(ProductCodeSites::all(), 'id'), $rows[1]);
    }

    public function test_every_site_of_the_survey_is_in_the_registry(): void
    {
        $ids = array_column(ProductCodeSites::all(), 'id');

        $survey = array_merge(
            array_map(static fn (int $number): string => 'A' . $number, range(1, 10)),
            ['B1', 'B2'],
            array_map(static fn (int $number): string => 'C' . $number, range(1, 11)),
            array_map(static fn (int $number): string => 'D' . $number, range(1, 8)),
            ['E1', 'E2', 'E3', 'F1'],
            array_map(static fn (int $number): string => 'G' . $number, range(1, 9)),
        );

        $this->assertEqualsCanonicalizing($survey, $ids);
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_the_doc_names_the_switch_the_command_and_what_it_does_not_do(): void
    {
        $doc = $this->doc();

        foreach (['GCC_COUNTRY_SCOPED_CODES', 'gcc.catalog.country_scoped_codes', 'php artisan gcc:catalog:code-readiness', 'scan_code', 'No database index in this slice', 'Open owner decisions', 'Assumptions'] as $needle) {
            $this->assertStringContainsString($needle, $doc);
        }
    }

    /** The ways a migration can put a unique index on a product's code or barcode. */
    private const UNIQUE_ON_A_CODE = [
        // $table->unique('code'), $table->unique(['storefront_country', 'barcode'])
        '/\$table->unique\(\s*(?:\[[^\]]*)?[\'"](?:code|barcode)[\'"]/',
        // $table->string('code')->unique()
        '/\$table->\w+\(\s*[\'"](?:code|barcode)[\'"][^;]*->unique\(/',
        // an index created by hand
        '/create\s+unique\s+index[^;]*\b(?:code|barcode)\b/i',
    ];

    private function addsAUniqueIndexOnACode(string $migration): bool
    {
        foreach (self::UNIQUE_ON_A_CODE as $pattern) {
            if (preg_match($pattern, $migration) === 1) {
                return true;
            }
        }

        return false;
    }

    public function test_no_migration_of_this_tree_adds_a_unique_index_on_the_codes(): void
    {
        foreach (glob(database_path('migrations/*.php')) as $migration) {
            $text = (string) file_get_contents($migration);
            if (preg_match("/Schema::(?:table|create)\\(\\s*'products'/", $text) === 1) {
                $this->assertFalse($this->addsAUniqueIndexOnACode($text), basename($migration) . ' adds a unique index on a product code: that is a later, owner-approved step (see the doc)');
            }
        }
    }

    public function test_the_check_sees_every_way_of_writing_such_an_index(): void
    {
        $this->assertTrue($this->addsAUniqueIndexOnACode("\$table->unique('code');"));
        $this->assertTrue($this->addsAUniqueIndexOnACode("\$table->unique(['storefront_country', 'barcode'], 'products_country_barcode_unique');"));
        $this->assertTrue($this->addsAUniqueIndexOnACode("\$table->string('code', 20)->unique();"));
        $this->assertTrue($this->addsAUniqueIndexOnACode("DB::statement('CREATE UNIQUE INDEX p_code ON products (storefront_country, code)');"));
        $this->assertFalse($this->addsAUniqueIndexOnACode("\$table->index('code', 'idx_products_code'); \$collection->unique();"));
        $this->assertFalse($this->addsAUniqueIndexOnACode("\$table->unique('slug');"));
    }

    public function test_the_switch_ships_off_in_the_configuration_file(): void
    {
        $this->assertStringContainsString("filter_var(env('GCC_COUNTRY_SCOPED_CODES', false), FILTER_VALIDATE_BOOLEAN)", (string) file_get_contents(config_path('gcc.php')));
    }
}
