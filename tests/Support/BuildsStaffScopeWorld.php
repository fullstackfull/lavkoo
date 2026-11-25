<?php

namespace Tests\Support;

use App\Models\Admin;
use App\Services\AuditLogger;
use App\Services\Staff\StaffCountryScope;
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
 * marketplace (role 3) and 6 holds the orders only (role 4). 70 holds the catalog and marketing (role 50), which is what the promotion screens ask for.
 */
trait BuildsStaffScopeWorld
{
    private const STAFF_SCOPE_MIGRATION = '2026_11_28_100000_create_admin_country_scopes_table.php';

    /** The grant trail on a scope row (G1-14) and the audit trail every grant writes a line to (G1-08). */
    private const STAFF_TRAIL_MIGRATIONS = [
        '2026_12_29_100000_create_audit_logs_and_name_the_market_each_line_concerns.php',
        '2026_12_29_100100_add_grant_trail_to_admin_country_scopes.php',
    ];

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
            ['id' => 50, 'name' => 'Catalog and marketing', 'module_access' => json_encode(['catalog', 'marketing']), 'status' => 1],
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
            ['id' => 70, 'name' => 'Catalog and marketing', 'email' => 'seventy@example.test', 'admin_role_id' => 50],
        ]);
    }

    /**
     * The tables the product screens read for the tests that make real requests to them: the products with the column the real migration adds, what a list of
     * them loads (translations, reviews, clearance offers), the restock requests, and the sellers, shops, brands and categories of the list page.
     */
    protected function buildStaffProductTables(): void
    {
        $this->refuseToDropStaffTablesOutsideATestDatabase();

        foreach (['digital_product_variations', 'restock_product_customers', 'restock_products', 'stock_clearance_products', 'order_details', 'reviews', 'translations', 'categories', 'brands', 'shops', 'sellers', 'products', 'storages', 'business_settings'] as $table) {
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
        Schema::create('digital_product_variations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('file')->nullable();
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
    /**
     * The tables the promotion screens of slice 4 read: the deals and what is in them, the deal of the day, the most-demanded entries, the
     * clearance setup and the banners, plus the orders the in-house shop page counts. `buildStaffProductTables()` runs first and builds the
     * products, the reviews and the clearance offers these hang off.
     *
     * `most_demandeds` is built from its REAL migration, which is the only one of these tables this repository creates; the rest are
     * inferred from their models, as the products and the admins are, because the installation dump is not in the repository.
     */
    protected function buildStaffPromotionTables(): void
    {
        $this->refuseToDropStaffTablesOutsideATestDatabase();

        foreach (['flash_deal_products', 'flash_deals', 'deal_of_the_days', 'most_demandeds', 'banners', 'stock_clearance_setups', 'seo_meta_info', 'orders'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('flash_deals', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('slug')->nullable();
            $table->string('deal_type')->default('flash_deal');
            $table->boolean('status')->default(true);
            $table->boolean('featured')->default(false);
            $table->string('banner')->nullable();
            $table->string('background_color')->nullable();
            $table->string('text_color')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });

        Schema::create('flash_deal_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('flash_deal_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('discount', 24, 2)->default(0);
            $table->string('discount_type')->default('flat');
            $table->timestamps();
        });

        Schema::create('deal_of_the_days', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->decimal('discount', 24, 2)->default(0);
            $table->string('discount_type')->default('flat');
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        // The one of these tables this repository creates, so it is built from its real migration. It is an old-style migration that
        // declares a NAMED class, so it is required once per process and instantiated: `require`ing it a second time is a fatal error.
        require_once database_path('migrations/2023_07_08_210747_create_most_demandeds_table.php');
        (new \CreateMostDemandedsTable())->up();

        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('theme')->nullable();
            $table->string('photo')->nullable();
            $table->string('mobile_photo')->nullable();
            $table->string('url')->nullable();
            $table->string('title')->nullable();
            $table->string('sub_title')->nullable();
            $table->string('button_text')->nullable();
            $table->string('background_color')->nullable();
            $table->string('layout')->nullable();
            $table->integer('priority')->nullable();
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('banner_type')->nullable();
            $table->boolean('published')->default(true);
            $table->timestamps();
        });

        Schema::create('stock_clearance_setups', function (Blueprint $table) {
            $table->id();
            $table->string('setup_by')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_amount', 24, 2)->default(0);
            $table->boolean('show_in_homepage')->default(false);
            $table->boolean('show_in_shop')->default(false);
            $table->date('duration_start_date')->nullable();
            $table->date('duration_end_date')->nullable();
            $table->string('offer_active_time')->nullable();
            $table->time('offer_active_range_start')->nullable();
            $table->time('offer_active_range_end')->nullable();
            $table->timestamps();
        });

        // The clearance screens filter their offers by who made them and read the setup each belongs to; `buildStaffProductTables()` builds
        // the offers for the product screens, which need neither. The clearance list also averages the rating of each product.
        Schema::table('stock_clearance_products', function (Blueprint $table) {
            $table->string('added_by')->nullable();
            $table->unsignedBigInteger('setup_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
        });
        Schema::table('reviews', function (Blueprint $table) {
            $table->integer('rating')->default(5);
        });

        // Two settings these screens read without a default of their own: the deal list dies on a missing `flash_deal_priority`, and the
        // in-house shop object reads `vacation_add` as an array.
        DB::table('business_settings')->insert([
            ['type' => 'flash_deal_priority', 'value' => json_encode([])],
            ['type' => 'vacation_add', 'value' => json_encode(['status' => 0, 'vacation_note' => '', 'vacation_start_date' => null, 'vacation_end_date' => null])],
        ]);

        Schema::create('seo_meta_info', function (Blueprint $table) {
            $table->id();
            $table->string('seoable_type');
            $table->unsignedBigInteger('seoable_id');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->default('admin');
            $table->string('order_status')->default('pending');
            $table->string('payment_status')->default('unpaid');
            $table->decimal('order_amount', 24, 2)->default(0);
            $table->timestamps();
        });
    }

    /** One order of one product, as the in-house shop page counts it: an order follows the country of the products of its lines. */
    protected function insertStaffOrderOfProduct(int $orderId, int $productId, array $extra = []): void
    {
        DB::table('orders')->insert(array_merge(['id' => $orderId, 'seller_is' => 'admin', 'seller_id' => 1, 'order_status' => 'delivered'], $extra));
        DB::table('order_details')->insert(['order_id' => $orderId, 'product_id' => $productId]);
    }

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
        $this->runStaffTrailMigrations();
    }

    /**
     * The audit trail and the grant trail, run beside the scope table: a grant and a revoke record a line through `recordOrFail()`, so a
     * world that runs those commands without `audit_logs` would have them refuse rather than record.
     */
    protected function runStaffTrailMigrations(): void
    {
        foreach (self::STAFF_TRAIL_MIGRATIONS as $file) {
            (require database_path('migrations/' . $file))->up();
        }

        AuditLogger::forget();
        StaffCountryScope::forgetTheSchema();
    }

    protected function rollBackStaffScopeMigration(): void
    {
        Schema::dropIfExists('audit_logs');
        (require database_path('migrations/' . self::STAFF_SCOPE_MIGRATION))->down();
        AuditLogger::forget();
        StaffCountryScope::forgetTheSchema();
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
