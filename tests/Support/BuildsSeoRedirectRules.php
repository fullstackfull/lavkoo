<?php

namespace Tests\Support;

use App\Services\Storefront\StorefrontMarketRows;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The `redirects` table and the rules in it, for the two files that test the operator's SEO redirects per market (A1-V2).
 *
 * The table is built as the SEO subsystem creates it and then the feature's REAL migration is run on top, so the market column is exactly
 * what production gets rather than a hand-typed guess at it -- the same shape the rest of this suite uses for an ALTER-only migration. It
 * is here rather than copied into both files because the two ask different questions of the same table: one asks which rows belong to which
 * market, the other drives the website and asks which rules a visitor is actually served.
 */
trait BuildsSeoRedirectRules
{
    /** The real migration that gives a redirect rule its market. */
    protected function redirectMarketMigration(): string
    {
        return '2026_12_26_100000_add_storefront_country_to_redirects_table.php';
    }

    protected function buildTheRedirectTable(): void
    {
        Schema::dropIfExists('redirects');
        Schema::create('redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('from_path');
            $table->string('to_path');
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->string('match_type')->default('exact');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('hits')->default(0);
            $table->dateTime('last_hit_at')->nullable();
            $table->string('note')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();
        });

        (require database_path('migrations/' . $this->redirectMarketMigration()))->up();
    }

    /**
     * One rule of one market. A null market is a rule written before the column existed, which is the default storefront's.
     *
     * It deliberately does NOT forget the rules cache: one test asserts that the cached set is what a request reads, which is the behaviour
     * the admin screens' own invalidation depends on. A caller that has just written a rule and means to read it forgets the key itself,
     * the way the admin screens do.
     */
    protected function aRuleOf(?string $market, string $from, string $to, int $status = 301, string $matchType = 'exact'): int
    {
        return DB::table('redirects')->insertGetId([
            'from_path' => $from,
            'to_path' => $to,
            'status_code' => $status,
            'match_type' => $matchType,
            'is_active' => true,
            StorefrontMarketRows::COLUMN => $market,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
