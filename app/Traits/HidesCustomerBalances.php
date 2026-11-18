<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Support\Arr;

/**
 * A customer embedded in a payload that somebody else reads (the seller app and the courier app receive whole orders, refund requests, reviews and chats with
 * their customer) is serialized without the wallet balance, the loyalty points and the referral code. Only the serialization changes: the attributes are still there for the
 * code that reads them, and the customer's own payload (User itself) still carries them.
 */
trait HidesCustomerBalances
{
    public function relationsToArray(): array
    {
        $relations = parent::relationsToArray();

        if (is_array($relations['customer'] ?? null)) {
            $relations['customer'] = Arr::except($relations['customer'], User::BALANCE_ATTRIBUTES);
        }

        return $relations;
    }
}
