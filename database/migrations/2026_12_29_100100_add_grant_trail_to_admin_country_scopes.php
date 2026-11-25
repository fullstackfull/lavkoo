<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A staff country scope records who granted it and when it ends (G1-14).
 *
 * The rows said only "admin 7 works with Kuwait". Who decided that, and whether it was meant to be permanent, lived nowhere -- so a scope
 * granted for one busy week stayed for ever unless somebody remembered it, and the audit question "who gave this employee Kuwait" had no
 * answer in the data at all.
 *
 * Additive and nullable. A row that exists keeps both empty: `granted_by` empty means "granted on the server console before this was
 * recorded", and `expires_at` empty means "until it is revoked", which is what every existing row is. Nothing changes for anyone.
 *
 * **Expiry NARROWS and never widens**, which is the whole reason this column needs a reader written with care: an employee with NO rows is
 * UNRESTRICTED on this platform, so a naive "ignore expired rows" would turn the last row's expiry into a privilege escalation on a timer.
 * `StaffCountryScope::resolve()` therefore reads the rows twice -- whether the employee HAS rows, and which of them are still in force --
 * so an employee whose every row has expired is restricted to NOTHING. `StaffCountryExpiryTest` is that test.
 *
 * `expires_at` is indexed with the admin, because every bind of a restricted employee's request asks "which of this admin's rows are still
 * in force". `granted_by` is a plain reference with no constraint: it names an admin who may later be deleted, and the trail must not lose
 * who granted a scope because the granter left.
 */
return new class extends Migration {
    private const TABLE = 'admin_country_scopes';

    private const GRANTED_BY = 'granted_by';

    private const EXPIRES_AT = 'expires_at';

    private const INDEX = 'admin_country_scopes_admin_expiry_idx';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (!Schema::hasColumn(self::TABLE, self::GRANTED_BY)) {
                $table->unsignedBigInteger(self::GRANTED_BY)->nullable();
            }

            if (!Schema::hasColumn(self::TABLE, self::EXPIRES_AT)) {
                // DATETIME, not TIMESTAMP. MySQL and MariaDB give a TIMESTAMP the Unix range, which ends on 19 January 2038, and an
                // operator typing a later date got `0000-00-00 00:00:00` stored without a word -- read back as already expired, which
                // narrows rather than widens but is still not what anybody asked for. SQLite stores either happily, so only the MariaDB
                // run showed it.
                $table->dateTime(self::EXPIRES_AT)->nullable();
                $table->index(['admin_id', self::EXPIRES_AT], self::INDEX);
            }
        });
    }

    /**
     * Refused while a row names either, for the reason every other market column refuses it: the fact cannot be worked out again, and
     * dropping `expires_at` would silently make a scope that was meant to end a permanent one.
     */
    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach ([self::GRANTED_BY => 'who granted it', self::EXPIRES_AT => 'when it ends'] as $column => $what) {
            if (Schema::hasColumn(self::TABLE, $column) && DB::table(self::TABLE)->whereNotNull($column)->exists()) {
                throw new RuntimeException("Staff country scopes record {$what}. Rolling this migration back would forget it, and an expiry forgotten is a scope that never ends.");
            }
        }

        $indexed = collect(Schema::getIndexes(self::TABLE))->contains(fn (array $index): bool => $index['name'] === self::INDEX);

        Schema::table(self::TABLE, function (Blueprint $table) use ($indexed): void {
            if ($indexed) {
                $table->dropIndex(self::INDEX);
            }

            foreach ([self::GRANTED_BY, self::EXPIRES_AT] as $column) {
                if (Schema::hasColumn(self::TABLE, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
