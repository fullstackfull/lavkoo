<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permission for one product to reach one destination country (H1-03).
 *
 * The platform has never recorded whether a product may be shipped abroad, exported from the country it is sold in, or imported into the
 * country it is going to. Those are three different permissions, granted by three different authorities, and a platform that assumes them
 * is a platform that ships a medicine into a country that bans it.
 *
 * So the rule this table serves is: NO ROW MEANS NO. A product with no row for a destination may not go there, and the engine that reads it
 * (H1-06) denies by default for every destination outside the product's own country. A domestic sale needs no row at all, which is why this
 * table ships EMPTY and the Saudi platform is unchanged: it has one country, and nothing in it crosses a border.
 *
 * One row per product and destination, so a grant is a fact that can be found, audited and withdrawn. Who grants it and who maintains the
 * lists is the owner's question Q-13; `recorded_by` and `recorded_at` are here so that the answer, whoever it is, leaves a trace.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('product_destination_permissions')) {
            return;
        }

        Schema::create('product_destination_permissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            // The country the product is going TO, as the platform writes country codes everywhere else.
            $table->string('destination_country', 2);
            // Three permissions, not one: carrying it at all, taking it out of where it is sold, and bringing it into where it is going.
            $table->boolean('may_ship')->default(false);
            $table->boolean('may_export')->default(false);
            $table->boolean('may_import')->default(false);
            // Who said so and when, so a grant is never anonymous. Nullable because a row may be loaded from a regulatory list.
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            // One row per product and destination: a second grant for the same pair would make "may it go there" ambiguous.
            //
            // Named, and named short. Laravel's own name for this index would be
            // `product_destination_permissions_product_id_destination_country_unique` -- 68 characters, where MySQL and MariaDB allow 64 --
            // so the migration ran on SQLite and would have failed on every real server with "Identifier name is too long". CI on MariaDB
            // is what caught it.
            $table->unique(['product_id', 'destination_country'], 'product_destination_country_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_destination_permissions');
    }
};
