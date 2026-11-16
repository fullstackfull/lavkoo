<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The seller accounts a registration or a sign-in touches, on top of the core tables.
 *
 * `sellers` and `seller_wallets` come from the installation dump, not from a migration, so they are inferred here with the columns the
 * seller flows read. The core tables are built by BuildsFulfillmentSchema, which refuses to drop anything unless APP_ENV is 'testing' and
 * the database name contains 'test'; nothing in this trait drops a table of its own.
 */
trait BuildsSellerSchema
{
    use BuildsFulfillmentSchema;

    protected function buildSellerSchema(): void
    {
        $this->buildCoreSchema();

        $registrationShopColumns = array_fill_keys(
            ['slug', 'address', 'contact', 'image', 'banner', 'bottom_banner', 'image_storage_type', 'banner_storage_type', 'bottom_banner_storage_type', 'tax_identification_number', 'tin_certificate', 'tin_certificate_storage_type'],
            'string',
        ) + ['tin_expire_date' => 'date'];
        $missingShopColumns = array_filter($registrationShopColumns, static fn (string $type, string $column): bool => !Schema::hasColumn('shops', $column), ARRAY_FILTER_USE_BOTH);
        Schema::table('shops', function (Blueprint $table) use ($missingShopColumns) {
            foreach ($missingShopColumns as $column => $type) {
                $table->{$type}($column)->nullable();
            }
        });

        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('image')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('pending');
            $table->string('auth_token')->nullable();
            $table->string('cm_firebase_token')->nullable();
            $table->string('cm_firebase_token_web')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('seller_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->decimal('withdrawn', 24, 2)->default(0);
            $table->decimal('commission_given', 24, 2)->default(0);
            $table->decimal('total_earning', 24, 2)->default(0);
            $table->decimal('pending_withdraw', 24, 2)->default(0);
            $table->decimal('delivery_charge_earned', 24, 2)->default(0);
            $table->decimal('collected_cash', 24, 2)->default(0);
            $table->timestamps();
        });
    }
}
