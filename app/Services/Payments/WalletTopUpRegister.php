<?php

namespace App\Services\Payments;

use App\Models\WalletTransaction;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The register of wallet top-ups: which payment was credited, to whom, as which ledger row.
 *
 * A gateway may hand one confirmed payment to the top-up more than once: a notification that is delivered again, a customer
 * who comes back to the return address, a recovery that runs after the first attempt had finished. The first of them credits
 * the wallet and every later one is told it is done.
 *
 * The entry is written before the credit and in the same transaction, under a unique key on the payment. Two deliveries that
 * arrive together meet on that key, so the second waits for the first and then finds the entry. A credit that is refused or
 * fails takes its entry back with it, so the payment can still be credited by a later delivery.
 */
class WalletTopUpRegister
{
    public const TABLE = 'wallet_top_ups';

    /**
     * @param Closure(): (WalletTransaction|false) $credit
     * @return WalletTransaction|false the ledger row of the credit; false when this payment was credited before, or the credit was refused
     */
    public function creditOnce(string $paymentId, int $customerId, Closure $credit): WalletTransaction|false
    {
        if (!Schema::hasTable(self::TABLE)) {
            return $credit();
        }

        DB::beginTransaction();
        try {
            $ledgerRow = $this->enter(paymentId: $paymentId, customerId: $customerId) ? $credit() : false;
            if (!$ledgerRow instanceof WalletTransaction) {
                DB::rollBack();

                return false;
            }
            DB::table(self::TABLE)->where('payment_request_id', $paymentId)->update(['wallet_transaction_id' => $ledgerRow->getKey()]);
            DB::commit();

            return $ledgerRow;
        } catch (Throwable $failure) {
            DB::rollBack();

            throw $failure;
        }
    }

    /** Writes the entry of the payment. False when it is there already: the payment was credited before. */
    private function enter(string $paymentId, int $customerId): bool
    {
        return DB::table(self::TABLE)->insertOrIgnore(['payment_request_id' => $paymentId, 'user_id' => $customerId, 'created_at' => now()]) === 1;
    }
}
