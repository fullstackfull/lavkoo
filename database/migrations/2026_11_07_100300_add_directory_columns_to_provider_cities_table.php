<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a provider's city record says about itself, promoted out of the raw JSON so the review screen can show it and a
 * duplicate (Torod lists Riyadh as 36189 in region 1204 and as 230194 in region 1265) can be told apart.
 *
 * `provider_cities` was created by the fulfillment migration that is already applied on the pilot server, so this is a separate
 * additive migration: six nullable columns, nothing rewritten. `missing_since` is set only by a COMPLETE synchronisation, for a
 * city the provider no longer lists; a partial run never touches it.
 */
return new class extends Migration
{
    private const TABLE = 'provider_cities';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        $columns = [
            'region_name_ar' => static fn (Blueprint $table) => $table->string('region_name_ar', 191)->nullable(),
            'region_name_en' => static fn (Blueprint $table) => $table->string('region_name_en', 191)->nullable(),
            'prefix' => static fn (Blueprint $table) => $table->string('prefix', 32)->nullable(),
            'is_selected' => static fn (Blueprint $table) => $table->boolean('is_selected')->nullable(),
            'last_seen_at' => static fn (Blueprint $table) => $table->timestamp('last_seen_at')->nullable(),
            'missing_since' => static fn (Blueprint $table) => $table->timestamp('missing_since')->nullable(),
        ];

        foreach ($columns as $name => $define) {
            if (!Schema::hasColumn(self::TABLE, $name)) {
                Schema::table(self::TABLE, static function (Blueprint $table) use ($define): void {
                    $define($table);
                });
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        $existing = array_values(array_filter(
            ['region_name_ar', 'region_name_en', 'prefix', 'is_selected', 'last_seen_at', 'missing_since'],
            static fn (string $column): bool => Schema::hasColumn(self::TABLE, $column),
        ));
        if ($existing !== []) {
            Schema::table(self::TABLE, static function (Blueprint $table) use ($existing): void {
                $table->dropColumn($existing);
            });
        }
    }
};
