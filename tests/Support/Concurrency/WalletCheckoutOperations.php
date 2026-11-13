<?php

namespace Tests\Support\Concurrency;

use App\Enums\Address\AddressChannel;
use App\Models\User;
use App\Services\Wallet\WalletPaymentRefused;
use App\Utils\OrderManager;
use Tests\Support\BuildsApiAddressSchema;

/** A wallet checkout run through the order writer the way the REST checkout calls it, one per process, against one customer. */
final class WalletCheckoutOperations
{
    /**
     * @param array{customer_id: int, amount: float} $args
     * @return string 'placed', 'refused' when the wallet debit was refused, or 'empty' when the writer placed nothing
     */
    public static function checkout(array $args): string
    {
        self::useTaxIncludedPrices();
        $customer = User::query()->findOrFail($args['customer_id']);
        $request = request();
        $request->merge(['user' => $customer]);
        $request->setUserResolver(static fn () => $customer);

        try {
            $orderIds = OrderManager::generateOrder(data: [
                'is_guest' => 0, 'customer_id' => $customer->id, 'address_channel' => AddressChannel::Api, 'order_status' => 'confirmed',
                'payment_method' => 'pay_by_wallet', 'payment_status' => 'paid', 'transaction_ref' => '', 'requestObj' => $request,
                'walletPayment' => ['customerId' => $customer->id, 'amount' => (float) $args['amount']],
            ]);
        } catch (WalletPaymentRefused) {
            return 'refused';
        }

        return $orderIds === [] ? 'empty' : 'placed';
    }

    /** The tax set-up the parent test put in its own cache, which a worker process does not share. */
    private static function useTaxIncludedPrices(): void
    {
        (new class {
            use BuildsApiAddressSchema {
                useTaxIncludedPrices as public;
            }
        })->useTaxIncludedPrices();
    }
}
