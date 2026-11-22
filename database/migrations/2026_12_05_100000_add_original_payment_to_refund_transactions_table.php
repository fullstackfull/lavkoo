<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a refund gives back of the ORIGINAL payment, and the currency that payment was taken in (D1-10, criteria 3 and 6).
 *
 * `refund_transactions.amount` is the refund in the amounts the order is stored in, and it stays exactly what it is: the seller's earnings, the reports and
 * the exports all read it. These two columns record the other figure beside it — the share of what the customer actually paid, in the currency they paid in
 * — so that a refund paid out after the rates or the money settings have moved can be checked against what was taken, and so that a payout which cannot be
 * made in that currency still records the amount that is owed.
 *
 * Additive: both columns are nullable with no default, nothing is backfilled, and they are written only while
 * `gcc.storefronts.refunds.from_original_payment` is on (off by default). They carry no index and no foreign key.
 *
 * The columns are appended, without a position, so MySQL 8 and MariaDB 10.3+ add them with ALGORITHM=INSTANT: no table rebuild and no table out of service.
 * The clause is not written here because no migration in this tree writes one, and naming INPLACE explicitly would forbid the faster algorithm; it was
 * measured on MariaDB 10.11 for these exact two columns instead.
 */
return new class extends Migration {
    private const TABLE = 'refund_transactions';

    private const AMOUNT = 'original_payment_amount';

    private const CURRENCY = 'original_payment_currency';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        if (!Schema::hasColumn(self::TABLE, self::AMOUNT)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->decimal(self::AMOUNT, 20, 8)->nullable());
        }

        if (!Schema::hasColumn(self::TABLE, self::CURRENCY)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->string(self::CURRENCY, 8)->nullable());
        }
    }

    /** Dropping the columns would forget what a refund gave back of the payment it is a refund of, which cannot be worked out again once a rate has moved. */
    public function down(): void
    {
        $recorded = Schema::hasTable(self::TABLE)
            && Schema::hasColumn(self::TABLE, self::AMOUNT)
            && DB::table(self::TABLE)->whereNotNull(self::AMOUNT)->exists();

        if ($recorded) {
            throw new RuntimeException('Refunds record what they gave back of the original payment. Rolling this migration back would forget it.');
        }

        foreach ([self::AMOUNT, self::CURRENCY] as $column) {
            if (Schema::hasTable(self::TABLE) && Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
