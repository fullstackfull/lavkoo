<?php

namespace Tests\Feature\Storefront;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * GCC-05, slice 5: the coupons migration appends one nullable column and nothing else. It touches no coupon, runs twice without harm, and cannot be rolled
 * back while a coupon names a storefront, because dropping the column would turn that coupon into a coupon of the default storefront, whose amounts are in
 * another currency.
 */
class CouponStorefrontMigrationTest extends TestCase
{
    private const FILE = 'database/migrations/2026_12_01_100000_add_storefront_country_to_coupons_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('coupons');
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->timestamps();
        });
        DB::table('coupons')->insert([['code' => 'OLD1'], ['code' => 'OLD2']]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('coupons');

        parent::tearDown();
    }

    private function migration(): object
    {
        return require base_path(self::FILE);
    }

    public function test_it_appends_a_nullable_column_and_touches_no_coupon(): void
    {
        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('coupons', 'storefront_country'));
        $this->assertSame(['id', 'code', 'created_at', 'updated_at', 'storefront_country'], Schema::getColumnListing('coupons'), 'appended at the end');
        $this->assertSame([null, null], DB::table('coupons')->orderBy('id')->pluck('storefront_country')->all(), 'every coupon that exists is the default storefront\'s');
        $this->assertSame(['OLD1', 'OLD2'], DB::table('coupons')->orderBy('id')->pluck('code')->all());
    }

    public function test_it_runs_twice_without_harm(): void
    {
        $this->migration()->up();
        DB::table('coupons')->where('code', 'OLD1')->update(['storefront_country' => 'KW']);

        $this->migration()->up();

        $this->assertSame('KW', DB::table('coupons')->where('code', 'OLD1')->value('storefront_country'));
    }

    public function test_it_rolls_back_when_no_coupon_names_a_storefront(): void
    {
        $this->migration()->up();

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('coupons', 'storefront_country'));
        $this->assertSame(2, DB::table('coupons')->count());
    }

    public function test_it_refuses_to_roll_back_while_a_coupon_names_a_storefront(): void
    {
        $this->migration()->up();
        DB::table('coupons')->where('code', 'OLD2')->update(['storefront_country' => 'AE']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Coupons of another storefront exist');

        try {
            $this->migration()->down();
        } finally {
            $this->assertTrue(Schema::hasColumn('coupons', 'storefront_country'), 'nothing was dropped');
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
            ['coupons', 'storefront_country'],
        );

        $this->assertSame(['ascii', 'ascii_bin'], [$row->charset, $row->collation]);
    }
}
