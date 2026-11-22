<?php

namespace Tests\Feature\Fulfillment;

use App\Utils\OrderManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The checkout validation looks at one cart line per cart group. It used to ask for it with `select *` and a bare GROUP BY, which a server that
 * enforces ONLY_FULL_GROUP_BY (the default of MySQL 8) refuses with error 1055, so the web checkout answered 500 there. The strict-mode job runs this
 * directory with DB_STRICT_TESTS=1; on SQLite it pins which line stands for the group.
 */
class CheckoutCartGroupStrictSqlTest extends FulfillmentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sellers', static function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('approved');
            $table->softDeletes();
        });
        Schema::create('brands', static function (Blueprint $table): void {
            $table->id();
            $table->integer('status')->default(1);
        });
    }

    private function cartLine(int $id, string $group, int $productId, int $checked = 1): void
    {
        DB::table('carts')->insert(['id' => $id, 'customer_id' => 1, 'cart_group_id' => $group, 'product_id' => $productId, 'is_checked' => $checked, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_one_line_stands_for_a_group_the_first_checked_one_by_id(): void
    {
        $this->makeProduct(10);
        $this->makeProduct(11);
        $this->cartLine(7, 'G1', 11);
        $this->cartLine(3, 'G1', 10);
        $this->cartLine(5, 'G1', 10, checked: 0);
        $this->cartLine(9, 'G2', 11);

        $first = OrderManager::firstCheckedCartLineOfGroup(groupId: 'G1');

        $this->assertSame([3], $first->pluck('id')->all(), 'the lowest id among the checked lines of the group');
        $this->assertSame(10, (int) $first->first()->product->id, 'the product is loaded with the line');
        $this->assertSame([9], OrderManager::firstCheckedCartLineOfGroup(groupId: 'G2')->pluck('id')->all());
    }

    public function test_a_group_with_no_checked_line_or_with_an_inactive_product_has_no_line(): void
    {
        $this->makeProduct(10);
        $this->makeProduct(12, overrides: ['status' => 0]);
        $this->cartLine(1, 'G3', 10, checked: 0);
        $this->cartLine(2, 'G4', 12);

        $this->assertCount(0, OrderManager::firstCheckedCartLineOfGroup(groupId: 'G3'));
        $this->assertCount(0, OrderManager::firstCheckedCartLineOfGroup(groupId: 'G4'));
        $this->assertCount(0, OrderManager::firstCheckedCartLineOfGroup(groupId: 'absent'));
    }
}
