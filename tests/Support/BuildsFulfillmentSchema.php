<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * An INFERRED minimal schema for the core store tables this feature reads.
 *
 * The real `products`, `orders` and `order_details` tables come from the proprietary installation dump,
 * which is not in the repository (see app/Console/Commands/BootstrapTestSchema.php). These definitions are
 * reconstructed from the models and from the code that writes them; they hold only the columns the
 * fulfillment feature touches. They prove the feature's own logic. They do NOT prove behaviour against the
 * live schema — that needs a staging database restored from the real dump.
 *
 * The feature's own tables are created by the REAL migrations, not redefined here.
 */
trait BuildsFulfillmentSchema
{
    protected function buildCoreSchema(): void
    {
        $this->startFromAnEmptyTestDatabase();

        Schema::create('business_settings', function (Blueprint $t) {
            $t->id();
            $t->string('type')->nullable();
            $t->longText('value')->nullable();
            $t->timestamps();
        });

        Schema::create('currencies', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('symbol', 20)->nullable();
            $t->string('code', 20)->nullable();
            $t->decimal('exchange_rate', 20, 8)->default(1);
            $t->boolean('status')->default(1);
            $t->timestamps();
        });

        Schema::create('products', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->string('code')->nullable();
            $t->string('barcode')->nullable();
            $t->string('added_by')->default('admin');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('product_type')->default('physical');
            $t->string('digital_product_type')->nullable();
            $t->string('digital_file_ready')->nullable();
            $t->text('digital_product_file_types')->nullable();
            $t->text('digital_product_extensions')->nullable();
            $t->text('category_ids')->nullable();
            $t->unsignedBigInteger('category_id')->nullable();
            $t->unsignedBigInteger('sub_category_id')->nullable();
            $t->unsignedBigInteger('sub_sub_category_id')->nullable();
            $t->unsignedBigInteger('brand_id')->nullable();
            $t->string('unit')->nullable();
            $t->longText('details')->nullable();
            $t->text('colors')->nullable();
            $t->text('choice_options')->nullable();
            $t->text('attributes')->nullable();
            $t->decimal('unit_price', 40, 20)->default(0);
            $t->decimal('purchase_price', 40, 20)->default(0);
            $t->decimal('tax', 40, 20)->default(0);
            $t->string('tax_type')->nullable();
            $t->string('tax_model')->nullable();
            $t->decimal('discount', 40, 20)->default(0);
            $t->string('discount_type')->nullable();
            $t->integer('current_stock')->default(0);
            $t->integer('minimum_order_qty')->default(1);
            $t->string('video_provider')->nullable();
            $t->string('video_url')->nullable();
            $t->longText('variation')->nullable();
            $t->integer('status')->default(1);
            $t->integer('request_status')->default(1);
            $t->decimal('shipping_cost', 40, 20)->default(0);
            $t->integer('multiply_qty')->default(0);
            $t->text('color_image')->nullable();
            $t->longText('images')->nullable();
            $t->string('thumbnail')->nullable();
            $t->string('thumbnail_storage_type')->nullable();
            $t->string('meta_title')->nullable();
            $t->timestamps();
        });

        Schema::create('shops', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('seller_id')->nullable();
            $t->string('author_type')->default('admin');
            $t->string('name')->nullable();
            $t->timestamps();
        });
        \Illuminate\Support\Facades\DB::table('shops')->insert(['id' => 1, 'author_type' => 'admin', 'name' => 'In-house shop']);

        Schema::create('admin_roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->text('module_access')->nullable();
            $t->boolean('status')->default(1);
            $t->timestamps();
        });
        \Illuminate\Support\Facades\DB::table('admin_roles')->insert([
            ['id' => 1, 'name' => 'Master Admin', 'module_access' => null, 'status' => 1],
            ['id' => 2, 'name' => 'Catalog only', 'module_access' => json_encode(['catalog']), 'status' => 1],
            ['id' => 3, 'name' => 'Third-party setup', 'module_access' => json_encode(['3rd_party_setup']), 'status' => 1],
        ]);

        Schema::create('admins', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->unsignedBigInteger('admin_role_id')->default(1);
            $t->integer('status')->default(1);
            $t->timestamps();
        });
        \Illuminate\Support\Facades\DB::table('admins')->insert([
            ['id' => 1, 'name' => 'Admin', 'email' => 'master@example.test', 'admin_role_id' => 1],
            ['id' => 2, 'name' => 'Catalog admin', 'email' => 'catalog@example.test', 'admin_role_id' => 2],
            ['id' => 3, 'name' => 'Integrations admin', 'email' => 'integrations@example.test', 'admin_role_id' => 3],
        ]);

        Schema::create('storages', function (Blueprint $t) {
            $t->id();
            $t->string('data_type');
            $t->unsignedBigInteger('data_id');
            $t->string('key')->nullable();
            $t->string('value')->nullable();
            $t->timestamps();
        });

        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->unsignedBigInteger('parent_id')->default(0);
            $t->integer('position')->default(0);
            $t->timestamps();
        });

        Schema::create('translations', function (Blueprint $t) {
            $t->id();
            $t->string('translationable_type');
            $t->unsignedBigInteger('translationable_id');
            $t->string('locale');
            $t->string('key');
            $t->text('value')->nullable();
            $t->timestamps();
        });

        Schema::create('attributes', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('orders', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->boolean('is_guest')->default(false);
            $t->string('order_status')->default('pending');
            $t->string('payment_status')->default('unpaid');
            $t->string('payment_method')->nullable();
            $t->decimal('order_amount', 40, 20)->default(0);
            $t->decimal('paid_amount', 40, 20)->default(0);
            $t->decimal('shipping_cost', 40, 20)->default(0);
            $t->string('order_type')->default('default_type');
            $t->boolean('is_pause')->default(false);
            $t->text('order_note')->nullable();
            $t->longText('shipping_address_data')->nullable();
            $t->string('seller_is')->default('admin');
            $t->unsignedBigInteger('seller_id')->nullable();
            $t->timestamps();
        });

        Schema::create('order_details', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('seller_id')->nullable();
            $t->longText('product_details')->nullable();
            $t->integer('qty')->default(1);
            $t->decimal('price', 40, 20)->default(0);
            $t->decimal('discount', 40, 20)->default(0);
            $t->decimal('tax', 40, 20)->default(0);
            $t->string('tax_model')->nullable();
            $t->string('variant')->nullable();
            $t->longText('variation')->nullable();
            $t->string('delivery_status')->default('pending');
            $t->string('payment_status')->default('unpaid');
            $t->timestamps();
        });
    }

    /**
     * The default test database is an in-memory SQLite connection that starts empty. CI also runs these
     * tests on a real MySQL service, whose tables survive between tests, so it is emptied first - but only
     * if it is unmistakably a throwaway database. Never drops anything on a database that is not.
     */
    private function startFromAnEmptyTestDatabase(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            return;
        }

        $database = (string) $connection->getDatabaseName();
        if (!app()->environment('testing') || !preg_match('/(^|_)test(s|ing)?($|_)/i', $database)) {
            throw new RuntimeException("Refusing to drop every table of '{$database}': the test database name must contain 'test' and APP_ENV must be 'testing'.");
        }

        Schema::dropAllTables();
    }

    /** Run the feature's REAL migrations against the in-memory database. */
    protected function runFulfillmentMigrations(): void
    {
        foreach ([
            '2026_11_05_100000_create_provider_fulfillment_tables.php',
            '2026_11_05_100100_add_fulfillment_provider_to_products_table.php',
        ] as $file) {
            (require database_path('migrations/' . $file))->up();
        }
    }
}
