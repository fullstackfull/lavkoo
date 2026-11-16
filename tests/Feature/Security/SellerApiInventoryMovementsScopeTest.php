<?php

namespace Tests\Feature\Security;

use App\Services\Marketplace\InventoryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSellerApiWorld;
use Tests\TestCase;

/**
 * The stock movement log of the seller apps is read by the seller id the movement carries. A manual correction of an in-house product stamped
 * its movement with the product's user_id, which is 1 for every in-house product, so seller 1's log showed the admin's corrections of the
 * in-house shop's stock (product, signed change, balance, reason and the admin's own note), and so did the movement count of the overview.
 *
 * A movement belongs to a seller when it carries the seller's id and its product is that seller's product, so the rows already stamped
 * the old way are not shown either.
 */
class SellerApiInventoryMovementsScopeTest extends TestCase
{
    use BuildsSellerApiWorld;

    private const OWN_PRODUCT = 10;
    private const OTHER_SELLERS_PRODUCT = 20;
    private const IN_HOUSE_PRODUCT = 30;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerApiWorld();
        Schema::table('sellers', static function (Blueprint $table): void {
            $table->integer('stock_limit')->default(0);
        });
        $this->createSellerApiTable('products', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('added_by')->nullable();
            $table->string('name')->nullable();
            $table->string('product_type')->default('physical');
            $table->integer('current_stock')->default(0);
            $table->timestamps();
        });
        $this->createSellerApiTable('stock_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            foreach (['type', 'reason', 'reference_type', 'created_by_type'] as $column) {
                $table->string($column)->nullable();
            }
            $table->integer('qty_change')->default(0);
            $table->integer('balance_after')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        foreach ([[self::OWN_PRODUCT, 1, 'seller'], [self::OTHER_SELLERS_PRODUCT, 2, 'seller'], [self::IN_HOUSE_PRODUCT, 1, 'admin']] as [$productId, $userId, $addedBy]) {
            DB::table('products')->insert(['id' => $productId, 'user_id' => $userId, 'added_by' => $addedBy, 'name' => 'Product ' . $productId, 'current_stock' => 5]);
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerApiWorld();

        parent::tearDown();
    }

    public function test_an_adjustment_of_an_in_house_product_carries_no_seller_and_is_not_in_seller_ones_log(): void
    {
        $this->adjust(productId: self::IN_HOUSE_PRODUCT, note: 'in-house count correction');

        $this->assertNull(DB::table('stock_movements')->value('seller_id'));
        $this->assertSame([], $this->movementsOf(self::SELLER));
    }

    public function test_an_adjustment_of_a_sellers_own_product_carries_that_seller_and_is_in_that_sellers_log(): void
    {
        $this->adjust(productId: self::OWN_PRODUCT, note: 'own count correction');
        $this->adjust(productId: self::OTHER_SELLERS_PRODUCT, note: 'rival count correction');

        $this->assertSame([self::SELLER, self::OTHER_SELLER], DB::table('stock_movements')->orderBy('id')->pluck('seller_id')->map(fn ($sellerId) => (int) $sellerId)->all());
        $this->assertSame([self::OWN_PRODUCT], $this->movementsOf(self::SELLER));
        $this->assertSame([self::OTHER_SELLERS_PRODUCT], $this->movementsOf(self::OTHER_SELLER));
    }

    public function test_a_movement_of_an_in_house_product_that_an_older_version_stamped_with_seller_ones_id_is_not_in_seller_ones_log_or_count(): void
    {
        DB::table('stock_movements')->insert([
            ['product_id' => self::IN_HOUSE_PRODUCT, 'seller_id' => self::SELLER, 'type' => 'adjustment', 'qty_change' => -1, 'note' => 'stamped the old way'],
            ['product_id' => self::OWN_PRODUCT, 'seller_id' => self::SELLER, 'type' => 'adjustment', 'qty_change' => 2, 'note' => 'own'],
        ]);

        $overview = $this->sellerApi('GET', 'seller-center/inventory/overview');

        $this->assertSame([self::OWN_PRODUCT], $this->movementsOf(self::SELLER));
        $this->assertSame(1, $overview->json('movements_recorded'));
    }

    private function adjust(int $productId, string $note): void
    {
        $result = app(InventoryService::class)->adjust(productId: $productId, delta: -1, reason: 'loss', note: $note, by: 1, byType: 'admin');

        $this->assertTrue($result['ok'], 'the adjustment was refused');
    }

    /** @return list<int> the products of the movements the seller's log shows */
    private function movementsOf(int $sellerId): array
    {
        $response = $this->sellerApi('GET', 'seller-center/inventory/movements', as: $sellerId);

        $this->assertSame(200, $response->getStatusCode());

        return array_map(static fn (array $movement): int => $movement['product_id'], $response->json('movements'));
    }
}
