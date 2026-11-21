<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A company's own account at a payment gateway (D2-07, D2-08, D2-09): one row for each country, gateway and environment, holding the credentials that
 * company's payments are taken with and the webhook settings its notifications are authenticated by.
 *
 * Additive: a new table that nothing reads until GCC_PAYMENT_ACCOUNTS is on, and that ships empty. While a country has no row, the one platform-wide
 * addon_settings row answers for it, which is how Saudi Arabia keeps working with an empty table (GCC-19). The credentials themselves are facts the
 * owner supplies from each gateway's portal; this migration invents none and stores none.
 *
 * `credentials` and `webhook_secret` hold ciphertext written by App\Services\Payments\GatewayAccountSecrets, never a plain value: a database dump of
 * this table is of no use to anybody. There is deliberately no plaintext column beside them.
 *
 * The unique key is (storefront_country, gateway, environment): a storefront has at most one account per gateway per environment, so "the live Kuwait
 * Paymob account" names one row and a second one cannot be created by accident. Both text columns are compared byte for byte on MySQL and MariaDB, as
 * PHP compares them, like `legal_entities.country`.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('payment_gateway_accounts')) {
            return;
        }

        Schema::create('payment_gateway_accounts', function (Blueprint $table) {
            $table->id();
            $country = $table->char('storefront_country', 2);
            $gateway = $table->string('gateway', 64);
            $environment = $table->string('environment', 8);
            $table->unsignedBigInteger('legal_entity_id')->nullable();
            $table->string('label', 191)->nullable();
            $table->text('credentials')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('webhook_path_token', 64)->nullable();
            $table->text('webhook_events')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();

            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $country->charset('ascii')->collation('ascii_bin');
                $gateway->charset('ascii')->collation('ascii_bin');
                $environment->charset('ascii')->collation('ascii_bin');
            }

            $table->unique(['storefront_country', 'gateway', 'environment'], 'pga_market_gateway_environment');
            $table->unique('webhook_path_token', 'pga_webhook_path_token');
            $table->index('legal_entity_id');
        });
    }

    /** Payments point at these rows, so the table is not dropped while it holds an account. */
    public function down(): void
    {
        if (Schema::hasTable('payment_gateway_accounts') && DB::table('payment_gateway_accounts')->exists()) {
            throw new RuntimeException('The payment gateway accounts table holds accounts. Rolling this migration back would forget whose account each payment was taken through.');
        }

        Schema::dropIfExists('payment_gateway_accounts');
    }
};
