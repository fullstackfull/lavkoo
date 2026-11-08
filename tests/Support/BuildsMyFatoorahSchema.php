<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The tables the MyFatoorah integration touches, built for an in-memory database.
 *
 * `payment_requests`, `addon_settings`, `orders` and `currencies` live in the installed SQL dump rather
 * than in migrations, so they are inferred here with the columns this feature actually reads. The
 * feature's own four tables come from their real migrations, so a test failing because a migration is
 * wrong is a test doing its job.
 */
trait BuildsMyFatoorahSchema
{
    protected function buildMyFatoorahSchema(): void
    {
        // Shared gateway settings table. `live_values`/`test_values` are JSON text; the Setting model
        // casts them to arrays.
        Schema::dropIfExists('addon_settings');
        Schema::create('addon_settings', function (Blueprint $t): void {
            $t->string('id', 36)->primary();
            $t->string('key_name')->nullable();
            $t->text('live_values')->nullable();
            $t->text('test_values')->nullable();
            $t->string('settings_type')->nullable();
            $t->string('mode')->default('test');
            $t->integer('is_active')->default(0);
            $t->text('additional_data')->nullable();
            $t->timestamps();
        });

        // The gateway-agnostic payment ledger every gateway in this codebase writes to.
        Schema::dropIfExists('payment_requests');
        Schema::create('payment_requests', function (Blueprint $t): void {
            $t->string('id', 36)->primary();
            $t->string('payer_id')->nullable();
            $t->string('receiver_id')->nullable();
            $t->decimal('payment_amount', 24, 2)->default(0);
            $t->string('currency_code', 8)->nullable();
            $t->string('payment_method')->nullable();
            $t->string('success_hook')->nullable();
            $t->string('failure_hook')->nullable();
            $t->text('additional_data')->nullable();
            $t->text('payer_information')->nullable();
            $t->text('receiver_information')->nullable();
            $t->text('external_redirect_link')->nullable();
            $t->string('attribute')->nullable();
            $t->string('attribute_id')->nullable();
            $t->string('payment_platform')->nullable();
            $t->boolean('is_paid')->default(false);
            $t->string('transaction_id')->nullable();
            $t->timestamps();
        });

        if (!Schema::hasTable('currencies')) {
            Schema::create('currencies', function (Blueprint $t): void {
                $t->id();
                $t->string('name')->nullable();
                $t->string('symbol')->nullable();
                $t->string('code', 8);
                $t->decimal('exchange_rate', 24, 8)->default(1);
                $t->boolean('status')->default(true);
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('business_settings')) {
            Schema::create('business_settings', function (Blueprint $t): void {
                $t->id();
                $t->string('type')->nullable();
                $t->longText('value')->nullable();
                $t->timestamps();
            });
        }

        // Only the columns the settlement path reads. `transaction_ref` is the one that matters: it is
        // the idempotency boundary — an order carrying it proves the success hook already ran.
        if (!Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $t): void {
                $t->unsignedBigInteger('id')->primary();
                $t->unsignedBigInteger('customer_id')->nullable();
                $t->string('order_status')->default('pending');
                $t->string('payment_status')->default('unpaid');
                $t->string('payment_method')->nullable();
                $t->string('transaction_ref')->nullable();
                $t->decimal('order_amount', 24, 2)->default(0);
                $t->string('seller_is')->default('admin');
                $t->timestamps();
            });
        } elseif (!Schema::hasColumn('orders', 'transaction_ref')) {
            Schema::table('orders', function (Blueprint $t): void {
                $t->string('transaction_ref')->nullable();
            });
        }

        $this->runMyFatoorahMigrations();
    }

    protected function runMyFatoorahMigrations(): void
    {
        foreach ([
            '2026_11_08_100001_create_myfatoorah_payment_attempts_table.php',
            '2026_11_08_100002_create_myfatoorah_webhook_events_table.php',
            '2026_11_08_100003_create_myfatoorah_refunds_table.php',
            '2026_11_08_100004_register_myfatoorah_payment_gateway.php',
            '2026_11_08_100005_add_hook_lease_to_myfatoorah_attempts.php',
        ] as $file) {
            (require database_path('migrations/' . $file))->up();
        }
    }

    /** SAR, switched on, so the currency check passes. */
    protected function sarIsActive(): void
    {
        DB::table('currencies')->insertOrIgnore([
            'id' => 1,
            'name' => 'Saudi Riyal',
            'symbol' => 'SAR',
            'code' => 'SAR',
            'exchange_rate' => 1,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The store-wide digital-payment switch that gates every gateway. */
    protected function digitalPaymentOn(bool $on = true): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => 'digital_payment'],
            ['value' => json_encode(['status' => $on ? 1 : 0]), 'updated_at' => now()],
        );
    }

    /**
     * A payment_requests row, as the checkout would have written it: the amount already computed on the
     * server from the cart.
     */
    protected function makePaymentRequest(array $overrides = []): string
    {
        $id = (string) ($overrides['id'] ?? Str::uuid());

        DB::table('payment_requests')->insert(array_merge([
            'id' => $id,
            'payer_id' => '7',
            'receiver_id' => '100',
            'payment_amount' => 150.00,
            'currency_code' => 'SAR',
            'payment_method' => 'fatoorah',
            'success_hook' => 'myfatoorah_test_success_hook',
            'failure_hook' => 'myfatoorah_test_failure_hook',
            'additional_data' => json_encode(['customer_id' => 7, 'order_note' => null]),
            'payer_information' => json_encode(['name' => 'Test Customer', 'email' => 'c@example.com', 'phone' => '0551234567']),
            'receiver_information' => json_encode(['name' => 'receiver']),
            'external_redirect_link' => null,
            'attribute' => 'order',
            'attribute_id' => '12345',
            'payment_platform' => 'web',
            'is_paid' => false,
            'transaction_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }
}
