<?php

namespace App\Services\Customer;

use App\Models\User;
use Closure;
use Exception;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs one write to a customer's balance, a wallet or loyalty-point balance, with the customer's row locked from the moment the
 * balance is read until the write commits.
 *
 * Without the lock, two writers can read the same balance, and the later write then replaces the earlier one: the ledger keeps
 * both entries while the balance keeps only one of them.
 */
final class CustomerBalanceLock
{
    /**
     * @param Closure(User): mixed $write runs with the locked customer, inside the transaction, and returns what the caller gets.
     * @return mixed the closure's result. False when the customer does not exist, or when the write throws an exception; the
     *               transaction is rolled back in both cases, so neither the balance nor the ledger changes.
     */
    public static function apply(int|string $customerId, Closure $write): mixed
    {
        DB::beginTransaction();
        try {
            $customer = User::query()->lockForUpdate()->find($customerId);
            if ($customer === null) {
                DB::rollBack();

                return false;
            }

            $result = $write($customer);
            DB::commit();

            return $result;
        } catch (Exception $exception) {
            info($exception);
            DB::rollBack();

            return false;
        } catch (Throwable $error) {
            DB::rollBack();

            throw $error;
        }
    }
}
