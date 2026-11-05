<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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

        Schema::create('admins', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->timestamps();
        });
        \Illuminate\Support\Facades\DB::table('admins')->insert(['id' => 1, 'name' => 'Admin']);

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
