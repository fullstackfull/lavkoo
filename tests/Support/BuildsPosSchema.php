<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The store tables the two point-of-sale tills write when a sale is placed.
 *
 * Like the other schema builders in this directory these are INFERRED from the models and from the code that writes them —
 * the installation dump is not in the repository — and hold only the columns the till path reads or writes.
 */
trait BuildsPosSchema
{
    /**
     * A real engine keeps the tables between tests, so each test starts by dropping them.
     *
     * The same guard the address tests use: the name of the database must contain "test" and the application must be in its
     * testing environment, or nothing is dropped.
     */
    protected function resetPosTestDatabase(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite') {
            $database = (string) $connection->getDatabaseName();
            if (!app()->environment('testing') || !preg_match('/(^|_)test(s|ing)?($|_)/i', $database)) {
                throw new RuntimeException("Refusing to drop every table of '{$database}': the test database name must contain 'test' and APP_ENV must be 'testing'.");
            }
            Schema::dropAllTables();
        }

        Cache::flush();
    }

    protected function createPosStoreTables(): void
    {
        $this->resetPosTestDatabase();

        $this->createPosTable('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->decimal('wallet_balance', 16, 2)->default(0);
            $table->decimal('loyalty_point', 16, 2)->default(0);
            $table->string('street_address')->nullable();
            $table->string('city')->nullable();
            $table->string('zip')->nullable();
            $table->string('country')->nullable();
            $table->string('house_no')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $this->createPosTable('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('status')->default('approved');
            $table->timestamps();
            $table->softDeletes();
        });

        $this->createPosTable('shops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('author_type')->default('vendor');
            $table->string('name')->nullable();
            $table->boolean('temporary_close')->default(false);
            $table->boolean('vacation_status')->default(false);
            $table->timestamps();
        });

        $this->createPosTable('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('code')->nullable();
            $table->string('barcode')->nullable();
            $table->string('added_by')->default('admin');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->default('admin');
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('product_type')->default('physical');
            $table->string('digital_product_type')->nullable();
            $table->string('digital_file_ready')->nullable();
            $table->string('digital_file_ready_storage_type')->nullable();
            $table->integer('current_stock')->default(0);
            $table->integer('minimum_order_qty')->default(1);
            $table->longText('variation')->nullable();
            $table->longText('choice_options')->nullable();
            $table->longText('colors')->nullable();
            $table->integer('status')->default(1);
            $table->integer('request_status')->default(1);
            $table->decimal('unit_price', 16, 2)->default(10);
            $table->decimal('purchase_price', 16, 2)->default(0);
            $table->decimal('tax', 16, 2)->default(0);
            $table->string('tax_type')->nullable();
            $table->string('tax_model')->nullable();
            $table->decimal('discount', 16, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('shipping_cost', 16, 2)->default(0);
            $table->integer('multiply_qty')->default(0);
            $table->text('category_ids')->nullable();
            $table->string('thumbnail')->nullable();
            $table->string('thumbnail_storage_type')->nullable();
            $table->longText('images')->nullable();
            $table->string('meta_image')->nullable();
            $table->string('preview_file')->nullable();
            $table->timestamps();
        });

        $this->createPosTable('shipping_addresses', function (Blueprint $table) {
            $table->id();
            $table->string('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('contact_person_name')->nullable();
            $table->string('email')->nullable();
            $table->string('address_type')->default('home');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('zip')->nullable();
            $table->string('phone')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('house_no')->nullable();
            $table->string('floor')->nullable();
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
            $table->boolean('is_billing')->default(false);
            $table->timestamps();
        });

        $this->createPosTable('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('order_group_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_type')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('order_status')->default('pending');
            $table->string('payment_status')->default('unpaid');
            $table->string('payment_method')->nullable();
            $table->string('transaction_ref')->nullable();
            $table->string('order_type')->default('default_type');
            $table->decimal('order_amount', 16, 2)->default(0);
            $table->decimal('paid_amount', 16, 2)->default(0);
            $table->decimal('discount_amount', 16, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->string('coupon_code')->nullable();
            $table->string('coupon_discount_bearer')->default('inhouse');
            $table->decimal('total_tax_amount', 16, 2)->default(0);
            $table->string('tax_type')->nullable();
            $table->string('tax_model')->nullable();
            $table->decimal('init_order_amount', 16, 2)->default(0);
            $table->decimal('shipping_cost', 16, 2)->default(0);
            $table->decimal('extra_discount', 16, 2)->default(0);
            $table->string('extra_discount_type')->nullable();
            $table->unsignedBigInteger('shipping_address')->nullable();
            $table->longText('shipping_address_data')->nullable();
            $table->unsignedBigInteger('billing_address')->nullable();
            $table->longText('billing_address_data')->nullable();
            $table->string('shipping_responsibility')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->default('admin');
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->string('delivery_type')->nullable();
            $table->string('order_note')->nullable();
            $table->string('checked')->nullable();
            $table->string('verification_code')->nullable();
            $table->boolean('is_pause')->default(false);
            $table->timestamps();
        });

        $this->createPosTable('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->longText('product_details')->nullable();
            $table->integer('qty')->default(0);
            $table->decimal('price', 16, 2)->default(0);
            $table->decimal('tax', 16, 2)->default(0);
            $table->string('tax_model')->nullable();
            $table->decimal('discount', 16, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->string('delivery_status')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('variant')->nullable();
            $table->longText('variation')->nullable();
            $table->unsignedBigInteger('shipping_method_id')->nullable();
            $table->timestamps();
        });

        $this->createPosTable('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('reference')->nullable();
            $table->string('transaction_type')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('credit', 16, 2)->default(0);
            $table->decimal('debit', 16, 2)->default(0);
            $table->decimal('admin_bonus', 16, 2)->default(0);
            $table->decimal('balance', 16, 2)->default(0);
            $table->timestamps();
        });

        $this->createPosTable('digital_product_variations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('variant_key')->nullable();
            $table->decimal('price', 16, 2)->default(0);
            $table->string('file')->nullable();
            $table->timestamps();
        });

        $this->createPosTable('storages', function (Blueprint $table) {
            $table->id();
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });

        $this->createPosTable('colors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->timestamps();
        });

        $this->createPosTable('translations', function (Blueprint $table) {
            $table->id();
            $table->string('translationable_type')->nullable();
            $table->unsignedBigInteger('translationable_id')->nullable();
            $table->string('locale')->nullable();
            $table->string('key')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        $this->createPosTable('reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        // The column widths are BuildsPlatformProcurementSchema's, the repository's other definition of this table: an
        // unbounded string() here would pass a value the real column cannot hold.
        $this->createPosTable('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('type', 24);
            $table->integer('qty_change');
            $table->integer('balance_after')->nullable();
            $table->string('reason', 40)->nullable();
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_type', 24)->nullable();
            $table->timestamps();
        });

        if (!Schema::hasTable('business_settings')) {
            Schema::create('business_settings', function (Blueprint $table) {
                $table->id();
                $table->string('type')->nullable();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
        }

        $this->createPosTable('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol', 20)->nullable();
            $table->string('code', 20)->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        if (DB::table('currencies')->where('id', 1)->doesntExist()) {
            DB::table('currencies')->insert(['id' => 1, 'name' => 'Saudi Riyal', 'code' => 'SAR', 'symbol' => 'SAR', 'exchange_rate' => 1, 'status' => 1]);
        }

        foreach ([
            'currency_model' => json_encode('single_currency'),
            'system_default_currency' => json_encode(1),
            'pagination_limit' => json_encode(10),
            'wallet_status' => 1,
            'loyalty_point_status' => 0,
            'company_name' => json_encode('LAVKOO'),
        ] as $type => $value) {
            if (DB::table('business_settings')->where('type', $type)->doesntExist()) {
                DB::table('business_settings')->insert(['type' => $type, 'value' => $value]);
            }
        }

        if (DB::table('shops')->where('id', 1)->doesntExist()) {
            DB::table('shops')->insert(['id' => 1, 'seller_id' => 0, 'author_type' => 'admin', 'name' => 'In-house shop']);
        }

        $this->createTaxModuleTables();
        $this->runStoreMigrations([
            'database/migrations/2024_11_02_075917_create_stock_clearance_setups_table.php',
            'database/migrations/2024_11_02_075931_create_stock_clearance_products_table.php',
        ]);

        Cache::flush();
    }

    /**
     * The tax module's own tables, from its own migrations.
     *
     * The till calls getTaxSystemType() on every sale and the module is published by a file in the repository, so these are
     * not optional. They come from the real migrations rather than an inferred copy: the till writes an order_taxes row per
     * applied rate, and the columns it writes have to be the ones the module ships.
     */
    private function createTaxModuleTables(): void
    {
        if (Schema::hasTable('system_tax_setups')) {
            return;
        }

        $this->artisan('migrate', ['--path' => 'Modules/TaxModule/database/migrations', '--force' => true])->run();
    }

    /**
     * Named store migrations, run from their own files.
     *
     * The clearance-sale tables are eager-loaded by the relation the till asks the product repository for, so the sale cannot be
     * placed without them. They come from their real migrations for the same reason the tax tables do.
     *
     * @param list<string> $paths
     */
    private function runStoreMigrations(array $paths): void
    {
        foreach ($paths as $path) {
            $this->artisan('migrate', ['--path' => $path, '--force' => true])->run();
        }
    }

    private function createPosTable(string $name, callable $definition): void
    {
        if (!Schema::hasTable($name)) {
            Schema::create($name, $definition);
        }
    }
}
