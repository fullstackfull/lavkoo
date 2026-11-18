<?php

namespace Tests\Feature\Storefront;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The menu of the admin panel shows the storefront entry to an admin whose role holds a storefront permission, and to no other: the section of the sales channels opens for it in the side bar
 * and its button is on the rail. A role that holds none of them sees neither, as before.
 */
class AdminStorefrontMenuTest extends AdminStorefrontScreenTestCase
{
    /** @return array<string, array{int, bool}> */
    public static function adminsAndTheirMenu(): array
    {
        return [
            'the master admin' => [self::MASTER, true],
            'the lifecycle role' => [self::LIFECYCLE_ADMIN, true],
            'a legal writer' => [self::LEGAL_WRITER, true],
            'a legal approver' => [self::LEGAL_APPROVER, true],
            'a role that holds another module' => [self::CATALOG_ADMIN, false],
            'a role that holds all three but is switched off' => [self::ADMIN_OF_A_SWITCHED_OFF_ROLE, false],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The side bar reads tables of the whole panel: this test builds minimal ones in the throwaway SQLite database, and no real server keeps them.');
        }

        // What the side bar reads besides the role: the setup guide counts categories, brands and products, and a product brings its translations and reviews.
        Schema::create('categories', static fn (Blueprint $table) => $table->id());
        Schema::create('brands', static fn (Blueprint $table) => $table->id());
        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->integer('request_status')->default(1);
            $table->integer('status')->default(1);
            $table->string('added_by')->default('admin');
            $table->integer('is_shipping_cost_updated')->nullable();
        });
        Schema::create('translations', static function (Blueprint $table): void {
            $table->id();
            $table->string('translationable_type')->nullable();
            $table->unsignedBigInteger('translationable_id')->nullable();
            $table->string('locale')->nullable();
            $table->string('key')->nullable();
            $table->text('value')->nullable();
        });
        Schema::create('reviews', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->integer('status')->default(1);
        });
    }

    #[DataProvider('adminsAndTheirMenu')]
    public function test_the_storefront_entry_is_in_the_side_bar_and_on_the_rail_only_for_a_role_that_holds_a_permission(int $admin, bool $isShown): void
    {
        $this->signInAs($admin);

        $sideBar = view('layouts.admin.partials.v2._side-bar')->render();
        $rail = view('layouts.admin.partials.v2._rail')->render();

        $this->assertSame($isShown, str_contains($sideBar, route('admin.storefronts.index')), 'the side bar');
        $this->assertSame($isShown, str_contains($rail, 'data-section="channels"'), 'the rail');
    }
}
