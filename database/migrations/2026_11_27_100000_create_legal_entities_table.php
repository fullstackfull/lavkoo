<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The company that sells in a storefront (GCC-13, D1-03): one legal entity for each country, with the name, the tax and commercial registration and
 * the invoice series it prints. Additive: a new table that nothing reads until legal entities are switched on, and that ships empty. The entities
 * themselves (names, tax numbers, e-invoicing duties) are facts the owner and counsel supply; this migration invents none.
 *
 * `country` is unique because the approved rule is one company for each country. On MySQL and MariaDB it is compared byte for byte (ascii_bin), as PHP
 * compares it, like `products.storefront_country`.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('legal_entities')) {
            return;
        }

        Schema::create('legal_entities', function (Blueprint $table) {
            $table->id();
            $column = $table->char('country', 2);
            $table->string('legal_name', 191);
            $table->string('trade_name', 191)->nullable();
            $table->string('tax_registration_number', 64)->nullable();
            $table->string('commercial_registration_number', 64)->nullable();
            $table->string('invoice_series_key', 16)->nullable();
            $table->text('address')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();

            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $column->charset('ascii')->collation('ascii_bin');
            }

            $table->unique('country');
        });
    }

    /** The registry is what orders point at, so it is not dropped while it holds a company. */
    public function down(): void
    {
        if (Schema::hasTable('legal_entities') && DB::table('legal_entities')->exists()) {
            throw new RuntimeException('The legal entities table holds companies. Rolling this migration back would forget who sells in each country.');
        }

        Schema::dropIfExists('legal_entities');
    }
};
