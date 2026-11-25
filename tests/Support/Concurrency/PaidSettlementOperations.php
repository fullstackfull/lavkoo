<?php

namespace Tests\Support\Concurrency;

use App\Models\User;
use App\Utils\OrderManager;
use Tests\Support\BuildsApiAddressSchema;

/**
 * What a worker process does in the paid-callback concurrency test: build the orders of one gateway payment through the real order writer,
 * with exactly the data the settlement hook hands it -- paid, carrying the gateway's reference and the payment's own row.
 *
 * It calls the order writer rather than the hook on purpose. The hook's first act is to read whether an order for this reference exists,
 * and that read would answer the question in the lucky orderings where one delivery finishes before the other starts. Going straight to the
 * writer leaves only one thing between two deliveries and two orders: the claim made inside the order's transaction, which is the subject.
 */
final class PaidSettlementOperations
{
    /**
     * @param array{customer: int, address: int, reference: string, payment: string} $args
     * @return list<int> the ids of the orders written; empty when another delivery had already written them
     */
    public static function settle(array $args): array
    {
        self::useTaxIncludedPrices();
        $customer = User::findOrFail($args['customer']);
        request()->merge(['user' => $customer]);
        request()->setUserResolver(static fn () => $customer);

        return OrderManager::generateOrder(data: [
            'is_guest' => 0, 'customer_id' => $customer->id, 'order_status' => 'confirmed', 'payment_method' => 'ssl_commerz',
            'payment_status' => 'paid', 'transaction_ref' => $args['reference'], 'payment_record_id' => $args['payment'],
            'requestObj' => request(), 'address_id' => $args['address'], 'enforce_address_rules' => false,
            'stock_policy' => 'allow_oversell',
        ]);
    }

    /** The tax set-up lives in the cache, which is private to each process: every worker puts in the one the test process uses. */
    private static function useTaxIncludedPrices(): void
    {
        (new class {
            use BuildsApiAddressSchema {
                useTaxIncludedPrices as public;
            }
        })->useTaxIncludedPrices();
    }
}
