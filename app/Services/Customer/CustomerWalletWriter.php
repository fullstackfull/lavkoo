<?php

namespace App\Services\Customer;

use App\Models\BusinessSetting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletOverdraft;
use Illuminate\Support\Str;
use App\Services\Storefront\LedgerAttribution;
use App\Services\Storefront\LedgerOrigin;

/**
 * The single place a customer's `wallet_balance` is read and written, and the only writer of `wallet_transactions`.
 *
 * ## Why there is one
 *
 * There were four near-identical copies of this write — App\Traits\CustomerTrait::createWalletTransaction,
 * App\Utils\OrderManager::createWalletTransaction, App\Utils\CustomerManager::create_wallet_transaction and
 * App\Repositories\WalletTransactionRepository::addWalletTransaction — and each read the balance BEFORE opening its
 * transaction and without locking the customer's row. Two concurrent credits therefore both read the same figure and each wrote
 * its own total: the later write replaced the earlier one, the ledger kept both rows, and a credit was gone from the account.
 * Here the balance is read inside the transaction with the customer's row locked (CustomerBalanceLock), so a second writer
 * waits for the first to commit and then reads what it wrote.
 *
 * ## The row agrees with the balance
 *
 * All four copies wrote `balance = previous + credit - debit` onto the ledger row while setting the account to
 * `previous + bonus + credit - debit`. For an `add_fund` that earned a bonus the row understated the account by exactly that
 * bonus — visible on the admin wallet report, which prints the bonus and the balance on one line, and in the add-fund email,
 * which prints the row's balance as the customer's new balance. Here the row carries the balance the account ends with.
 *
 * Rows written before this are NOT rewritten and keep the understated figure. They stay reconcilable, because `admin_bonus` is
 * on the row in both eras: summing `credit + admin_bonus - debit` over a customer's rows reaches their balance across the
 * boundary. What cannot be done from the row alone is tell an old `add_fund` row from a new one.
 */
final class CustomerWalletWriter
{
    /**
     * The transaction types whose callers are handed the row rather than `true`.
     *
     * `order_refund` is absent because the one copy that served it returned `true`, and both of its callers ignore the answer.
     * The asymmetry is inherited, not chosen.
     */
    private const RETURNS_THE_ROW = [
        'add_fund',
        'add_fund_by_admin',
        'due_payment_for_order',
        'loyalty_point',
        'order_place',
        'return_order_amount_by_admin',
    ];

    /**
     * Moves money into or out of a customer's wallet and records it, or answers false and moves nothing.
     *
     * @return bool|WalletTransaction the row for the types above, `true` for the rest, and false when the wallet is switched
     *                                off, the customer does not exist, or the write failed and was rolled back.
     */
    public static function write(int|string $customerId, WalletPosting $posting, ?LedgerOrigin $origin = null): bool|WalletTransaction
    {
        if (BusinessSetting::where('type', 'wallet_status')->first()->value != 1) {
            return false;
        }

        return CustomerBalanceLock::apply($customerId, function (User $customer) use ($posting, $origin): bool|WalletTransaction {
            // A debit never takes a wallet below nothing. The balance is read under the customer's row lock just above, so this
            // judges the figure the write is about to be made from, not one another writer has since moved. Every other wallet
            // writer on the platform asks the same question in the same place (WalletTransactionRepository, CustomerManager,
            // CustomerTrait), and this one answers the six callers that now come through here.
            if (WalletOverdraft::refuses($customer->wallet_balance, $posting->debit)) {
                return false;
            }

            $balanceAfter = $customer->wallet_balance + $posting->bonus + $posting->credit - $posting->debit;

            $transaction = new WalletTransaction();
            $transaction->user_id = $customer->id;
            $transaction->transaction_id = Str::uuid();
            $transaction->reference = $posting->reference;
            $transaction->transaction_type = $posting->transactionType;
            $transaction->payment_method = $posting->paymentMethod;
            $transaction->credit = $posting->credit;
            $transaction->debit = $posting->debit;
            $transaction->admin_bonus = $posting->bonus;
            $transaction->balance = $balanceAfter;
            $transaction->created_at = now();
            $transaction->updated_at = now();

            // What stands behind the row (E-05), as the other four writers record it. Dormant until its migration runs: of()
            // answers [] while none of the attribution columns exist. Written AFTER the overdraft guard and inside the balance
            // lock, so it records the write rather than deciding it.
            $transaction->fill(app(LedgerAttribution::class)->of(table: $transaction->getTable(), origin: $origin));

            $customer->wallet_balance = $balanceAfter;
            $customer->save();
            $transaction->save();

            return in_array($posting->transactionType, self::RETURNS_THE_ROW, true) ? $transaction : true;
        });
    }

    /**
     * The common case: a type and an amount, shaped by WalletPosting::of().
     *
     * Loyalty-point conversions do not come through here — they have two formulas and the caller has to name the one it means.
     *
     * @param array<string, mixed> $paymentData
     */
    public static function record(int|string $customerId, float $amount, string $transactionType, ?string $reference = null, array $paymentData = [], ?LedgerOrigin $origin = null): bool|WalletTransaction
    {
        return self::write(
            customerId: $customerId,
            posting: WalletPosting::of(
                transactionType: $transactionType,
                amount: $amount,
                reference: $reference,
                paymentData: $paymentData,
            ),
            origin: $origin,
        );
    }
}
