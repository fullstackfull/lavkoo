<?php

namespace Tests\Support\Concurrency;

use App\Models\User;
use App\Utils\OrderManager;
use Tests\Support\BuildsApiAddressSchema;

/** What a worker process does in the storefront checkout concurrency test: place the customer's cart through the real order writer. */
final class CheckoutOperations
{
    /**
     * @param array{customer: int, address: int} $args
     * @return list<int> the ids of the orders written; empty when the order writer refused the cart
     */
    public static function generateOrder(array $args): array
    {
        self::useTaxIncludedPrices();
        $customer = User::findOrFail($args['customer']);
        request()->merge(['user' => $customer]);

        return OrderManager::generateOrder(data: [
            'is_guest' => 0, 'customer_id' => $customer->id, 'order_status' => 'pending', 'payment_method' => 'cash_on_delivery',
            'payment_status' => 'unpaid', 'transaction_ref' => '', 'requestObj' => request(), 'address_id' => $args['address'],
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
