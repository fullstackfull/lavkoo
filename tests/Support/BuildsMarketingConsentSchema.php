<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The marketing-consent tests' database: an INFERRED copy of the store tables the marketing senders read, then the
 * feature's REAL migration on top.
 *
 * Same limits as BuildsAddressSchema and BuildsFulfillmentSchema, and the same reason: the installation dump that owns
 * `users`, `carts`, `orders`, `shipping_addresses`, `products` and `subscriptions` is not in this repository, and
 * `abandoned_cart_reminders` is created by NO migration anywhere in the project either. So the shape here is inferred
 * from the models and the queries that read them. This proves the feature's logic; it does not prove the live schema.
 *
 * `customer_marketing_consents` is the exception, and the one that matters: it is built by running the real migration
 * file, so the columns, the indexes and the refusal in down() are the ones that would reach a server.
 */
trait BuildsMarketingConsentSchema
{
    public const CONSENT_MIGRATION = '2026_11_23_100000_create_customer_marketing_consents_table.php';

    protected function buildMarketingConsentSchema(): void
    {
        $this->startFromAnEmptyTestDatabase();
        $this->createInferredStoreTables();
        $this->runConsentMigration();
    }

    /** The feature's REAL migration, so what the tests read is what a server would get. */
    protected function runConsentMigration(): void
    {
        (require database_path('migrations/' . self::CONSENT_MIGRATION))->up();
    }

    protected function consentMigration(): object
    {
        return require database_path('migrations/' . self::CONSENT_MIGRATION);
    }

    /**
     * SQLite starts empty every time. CI also runs these tests against real MySQL and MariaDB, whose tables survive
     * between tests, so it is emptied first — but only when the database is unmistakably a throwaway.
     */
    private function startFromAnEmptyTestDatabase(): void
    {
        Cache::flush();

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

    private function createInferredStoreTables(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            // What the push path reads off a customer: the device to send to and the language to send in.
            $table->string('cm_firebase_token')->nullable();
            $table->string('app_language')->nullable();
            $table->timestamps();
        });

        Schema::create('shipping_addresses', function (Blueprint $table) {
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
            $table->boolean('is_billing')->default(false);
            $table->timestamps();
        });

        // `sellers` is never read by these tests, but Product::scopeSellerApproved builds a subquery against it, so the
        // table has to exist for the cart's product filter to run at all.
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('status')->nullable();
            $table->timestamps();
            // Seller is soft-deleted, and Product::scopeSellerApproved's subquery names the column.
            $table->softDeletes();
        });

        // Written by the web middleware for an anonymous visitor, so any test that drives a web route needs it.
        Schema::create('guest_users', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('added_by')->default('admin');
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('product_type')->default('physical');
            $table->tinyInteger('status')->default(1);
            $table->tinyInteger('request_status')->default(1);
            $table->timestamps();
        });

        // Money in a rendered message goes through webCurrencyConverter, which reads the currency list.
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code')->nullable();
            $table->decimal('exchange_rate', 24, 3)->default(1);
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });

        // Product eager-loads its reviews, so rendering a message that lists a cart line reaches this table.
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('cart_group_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('name')->nullable();
            $table->string('thumbnail')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('price', 40, 20)->default(0);
            $table->decimal('discount', 40, 20)->default(0);
            $table->decimal('tax', 40, 20)->default(0);
            $table->boolean('is_checked')->default(true);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('order_status')->nullable();
            $table->string('payment_status')->nullable();
            $table->timestamps();
        });

        Schema::create('abandoned_cart_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('cart_group_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('email');
            $table->integer('stage')->default(1);
            $table->decimal('cart_value', 24, 3)->default(0);
            $table->integer('item_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->unsignedBigInteger('recovered_order_id')->nullable();
            $table->timestamps();
            $table->unique(['cart_group_id', 'stage']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        $this->createNecessaryNotificationTables();
    }

    /**
     * What the messages that must NEVER depend on a marketing consent are built from: the e-mail templates the
     * one-time-code and payment listeners read, and the push texts the order-status and refund listeners read.
     *
     * Shaped from the project's own create migrations for `email_templates` (2024_04_03_093637),
     * `notification_messages` (2023_10_07_182714) and `storages` (2024_05_30_101603); `translations` and
     * `social_media` belong to the installation dump and are inferred from the models that read them.
     */
    private function createNecessaryNotificationTables(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_name');
            $table->string('user_type');
            $table->string('template_design_name');
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->string('banner_image')->nullable();
            $table->string('image')->nullable();
            $table->string('logo')->nullable();
            $table->string('button_name')->nullable();
            $table->string('button_url')->nullable();
            $table->string('footer_text')->nullable();
            $table->string('copyright_text')->nullable();
            $table->text('pages')->nullable();
            $table->text('social_media')->nullable();
            $table->text('hide_field')->nullable();
            $table->tinyInteger('button_content_status')->default(1);
            $table->tinyInteger('product_information_status')->default(1);
            $table->tinyInteger('order_information_status')->default(1);
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('notification_messages', function (Blueprint $table) {
            $table->id();
            $table->string('user_type')->nullable();
            $table->string('key')->nullable();
            $table->text('message')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('translationable_type')->nullable();
            $table->unsignedBigInteger('translationable_id')->nullable();
            $table->string('locale')->nullable();
            $table->string('key')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('social_medias', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('link')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
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
}
