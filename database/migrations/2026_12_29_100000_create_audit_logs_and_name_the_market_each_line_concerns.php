<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The panel's audit trail exists on every installation, and each line says which market and which company it concerns (G1-08).
 *
 * TWO gaps in one table. `audit_logs` was never created by a migration: it comes from `installation/backup/database.sql`, which is not in
 * this repository, so every reader guards with `Schema::hasTable()` and a host built from the migrations alone has NO trail at all --
 * `AuditLogger::record()` silently returns null and the actions that are only recorded are not recorded anywhere. And a line said who did
 * what without saying WHERE: six markets share one trail, so "coupon.updated" could be any market's coupon.
 *
 * Creating it IF MISSING, and otherwise only adding the two columns, is what makes this safe on both kinds of host: the shipped platform
 * keeps the table its dump made, with its data, and a migrations-only host gets the same shape. The columns the create uses are the ones
 * `App\Models\AuditLog` declares fillable and `AuditLogger` writes, no more.
 *
 * Both new columns are NULLABLE with no backfill. A line written before this says nothing about a market, which is the honest record: it
 * was written when the platform had one, and guessing Saudi Arabia for it would put a fact in the trail that nobody recorded. The market
 * is indexed, because narrowing the viewer to an employee's countries filters on it; the company is not, there being a handful of them.
 *
 * On MySQL and MariaDB the market is compared byte for byte (ascii_bin), as every other market column of this platform is.
 */
return new class extends Migration {
    private const TABLE = 'audit_logs';

    private const MARKET = 'storefront_country';

    private const COMPANY = 'legal_entity_id';

    private const MARKET_INDEX = 'audit_logs_storefront_country_index';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            $this->createTheTrail();
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (!Schema::hasColumn(self::TABLE, self::MARKET)) {
                $column = $table->char(self::MARKET, 2)->nullable();

                if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                    $column->charset('ascii')->collation('ascii_bin');
                }

                $table->index(self::MARKET, self::MARKET_INDEX);
            }

            if (!Schema::hasColumn(self::TABLE, self::COMPANY)) {
                $table->unsignedBigInteger(self::COMPANY)->nullable();
            }
        });
    }

    /**
     * The two columns go, and the TABLE stays. Rolling back must not take the trail away: it is append-only by policy, a host that got it
     * from this migration has nothing else holding those lines, and no rollback is worth deleting an audit trail. A host that wants the
     * table gone drops it deliberately.
     */
    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach ([self::MARKET => 'market', self::COMPANY => 'company'] as $column => $what) {
            if (Schema::hasColumn(self::TABLE, $column) && DB::table(self::TABLE)->whereNotNull($column)->exists()) {
                throw new RuntimeException("Audit lines name a {$what}. Rolling this migration back would forget which, and an audit line is not a thing to edit.");
            }
        }

        $indexed = collect(Schema::getIndexes(self::TABLE))->contains(fn (array $index): bool => $index['name'] === self::MARKET_INDEX);

        Schema::table(self::TABLE, function (Blueprint $table) use ($indexed): void {
            if ($indexed) {
                $table->dropIndex(self::MARKET_INDEX);
            }

            foreach ([self::MARKET, self::COMPANY] as $column) {
                if (Schema::hasColumn(self::TABLE, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function createTheTrail(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            // What the viewer and the per-record timeline read by: the module prefix of the action, and the record acted on.
            $table->index('action', 'audit_logs_action_index');
            $table->index(['subject_type', 'subject_id'], 'audit_logs_subject_index');
        });
    }
};
