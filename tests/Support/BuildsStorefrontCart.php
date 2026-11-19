<?php

namespace Tests\Support;

use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A store with a cart that works: the order pipeline's tables, the products migration, two in-house products of two countries, and the
 * columns the cart add and the checkout read. For a test case that extends ApiAddressTestCase and uses BuildsStorefrontCatalog.
 */
trait BuildsStorefrontCart
{
    use ActivatesStorefront;
    use ApprovesStorefrontTax;

    /** What every product of this world has in common: the shop sells it itself. */
    private const IN_HOUSE_PRODUCT = ['added_by' => 'admin', 'user_id' => 1, 'code' => 'SKU-1', 'variation' => '[]', 'images' => '[]', 'thumbnail' => 'x.png', 'category_ids' => '[]', 'discount' => 0, 'discount_type' => 'flat', 'choice_options' => '[]', 'tax' => 0, 'tax_type' => 'percent', 'tax_model' => 'exclude'];

    protected function buildStorefrontCartWorld(): void
    {
        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        $this->runStorefrontCountryMigration();

        if (!Schema::hasColumn('products', 'code')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('code')->nullable();
                $table->text('choice_options')->nullable();
                $table->integer('free_shipping')->default(0);
            });
        }
        if (!Schema::hasColumn('carts', 'color')) {
            Schema::table('carts', function (Blueprint $table) {
                $table->string('color')->nullable();
                $table->text('choices')->nullable();
                $table->text('variations')->nullable();
                $table->string('tax_model')->nullable();
                $table->string('slug')->nullable();
                $table->string('name')->nullable();
                $table->string('thumbnail')->nullable();
                $table->string('shop_info')->nullable();
                $table->string('digital_product_type')->nullable();
                $table->decimal('tax', 16, 2)->default(0);
            });
        }

        StorefrontCatalog::flush();
    }

    /** Storefronts on, with Saudi Arabia and the UAE allowed to sell (the UAE's legal texts are grandfathered, its tax is decided to be none and it is active here, so that the sale gate is not what is under test). */
    protected function switchStorefrontsOn(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.legal.grandfathered' => ['SA', 'AE'],
        ]);
        $this->approveNoTaxFor('AE');
        $this->activateStorefrontFor('AE');
        StorefrontCatalog::flush();
    }

    /** Saudi product 1 (100, stock 10) and Emirati product 2 (250, stock 4): the same code, both sold by the shop itself. */
    protected function seedTwoCountries(): void
    {
        DB::table('products')->insert([
            self::IN_HOUSE_PRODUCT + ['id' => 1, 'name' => 'Paracetamol SA', 'slug' => 'paracetamol-sa', 'unit_price' => 100, 'current_stock' => 10, 'storefront_country' => 'SA'],
            self::IN_HOUSE_PRODUCT + ['id' => 2, 'name' => 'Paracetamol AE', 'slug' => 'paracetamol-ae', 'unit_price' => 250, 'current_stock' => 4, 'storefront_country' => 'AE'],
        ]);
    }

    /** Product 3 (100, stock 10), which belongs to no country: the column is not null but it takes an empty string, which a bad write can leave behind. */
    protected function seedAProductOfNoCountry(): void
    {
        DB::table('products')->insert(
            self::IN_HOUSE_PRODUCT + ['id' => 3, 'name' => 'Paracetamol nowhere', 'slug' => 'paracetamol-nowhere', 'unit_price' => 100, 'current_stock' => 10, 'storefront_country' => ''],
        );
    }

    protected function cartRow(int $customerId, int $productId, float $price, ?string $group = null, int $checked = 1): int
    {
        return DB::table('carts')->insertGetId([
            'customer_id' => $customerId, 'is_guest' => false, 'cart_group_id' => $group ?? ('group-' . $customerId . 'c'), 'product_id' => $productId,
            'quantity' => 1, 'price' => $price, 'seller_id' => 1, 'seller_is' => 'admin', 'is_checked' => $checked, 'product_type' => 'physical',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<int> the ids of the products the cart still holds */
    protected function productsInTheCart(): array
    {
        return DB::table('carts')->orderBy('product_id')->pluck('product_id')->map(fn ($id) => (int) $id)->all();
    }
}
