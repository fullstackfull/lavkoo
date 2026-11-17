<?php

namespace App\Services\Payments;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * One payment settlement, with the payer the stored payment names.
 *
 * The success hook builds an order from the cart of whoever the request says is paying. That is right inside the shopper's own browser
 * and wrong everywhere else: a gateway's server call has no session, a scheduled recovery settles many payments in one process on one
 * request, and a paid callback can be replayed, or delivered by the browser of another customer. While a settlement runs, the payment alone
 * decides who pays, which guest cart is meant and which address applies; the session is put back as it was found afterwards.
 */
final class PaymentSettlementScope
{
    /** Session keys a settlement pins to its payment and hands back as it found them. */
    private const PINNED_SESSION_KEYS = ['guest_id', 'address_id', 'newRegisterCustomerInfo'];

    /** Session keys an order uses up: the coupon and the billing choice are gone from the session once an order was built, or tried. */
    private const CONSUMED_SESSION_KEYS = ['billing_address_id', 'coupon_code', 'coupon_type', 'coupon_discount', 'coupon_bearer', 'coupon_seller_id'];

    public function __construct(
        public readonly ?User $registeredPayer,
        public readonly int|string|null $guestId,
        public readonly int|string|null $addressId,
        public readonly int|string|null $billingAddressId,
        public readonly ?User $newCustomer,
    ) {
    }

    /** The settlement running right now, if there is one. */
    public static function current(): ?self
    {
        return app()->bound(self::class) ? app(self::class) : null;
    }

    /** Whether the order of this payment exists already: a gateway delivers a callback more than once, and a recovery asks again. */
    public static function hasOrderFor(mixed $transactionId): bool
    {
        if (!is_scalar($transactionId) || (string) $transactionId === '') {
            return false;
        }

        // The query builder, not the Order model: a global scope on the model must not hide an order that exists.
        return DB::table('orders')->where('transaction_ref', (string) $transactionId)->exists();
    }

    public function run(Closure $settlement): mixed
    {
        $enclosingSettlement = self::current();
        $sessionBefore = $this->pinSession();
        app()->instance(self::class, $this);

        try {
            return $settlement();
        } finally {
            $enclosingSettlement ? app()->instance(self::class, $enclosingSettlement) : app()->forgetInstance(self::class);
            $this->restoreSession($sessionBefore);
        }
    }

    /** @return array<string, mixed> the pinned keys as they were, absent keys left out */
    private function pinSession(): array
    {
        $before = [];
        foreach (self::PINNED_SESSION_KEYS as $key) {
            if (session()->has($key)) {
                $before[$key] = session($key);
            }
        }

        $this->forgetSession([...self::PINNED_SESSION_KEYS, ...self::CONSUMED_SESSION_KEYS]);
        $this->putSession(['guest_id' => $this->guestId, 'address_id' => $this->addressId, 'billing_address_id' => $this->billingAddressId, 'newRegisterCustomerInfo' => $this->newCustomer]);

        return $before;
    }

    /** @param array<string, mixed> $sessionBefore */
    private function restoreSession(array $sessionBefore): void
    {
        $this->forgetSession([...self::PINNED_SESSION_KEYS, ...self::CONSUMED_SESSION_KEYS]);
        session()->put($sessionBefore);
    }

    /** @param list<string> $keys */
    private function forgetSession(array $keys): void
    {
        foreach ($keys as $key) {
            session()->forget($key);
        }
    }

    /** @param array<string, mixed> $values a key with no value is not put */
    private function putSession(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!empty($value)) {
                session()->put($key, $value);
            }
        }
    }
}
