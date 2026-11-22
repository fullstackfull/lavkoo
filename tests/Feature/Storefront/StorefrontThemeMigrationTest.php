<?php

namespace Tests\Feature\Storefront;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\BuildsThemeWorld;
use Tests\TestCase;

/**
 * GCC F-01: the themes migration appends one nullable column and nothing else. It touches no theme, runs twice without harm, does nothing on a database that has no
 * themes table (no migration of this repository creates it), and cannot be rolled back while a theme names a storefront, because dropping the column would make
 * every such theme a theme of the default storefront.
 */
class StorefrontThemeMigrationTest extends TestCase
{
    use BuildsThemeWorld;

    private const FILE = 'database/migrations/2026_12_04_100000_add_storefront_country_to_themes_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildThemeWorld(withCountryColumn: false);
        $this->giveTheme(name: 'Old one', active: true);
        $this->giveTheme(name: 'Old two');
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();

        parent::tearDown();
    }

    private function migration(): object
    {
        return require base_path(self::FILE);
    }

    public function test_it_appends_a_nullable_column_and_touches_no_theme(): void
    {
        $columnsBefore = Schema::getColumnListing('themes');

        $this->migration()->up();

        $this->assertSame([...$columnsBefore, 'storefront_country'], Schema::getColumnListing('themes'), 'appended at the end');
        $this->assertSame([null, null], DB::table('themes')->orderBy('id')->pluck('storefront_country')->all(), 'every theme that exists is the default storefront\'s');
        $this->assertSame(['Old one', 'Old two'], DB::table('themes')->orderBy('id')->pluck('name')->all());
        $this->assertSame([1, 0], DB::table('themes')->orderBy('id')->pluck('is_active')->map(fn ($active) => (int) $active)->all());
    }

    public function test_it_runs_twice_without_harm(): void
    {
        $this->migration()->up();
        DB::table('themes')->where('name', 'Old two')->update(['storefront_country' => 'AE']);

        $this->migration()->up();

        $this->assertSame('AE', DB::table('themes')->where('name', 'Old two')->value('storefront_country'));
        $this->assertSame(1, count(array_keys(Schema::getColumnListing('themes'), 'storefront_country', true)));
    }

    public function test_it_does_nothing_on_a_database_that_has_no_themes_table(): void
    {
        $this->dropThemeWorld();

        $this->migration()->up();

        $this->assertFalse(Schema::hasTable('themes'));
    }

    public function test_it_rolls_back_to_exactly_the_table_it_found_when_no_theme_names_a_storefront(): void
    {
        $columnsBefore = Schema::getColumnListing('themes');
        $this->migration()->up();

        $this->migration()->down();

        $this->assertSame($columnsBefore, Schema::getColumnListing('themes'));
        $this->assertSame(2, DB::table('themes')->count());
    }

    public function test_it_can_be_rolled_back_twice_and_run_again(): void
    {
        $this->migration()->up();
        $this->migration()->down();
        $this->migration()->down();
        $this->assertFalse(Schema::hasColumn('themes', 'storefront_country'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('themes', 'storefront_country'));
    }

    public function test_it_refuses_to_roll_back_while_a_theme_names_a_storefront(): void
    {
        $this->migration()->up();
        DB::table('themes')->where('name', 'Old two')->update(['storefront_country' => 'AE']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Themes of another storefront exist');

        try {
            $this->migration()->down();
        } finally {
            $this->assertTrue(Schema::hasColumn('themes', 'storefront_country'), 'nothing was dropped');
            $this->assertSame('AE', DB::table('themes')->where('name', 'Old two')->value('storefront_country'));
        }
    }

    public function test_the_column_is_compared_byte_for_byte_on_mysql_and_mariadb(): void
    {
        if (!in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Only MySQL and MariaDB have a collation to check.');
        }

        $this->migration()->up();
        $row = DB::selectOne(
            'select character_set_name as charset, collation_name as collation from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
            ['themes', 'storefront_country'],
        );

        $this->assertSame(['ascii', 'ascii_bin'], [$row->charset, $row->collation]);
    }
}
