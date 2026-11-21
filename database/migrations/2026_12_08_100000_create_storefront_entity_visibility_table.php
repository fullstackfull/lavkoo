<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which catalogue entity a storefront shows (B-11, B-13, B-15, B-16). One mapping table for every entity type: the central row of a
 * category, a brand, a banner, a coupon or a campaign is never touched, and deleting the mapping restores the entity exactly.
 *
 * The row's `mode` says what the mapping means, and the two meanings are not the same rule:
 *
 *  - `hidden`   a category or a brand is visible in every storefront until a row hides it in one. No row is today's behaviour.
 *  - `assigned` a banner, a coupon or a campaign with no row at all is shown everywhere; once it has any row it is shown only in the
 *               storefronts its rows name.
 *
 * Both land on the same safe place: with no rows at all, every storefront shows exactly what it shows today, whether storefronts are
 * switched on or off. Nothing reads the table before this migration has run.
 *
 * Both directions are idempotent, as this platform's deployment imports a database dump before it migrates: `up()` returns when the
 * table is already there instead of throwing, and `down()` drops it only if it exists.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('storefront_entity_visibility')) {
            return;
        }

        Schema::create('storefront_entity_visibility', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->char('storefront_country', 2);
            $table->string('mode', 10);
            $table->timestamps();

            $table->unique(['entity_type', 'entity_id', 'storefront_country'], 'storefront_entity_visibility_unique');
            $table->index(['entity_type', 'storefront_country'], 'storefront_entity_visibility_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_entity_visibility');
    }
};
