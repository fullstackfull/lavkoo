<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provider-agnostic link between the store's own cities and a provider's cities.
 *
 * `provider_city_mappings` says "for provider P, internal city C is provider city E (region R)". Provider ids live only here:
 * the address book never stores one, and an id of one provider is never sent to another. A mapping routes an order only once
 * it is `approved`; many candidates may sit `pending` for one city (Torod lists Riyadh twice) but the database itself allows a
 * single approved mapping per provider and internal city (`approved_slot` is 1 while approved and NULL otherwise, and NULLs do
 * not collide in a unique index).
 *
 * `provider_city_sync_runs` records every attempt to refresh a provider's city list and whether it was complete, so a truncated
 * list can never be mistaken for a full one.
 *
 * Additive; rolling back drops these two tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('provider_city_mappings')) {
            Schema::create('provider_city_mappings', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 32);
                $table->unsignedBigInteger('address_city_id');
                $table->string('external_city_id', 32);
                $table->string('external_region_id', 32)->nullable();
                $table->string('country_code', 2)->nullable()->comment('null: the provider did not say which country its city is in');
                $table->string('status', 16)->default('pending');
                $table->string('method', 24)->nullable();
                $table->unsignedTinyInteger('approved_slot')->nullable();
                $table->json('evidence')->nullable();
                $table->string('notes', 255)->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'address_city_id', 'external_city_id'], 'provider_city_mappings_pair_unique');
                $table->unique(['provider', 'address_city_id', 'approved_slot'], 'provider_city_mappings_approved_unique');
                $table->index(['provider', 'status'], 'provider_city_mappings_status_idx');
                $table->index(['provider', 'external_city_id'], 'provider_city_mappings_external_idx');
            });
        }

        if (!Schema::hasTable('provider_city_sync_runs')) {
            Schema::create('provider_city_sync_runs', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 32);
                $table->string('verdict', 16);
                $table->unsignedInteger('pages')->default(0);
                $table->unsignedInteger('fetched')->default(0);
                $table->unsignedInteger('distinct_ids')->default(0);
                $table->unsignedInteger('expected_total')->nullable();
                $table->unsignedInteger('regions_seen')->default(0);
                $table->json('request')->nullable();
                $table->string('reason', 255)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['provider', 'created_at'], 'provider_city_sync_runs_provider_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['provider_city_sync_runs', 'provider_city_mappings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
