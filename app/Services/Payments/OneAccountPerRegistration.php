<?php

namespace App\Services\Payments;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One guest who asks to be registered while paying gets ONE account, even when two deliveries of the payment's callback are
 * being handled at the same instant (D1-328, the half the order claim does not reach).
 *
 * `digital_payment_place_orders` registers that guest with
 * `User::where(email)->orWhere(phone)->first() ?? User::create(...)`, BEFORE and OUTSIDE any transaction. Two deliveries of
 * one paid callback -- a gateway's server call arriving while the shopper's browser returns, a gateway retrying because the
 * first answer was slow, the recovery sweep running beside either -- both read "no such customer" before either has written
 * one, and both insert. Measured: `PaidCallbackConcurrencyTest::test_two_simultaneous_deliveries_of_one_paid_callback_register_one_customer`
 * found TWO accounts for one registration while the orders were already single, which is what
 * {@see OneOrderPerPayment} closed and could not close here -- it is claimed inside the order's transaction, and the account
 * is made before that transaction is opened.
 *
 * **Why a register of its own and not a unique key on `users`.** A unique key on `users.email` or `users.phone` would reach
 * every registration this platform has -- the two apps' passport and social sign-ups, the web form, the order API, the point
 * of sale and two admin screens -- and would refuse to be applied at all to a live table that already carries a duplicate.
 * And a lock on the lookup is not an option either: `users.email` and `users.phone` carry no index this repository declares,
 * so `SELECT ... WHERE email = ? OR phone = ? FOR UPDATE` locks every row it scans and would serialise every settlement on
 * the platform -- the trap {@see OneOrderPerPayment} documents for `transaction_id`. So the claim goes where it can be
 * indexed and where it reaches nothing else: a register of its own, in the shape {@see WalletTopUpRegister} already uses for
 * the top-up.
 *
 * **Two rows per registration, one for the e-mail and one for the phone**, because the lookup it is standing in for is an
 * OR: an account already existing under EITHER is what makes this guest an existing customer, so either is enough to lose
 * the claim. A registration naming neither claims nothing and keeps today's behaviour, there being nothing to collide on.
 *
 * **Where the work happens matters.** The transaction here contains the lookup, the claim and the insert of ONE customer and
 * nothing else. It does not reach `generateOrder`, and nothing that notifies anybody is inside it: `OrderPlacedEvent` is
 * fired outside the order's own transaction and is not caught, so a claim held around the whole settlement would let a
 * notification failure roll back an order the customer has already PAID for.
 *
 * Until the register's migration has run this is the find-or-create the platform has always done, to the line.
 */
class OneAccountPerRegistration
{
    public const TABLE = 'customer_registration_claims';

    /**
     * The one account of this registration: the customer who already exists under the e-mail or the phone, or the one this
     * delivery creates.
     *
     * @param Closure(): (User|Model|null) $find today's lookup, unchanged
     * @param Closure(): (User|Model) $create today's insert, unchanged
     */
    public function registerOnce(?string $email, ?string $phone, Closure $find, Closure $create): User|Model
    {
        $keys = $this->keysOf(email: $email, phone: $phone);

        if ($keys === [] || !Schema::hasTable(self::TABLE)) {
            return $find() ?? $create();
        }

        DB::beginTransaction();
        try {
            $existing = $find();
            if ($existing !== null) {
                DB::commit();

                return $existing;
            }

            if (!$this->claim(keys: $keys)) {
                // Lost the claim. `insertOrIgnore` waited on the winner's index entry and was told it is a duplicate, which
                // means the winner has COMMITTED -- and the rollback is what makes that visible: this connection is in
                // REPEATABLE READ and its read view was taken by the lookup above, before the winner's row existed, so a
                // second lookup inside this transaction would miss the account that is now there.
                DB::rollBack();

                // `?? $create()` is a branch that cannot be taken -- a claim held by nobody would have been rolled back
                // with its row and this insert would have won it -- and it is here so this method keeps the contract the
                // call site has always had: a guest who has paid is never left without an account.
                return $find() ?? $create();
            }

            $customer = $create();
            DB::table(self::TABLE)->whereIn('identity', $keys)->update(['user_id' => $customer->getKey()]);
            DB::commit();

            return $customer;
        } catch (Throwable $failure) {
            DB::rollBack();

            throw $failure;
        }
    }

    /**
     * Writes this registration's rows. False when either is there already: another delivery is registering this guest, or
     * has.
     *
     * @param list<string> $keys
     */
    private function claim(array $keys): bool
    {
        foreach ($keys as $key) {
            if (DB::table(self::TABLE)->insertOrIgnore(['identity' => $key, 'created_at' => now()]) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * What this registration claims: the e-mail and the phone it names, each under its own prefix so an address and a number
     * that happen to read the same cannot be one key. Trimmed and lower-cased, because an e-mail differing only in case is
     * the same address to every mail server and the lookup this stands in for compares them in the engine's own
     * case-insensitive collation.
     *
     * @return list<string>
     */
    private function keysOf(?string $email, ?string $phone): array
    {
        $keys = [];
        foreach (['email' => $email, 'phone' => $phone] as $kind => $value) {
            $value = mb_strtolower(trim((string) $value));

            if ($value !== '') {
                $keys[] = $kind . ':' . mb_substr($value, 0, 180);
            }
        }

        return $keys;
    }
}
