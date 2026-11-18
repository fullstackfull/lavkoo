<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tables the search box of the admin header reads, on top of the core store tables of BuildsFulfillmentSchema.
 *
 * Like the other schema builders these are INFERRED from the models and from the columns the search reads: `users`, `sellers`, `brands`, `coupons`, `contacts`
 * and `blogs` come from the installation dump, which is not in the repository, and `delivery_men`, `subscriptions` and `refund_requests` have migrations that
 * declare a named class, which a second test of the same process could not require again. `business_pages` and `recent_searches` are created by their REAL
 * migrations. The tables hold only the columns the search touches: they prove its logic, not the live schema.
 */
trait BuildsAdvancedSearchSchema
{
    protected function buildAdvancedSearchSchema(): void
    {
        $this->buildCoreSchema();

        Schema::table('products', static function (Blueprint $table): void {
            $table->text('meta_description')->nullable();
        });
        Schema::table('orders', static function (Blueprint $table): void {
            $table->string('order_group_id')->nullable();
        });

        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
        });
        Schema::create('sellers', static function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('image')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('brands', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
        });
        Schema::create('coupons', static function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->string('code')->nullable();
            $table->timestamps();
        });
        Schema::create('contacts', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('subject')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
        Schema::create('blogs', static function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->string('slug')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
        });
        Schema::create('blog_translations', static function (Blueprint $table): void {
            $table->id();
            $table->string('translation_type');
            $table->unsignedBigInteger('translation_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
            $table->boolean('is_draft')->default(false);
            $table->timestamps();
        });

        Schema::create('delivery_men', static function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('image')->nullable();
            $table->string('identity_image')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });
        Schema::create('subscriptions', static function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->timestamps();
        });
        Schema::create('refund_requests', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('status')->nullable();
            $table->text('refund_reason')->nullable();
            $table->text('images')->nullable();
            $table->timestamps();
        });

        foreach (['2025_03_08_201607_create_business_pages_table.php', '2025_05_26_205629_create_recent_searches_table.php'] as $migration) {
            (require database_path('migrations/' . $migration))->up();
        }
    }
}
