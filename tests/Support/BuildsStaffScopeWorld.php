<?php

namespace Tests\Support;

use App\Models\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The tables the staff country scope reads and writes, built from the real migration of its own table, and the employees the tests sign in as.
 *
 * Like the other schema builders, `admins` and `admin_roles` are INFERRED from the models: the installation dump is not in the repository. The tables are
 * dropped before they are built, so on a real engine the database must be unmistakably a throwaway one.
 *
 * Employees: 1 is the master admin (role 1), 2 and 3 are catalog employees (role 2), 4 is an employee whose role was never set, 5 holds the catalog and the
 * marketplace (role 3) and 6 holds the orders only (role 4).
 */
trait BuildsStaffScopeWorld
{
    private const STAFF_SCOPE_MIGRATION = '2026_11_28_100000_create_admin_country_scopes_table.php';

    protected function buildStaffAdminTables(): void
    {
        $this->refuseToDropStaffTablesOutsideATestDatabase();

        foreach (['admin_country_scopes', 'admin_roles', 'admins'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('admin_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->text('module_access')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        DB::table('admin_roles')->insert([
            ['id' => 1, 'name' => 'Master Admin', 'module_access' => null, 'status' => 1],
            ['id' => 2, 'name' => 'Catalog', 'module_access' => json_encode(['catalog']), 'status' => 1],
            ['id' => 3, 'name' => 'Catalog and marketplace', 'module_access' => json_encode(['catalog', 'marketplace']), 'status' => 1],
            ['id' => 4, 'name' => 'Orders', 'module_access' => json_encode(['orders']), 'status' => 1],
        ]);

        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('admin_role_id')->nullable();
            $table->integer('status')->default(1);
            $table->timestamps();
        });
        DB::table('admins')->insert([
            ['id' => 1, 'name' => 'Master', 'email' => 'master@example.test', 'admin_role_id' => 1],
            ['id' => 2, 'name' => 'Catalog one', 'email' => 'one@example.test', 'admin_role_id' => 2],
            ['id' => 3, 'name' => 'Catalog two', 'email' => 'two@example.test', 'admin_role_id' => 2],
            ['id' => 4, 'name' => 'No role', 'email' => 'none@example.test', 'admin_role_id' => null],
            ['id' => 5, 'name' => 'Marketplace', 'email' => 'five@example.test', 'admin_role_id' => 3],
            ['id' => 6, 'name' => 'Orders only', 'email' => 'six@example.test', 'admin_role_id' => 4],
        ]);
    }

    /**
     * The tables the product screens read for the tests that make real requests to them: the products with the column the real migration adds, what a list of
     * them loads (translations, reviews, clearance offers), the restock requests, and the sellers, shops, brands and categories of the list page.
     */
    protected function buildStaffProductTables(): void
    {
        $this->refuseToDropStaffTablesOutsideATestDatabase();

        foreach (['restock_product_customers', 'restock_products', 'stock_clearance_products', 'order_details', 'reviews', 'translations', 'categories', 'brands', 'shops', 'sellers', 'products', 'storages', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        foreach (['product_brand' => '1', 'digital_product' => '0', 'business_mode' => 'multi', 'pagination_limit' => '25', 'stock_limit' => '10'] as $type => $value) {
            DB::table('business_settings')->insert(['type' => $type, 'value' => $value]);
        }

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('code')->nullable();
            $table->string('barcode')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('added_by')->default('admin');
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('sub_category_id')->nullable();
            $table->unsignedBigInteger('sub_sub_category_id')->nullable();
            $table->text('category_ids')->nullable();
            $table->text('bought_together_ids')->nullable();
            $table->string('product_type')->default('physical');
            $table->string('digital_product_type')->nullable();
            $table->boolean('status')->default(true);
            $table->integer('request_status')->default(1);
            $table->boolean('featured')->default(false);
            $table->boolean('featured_status')->default(false);
            $table->integer('current_stock')->default(5);
            $table->integer('minimum_order_qty')->default(1);
            $table->decimal('unit_price', 24, 2)->default(10);
            $table->decimal('purchase_price', 24, 2)->default(4);
            $table->decimal('discount', 24, 2)->default(0);
            $table->string('discount_type')->default('flat');
            $table->decimal('shipping_cost', 24, 2)->default(0);
            $table->decimal('temp_shipping_cost', 24, 2)->nullable();
            $table->boolean('is_shipping_cost_updated')->default(false);
            $table->boolean('multiply_qty')->default(false);
            $table->text('colors')->nullable();
            $table->text('attributes')->nullable();
            $table->text('choice_options')->nullable();
            $table->text('variation')->nullable();
            $table->text('details')->nullable();
            $table->text('denied_note')->nullable();
            $table->string('thumbnail')->nullable();
            $table->string('thumbnail_storage_type')->nullable();
            $table->text('images')->nullable();
            $table->text('color_image')->nullable();
            $table->string('unit')->nullable();
            $table->boolean('refundable')->default(false);
            $table->string('video_provider')->nullable();
            $table->string('video_url')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
        });
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->boolean('status')->default(true);
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->integer('qty')->default(1);
            $table->string('delivery_status')->nullable();
        });
        Schema::create('stock_clearance_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->boolean('is_active')->default(true);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_amount', 24, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('restock_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('variant')->nullable();
            $table->timestamps();
        });
        Schema::create('restock_product_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('restock_product_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('variant')->nullable();
            $table->timestamps();
        });
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('status')->nullable();
            $table->softDeletes();
        });
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('author_type')->default('vendor');
            $table->string('name')->nullable();
        });
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->boolean('status')->default(true);
        });
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('parent_id')->default(0);
            $table->integer('position')->default(0);
        });
        Schema::create('storages', function (Blueprint $table) {
            $table->id();
            $table->string('data_type')->nullable();
            $table->unsignedBigInteger('data_id')->nullable();
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
    }

    /** The pages ask for the tax system of the shop; the answer is remembered, so the tests give it instead of building the tax tables. */
    protected function primeTaxSystemCache(): void
    {
        Cache::flush();
        Cache::put('tax_system_type_vendor_with_vat', ['SystemTaxVat' => null, 'SystemTaxVatType' => 'order_wise', 'is_included' => 0, 'productWiseTax' => false, 'categoryWiseTax' => false, 'taxVats' => []]);
    }

    /** One product of a country, as the product screens read it. */
    protected function insertStaffProduct(int $id, string $country, array $extra = []): void
    {
        DB::table('products')->insert(array_merge([
            'id' => $id,
            'name' => "Product {$id} of {$country}",
            'slug' => "product-{$id}",
            'code' => "SKU{$id}",
            'added_by' => 'admin',
            'status' => 1,
            'request_status' => 1,
            'current_stock' => 5,
            'category_ids' => '[]',
            'colors' => '[]',
            'choice_options' => '[]',
            'variation' => '[]',
            'attributes' => '[]',
            'images' => '[]',
            'storefront_country' => $country,
        ], $extra));
    }

    protected function runStaffScopeMigration(): void
    {
        (require database_path('migrations/' . self::STAFF_SCOPE_MIGRATION))->up();
    }

    protected function rollBackStaffScopeMigration(): void
    {
        (require database_path('migrations/' . self::STAFF_SCOPE_MIGRATION))->down();
    }

    /** @param list<string> $countries stored as they are given: a test may hand over a value no command would accept */
    protected function giveCountries(int $adminId, array $countries): void
    {
        foreach ($countries as $country) {
            DB::table('admin_country_scopes')->insert(['admin_id' => $adminId, 'country' => $country, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function switchStaffScopesOn(): void
    {
        config(['gcc.staff.country_scopes' => true]);
    }

    protected function signInAsStaff(int $adminId): static
    {
        return $this->actingAs(Admin::query()->findOrFail($adminId), 'admin');
    }

    private function refuseToDropStaffTablesOutsideATestDatabase(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            return;
        }

        $database = (string) $connection->getDatabaseName();
        if (!app()->environment('testing') || !preg_match('/(^|_)test(s|ing)?($|_)/i', $database)) {
            throw new RuntimeException("Refusing to drop the admin tables of '{$database}': the test database name must contain 'test' and APP_ENV must be 'testing'.");
        }
    }
}
