<?php

namespace App\Services\Checkout;

use ArrayAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One submit of a customer's checkout places one order, however many times that submit arrives (D1-14).
 *
 * The cash-on-delivery, offline and wallet checkouts have no key of any kind. A customer who double-taps "place order", a phone that
 * retries a request whose answer was slow, a browser that re-posts on refresh: each arrival reads the cart, builds its own order and
 * takes its own stock, and nothing afterwards can say the two orders came from one submit. `order_group_id` does not say it -- it
 * separates the vendor split of ONE basket, not one checkout from the next -- which is what the order-integrity panel means by
 * `checkout_idempotency_key: not configured`.
 *
 * The key is a client one: the `Idempotency-Key` header, the same header the seller app's point-of-sale sale already honours
 * (`pos_sale_idempotency_keys`). Whether the SERVER should instead issue a per-checkout token that the apps must send back is an open
 * owner decision, because it needs a release of both apps; accepting the header needs none, and until a client sends one this class
 * answers null and every checkout behaves exactly as it does today. A database that has not run the migration answers null too.
 *
 * ## Why the claim lives inside the order's transaction
 *
 * The claim is one INSERT under a unique key, made inside the transaction `OrderManager::generateOrder` writes the order in. That gives
 * three things at once, and none of them survives moving it:
 *
 *   - the row IS the lock. A second submit's insert meets the key and waits on the uncommitted row until the first submit commits, so
 *     two simultaneous submits cannot both pass a check;
 *   - a submit whose order write fails takes its claim back with it, so the key is free and the customer can try again. A key marked
 *     before the write would have stranded them;
 *   - the second submit then reads the orders the first placed and is answered WITH THEM, rather than with a refusal. The customer who
 *     tapped twice sees one order, which is what they meant, instead of an error.
 *
 * The key is scoped to whoever is submitting, so two customers who happen to send the same key are two submits. A guest is scoped by
 * their guest id, which is the id their cart was made under.
 */
final readonly class OneOrderPerCheckoutSubmit
{
    public const HEADER = 'Idempotency-Key';

    private const TABLE = 'checkout_idempotency_keys';

    /** The longest key that is stored. A client that sends more than this names no submit rather than a truncated one. */
    private const KEY_LIMIT = 191;

    private function __construct(private string $scope, private string $key)
    {
    }

    /**
     * The submit this checkout names, or null when it names none -- which is every checkout of this platform today.
     *
     * @param array<string, mixed> $customerInfo as `OrderManager::getCustomerInfoForOrder` returns it
     */
    public static function of(object|array|null $data, array $customerInfo): ?self
    {
        if (!Schema::hasTable(self::TABLE)) {
            return null;
        }

        $key = self::keyOn(request: self::requestOf($data));
        $scope = self::scopeOf($customerInfo);

        return ($key === null || $scope === null) ? null : new self(scope: $scope, key: $key);
    }

    /** False when this submit's key already belongs to another submit, whether that one has been written or is still writing. */
    public function claim(): bool
    {
        return DB::table(self::TABLE)->insertOrIgnore([
            'customer_scope' => $this->scope, 'idempotency_key' => $this->key, 'order_ids' => null, 'created_at' => now(), 'updated_at' => now(),
        ]) === 1;
    }

    /**
     * The orders the submit that holds this key placed. The read takes the row's lock, so a second submit that arrived while the first
     * was still writing waits here for it to commit and then answers with the orders it wrote.
     *
     * @return list<int> empty when the submit that holds the key placed none
     */
    public function theOrdersItAlreadyPlaced(): array
    {
        $recorded = DB::table(self::TABLE)
            ->where(['customer_scope' => $this->scope, 'idempotency_key' => $this->key])
            ->lockForUpdate()
            ->value('order_ids');

        $orderIds = json_decode((string) $recorded, true);

        return is_array($orderIds) ? array_values(array_map(static fn ($orderId): int => (int) $orderId, $orderIds)) : [];
    }

    /**
     * Gives the key back, for a submit that placed nothing. Without this an empty cart, or a cart every line of which was refused, would
     * burn the key: the row would stay with no orders on it and every later submit carrying it would be answered "that submit placed
     * nothing" forever. A submit that placed nothing is not a submit that was already honoured.
     */
    public function release(): void
    {
        DB::table(self::TABLE)->where(['customer_scope' => $this->scope, 'idempotency_key' => $this->key])->delete();
    }

    /** @param list<int|string> $orderIds */
    public function record(array $orderIds): void
    {
        DB::table(self::TABLE)
            ->where(['customer_scope' => $this->scope, 'idempotency_key' => $this->key])
            ->update([
                'order_ids' => json_encode(array_values(array_map(static fn ($orderId): int => (int) $orderId, $orderIds))),
                'updated_at' => now(),
            ]);
    }

    /**
     * `requestObj` is NOT always a Request: the payment-settlement path builds it as an ARRAY and merges that array into the global
     * request before calling the order writer, so the global one is where its input actually is. A gateway callback carries no
     * idempotency header either way, which is what the paid path's own guard is for.
     */
    private static function requestOf(object|array|null $data): Request
    {
        $requestObject = is_array($data) || $data instanceof ArrayAccess ? ($data['requestObj'] ?? null) : null;

        return $requestObject instanceof Request ? $requestObject : request();
    }

    private static function keyOn(Request $request): ?string
    {
        $key = trim((string) $request->header(self::HEADER, ''));

        return ($key === '' || strlen($key) > self::KEY_LIMIT) ? null : $key;
    }

    /** @param array<string, mixed> $customerInfo */
    private static function scopeOf(array $customerInfo): ?string
    {
        $submitter = trim((string) ($customerInfo['customer_id'] ?? ''));

        if ($submitter === '' || $submitter === '0') {
            return null;
        }

        return (($customerInfo['is_guest'] ?? 0) ? 'guest' : 'customer') . ':' . $submitter;
    }
}
