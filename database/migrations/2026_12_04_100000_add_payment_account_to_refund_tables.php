<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which merchant account took the money a refund gives back, and which company it was taken by (D1-10, criterion 1 and the recording half of criterion 4):
 * the gateway account the original payment was stamped with when it started (App\Services\Payments\PaymentAccountBinding) and the company that stamp names,
 * copied onto the refund row when it is made. An account can be edited, disabled or deleted and a company can be re-registered between the sale and the
 * refund, so the refund keeps what was true when the money was taken rather than what the registry answers on the day of the refund.
 *
 * This is NOT the order's selling company, which 2026_12_03_100000 already copies into `legal_entity_id`: a refund row carries both, because the company
 * that sold and the company whose merchant account took the money are two facts and a reconciliation that assumes they are one is how a refund is drawn on
 * the wrong account.
 *
 * Additive: both columns are nullable, nothing is backfilled, and a refund that exists today keeps them empty. Nothing writes them while per-company
 * payment accounts are off (gcc.storefronts.payment_accounts.enabled, off by default), which is every payment today, Saudi ones included.
 *
 * The columns are appended, without a position, and carry no index or foreign key, so MySQL 8 and MariaDB 10.3 and later add them without rebuilding the
 * table and without taking the table out of service: appending a nullable column with no default is INSTANT on both, which is what INPLACE with LOCK=NONE
 * asks for and better. No foreign key, deliberately: an account or a company that is deleted must not take the refund's record of it with it, nor refuse
 * the delete. The style is 2026_12_03_100000's, which is the migration this one sits beside.
 */
return new class extends Migration {
    private const TABLES = ['refund_requests', 'refund_transactions'];

    private const ACCOUNT = 'payment_gateway_account_id';

    private const ENTITY = 'payment_legal_entity_id';

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (!Schema::hasTable($name)) {
                continue;
            }

            foreach ([self::ACCOUNT, self::ENTITY] as $column) {
                if (!Schema::hasColumn($name, $column)) {
                    Schema::table($name, fn (Blueprint $table) => $table->unsignedBigInteger($column)->nullable());
                }
            }
        }
    }

    /** Dropping the columns would forget which account took the money a refund gives back, which cannot be worked out again, so it is refused while any refund carries one. */
    public function down(): void
    {
        foreach (self::TABLES as $name) {
            foreach ([self::ACCOUNT, self::ENTITY] as $column) {
                $recorded = Schema::hasTable($name) && Schema::hasColumn($name, $column) && DB::table($name)->whereNotNull($column)->exists();

                if ($recorded) {
                    throw new RuntimeException("Refunds name the account their payment was taken through ({$name}.{$column}). Rolling this migration back would forget it.");
                }
            }
        }

        foreach (self::TABLES as $name) {
            foreach ([self::ACCOUNT, self::ENTITY] as $column) {
                if (Schema::hasTable($name) && Schema::hasColumn($name, $column)) {
                    Schema::table($name, fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
    }
};
