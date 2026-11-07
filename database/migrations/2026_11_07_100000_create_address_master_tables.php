<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The store's ONE list of regions and cities.
 *
 * Until now the platform had no such list: `shipping_addresses.city` is whatever the customer typed, and every courier
 * integration had to guess from that string. These tables give every place a stable internal id; shipping providers,
 * warehouses and marketplaces are mapped to those ids (see the provider_city_mappings migration) and never own a list of
 * their own. Names are never keys: two places may share a name, which is why ids exist.
 *
 * Additive and independent of every existing table. Rolling back drops these three tables and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('address_regions')) {
            Schema::create('address_regions', function (Blueprint $table) {
                $table->id();
                $table->string('country_code', 2)->default('SA');
                $table->string('code', 16);
                $table->string('name_ar', 191);
                $table->string('name_en', 191);
                $table->string('normalized_ar', 191)->index('address_regions_norm_ar_idx');
                $table->string('normalized_en', 191)->index('address_regions_norm_en_idx');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->string('source', 40)->nullable();
                $table->string('source_ref', 64)->nullable();
                $table->timestamp('source_updated_at')->nullable();
                $table->timestamps();

                $table->unique(['country_code', 'code'], 'address_regions_code_unique');
            });
        }

        if (!Schema::hasTable('address_cities')) {
            Schema::create('address_cities', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('region_id')->index('address_cities_region_idx');
                $table->string('name_ar', 191);
                $table->string('name_en', 191);
                $table->string('normalized_ar', 191)->index('address_cities_norm_ar_idx');
                $table->string('normalized_en', 191)->index('address_cities_norm_en_idx');
                $table->decimal('latitude', 10, 8)->nullable();
                $table->decimal('longitude', 11, 8)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->string('source', 40)->nullable();
                $table->string('source_ref', 64)->nullable();
                $table->timestamp('source_updated_at')->nullable();
                $table->timestamps();

                $table->unique(['source', 'source_ref'], 'address_cities_source_unique');
                $table->index(['region_id', 'is_active'], 'address_cities_region_active_idx');
            });
        }

        if (!Schema::hasTable('address_city_aliases')) {
            Schema::create('address_city_aliases', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('city_id')->index('address_city_aliases_city_idx');
                $table->string('alias', 191);
                $table->string('normalized_alias', 191);
                $table->string('source', 24)->default('admin');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique('normalized_alias', 'address_city_aliases_alias_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (['address_city_aliases', 'address_cities', 'address_regions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
