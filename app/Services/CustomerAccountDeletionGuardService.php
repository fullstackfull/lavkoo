<?php

namespace App\Services;

use App\Models\User;

/**
 * Whether a customer's own account deletion has to be refused because the store still holds money for them, asked the same way by the two routes a customer
 * deletes their own account over: RestAPI\v1\CustomerController::account_delete for the customer app and Web\UserProfileController::account_delete for the
 * website. It answers the translation key of the refusal, or null when nothing stands in the way, the way Auction's own deletion guard answers its callers.
 * A third route deletes a customer account, Admin\Customer\CustomerController::deleteCustomer, and deliberately does not ask this: config/gcc.php says why.
 *
 * Switched off by default (`gcc.account_deletion.refuse_while_balance_remains`): while it is off this answers null whatever the customer holds, and both
 * routes delete the account as they do today. Nothing here ever writes: a ledger row is kept whichever way the answer goes.
 */
class CustomerAccountDeletionGuardService
{
    /**
     * The smallest holding that is money a person has. A wallet balance and a point total are kept as DECIMAL(40,20) and read through a float cast, so an
     * addition and a subtraction that should cancel can leave a residual of the order of 1e-16 behind; refusing a deletion over that is a support ticket,
     * not a guard. One halala -- the smallest unit the storefront quotes a price in, and the difference the reconciliation services already treat as the
     * smallest one that is money (App\Services\Marketplace\ReconciliationService) -- is some fourteen orders of magnitude above such a residual and below
     * anything a customer can be credited with: points are credited in whole units (App\Utils\CustomerManager::create_loyalty_point_transaction).
     */
    private const SMALLEST_HOLDING = 0.01;

    public function blockingReason(User $customer): ?string
    {
        if (!config('gcc.account_deletion.refuse_while_balance_remains')) {
            return null;
        }

        $holdings = $this->holdingsNow(customer: $customer);

        if ($this->isHeld(amount: (float) $holdings->wallet_balance)) {
            return 'you_can_not_delete_account_while_your_wallet_holds_money';
        }

        if ($this->isHeld(amount: (float) $holdings->loyalty_point)) {
            return 'you_can_not_delete_account_while_you_still_have_loyalty_points';
        }

        return null;
    }

    /**
     * The two columns read again under the customer's own key. Both callers hydrate the customer before they count their orders, ask the Auction addon and
     * delete a profile image, so a refund credited in that window is money the store holds and a guard that trusts the hydrated copy answers from before the
     * credit. Reading the row again does not make the deletion atomic -- only a lock held across the delete would -- but it costs one read on a route that
     * runs once in the life of an account, and it closes the half of the window that is this guard's own. If the row has gone in the meantime, what the
     * caller hydrated is all there is to weigh.
     */
    private function holdingsNow(User $customer): User
    {
        return User::whereKey($customer->getKey())->first(['wallet_balance', 'loyalty_point']) ?? $customer;
    }

    /** A debt is held as surely as a credit, so what counts is the distance from zero. */
    private function isHeld(float $amount): bool
    {
        return abs($amount) >= self::SMALLEST_HOLDING;
    }
}
