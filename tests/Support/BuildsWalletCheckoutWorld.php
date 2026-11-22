<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/**
 * A store where a customer pays a one-line cart from the wallet, through the REST v1 wallet order or the website's wallet checkout, in any
 * store default currency. Wallet amounts are DECIMAL(40,20), as the 2026_05_07 migration makes them on the live schema, so what the writer
 * stores is not rounded away by the test table.
 */
trait BuildsWalletCheckoutWorld
{
    use BuildsAddressSchema;
    use BuildsApiAddressSchema;

    protected const API_WALLET_ORDER = '/api/v1/customer/order/place-by-wallet';

    protected function buildWalletCheckoutWorld(): void
    {
        $this->buildAddressSchema();
        $this->createApiStoreTables();
        $this->createOrderPipelineTables();
        $this->useThrowawayPassportKeys();

        Schema::table('users', function (Blueprint $table) {
            $table->decimal('wallet_balance', 40, 20)->default(0)->change();
        });
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('transaction_id')->nullable();
            $table->decimal('credit', 40, 20)->default(0);
            $table->decimal('debit', 40, 20)->default(0);
            $table->decimal('admin_bonus', 40, 20)->default(0);
            $table->decimal('balance', 40, 20)->default(0);
            $table->string('transaction_type')->nullable();
            $table->string('reference')->nullable();
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });
        DB::table('business_settings')->insert(['type' => 'wallet_status', 'value' => '1']);
        Cache::flush();
        $this->useTaxIncludedPrices();
    }

    /** The store's default currency, its rate to USD and its decimal_point_settings; USD stays the base the wallet is kept in. */
    protected function useStoreCurrency(string $code, string $rate, int $decimals): void
    {
        DB::table('currencies')->where('id', 1)->update(['name' => $code, 'code' => $code, 'symbol' => $code, 'exchange_rate' => $rate]);
        if ($code !== 'USD') {
            DB::table('currencies')->insert(['id' => 2, 'name' => 'US Dollar', 'code' => 'USD', 'symbol' => '$', 'exchange_rate' => 1, 'status' => 1]);
        }
        DB::table('business_settings')->where('type', 'currency_model')->update(['value' => json_encode('multi_currency')]);
        DB::table('business_settings')->insert(['type' => 'decimal_point_settings', 'value' => (string) $decimals]);
        Cache::flush();
        $this->useTaxIncludedPrices();
    }

    protected function shopperWithCart(string $walletBalance, string $price): User
    {
        $customer = $this->makeCustomer(overrides: ['wallet_balance' => $walletBalance]);
        $this->putOneProductInTheCart(callerId: $customer->id);
        DB::table('products')->where('id', 1)->update(['unit_price' => $price]);
        DB::table('carts')->where('customer_id', $customer->id)->update(['price' => $price]);

        return $customer;
    }

    protected function placeApiWalletOrder(User $customer): TestResponse
    {
        $this->actingAs($customer, 'api');

        return $this->withHeader('Authorization', 'Bearer test-token')->getJson(self::API_WALLET_ORDER);
    }

    protected function completeWebWalletCheckout(User $customer): TestResponse
    {
        $address = $this->makeLegacyAddress(customerId: $customer->id);

        return $this->actingAs($customer, 'customer')
            ->withSession(['address_id' => $address->id, 'billing_address_id' => $address->id])
            ->from(route('checkout-payment'))
            ->post(route('checkout-complete-wallet'));
    }

    /** An amount as an exact decimal string, rounded half up to $scale places, so it is compared as text and never as a float. */
    protected static function decimal(string|float|int|null $amount, int $scale = 6): string
    {
        return number_format((float) ($amount ?? 0), $scale, '.', '');
    }

    /** @return array{orders: int, paid_orders: int, wallet_transactions: int, debited: string, wallet_balance: string} */
    protected function walletLedgerOf(int $customerId): array
    {
        return [
            'orders' => DB::table('orders')->count(),
            'paid_orders' => DB::table('orders')->where('payment_status', 'paid')->count(),
            'wallet_transactions' => DB::table('wallet_transactions')->count(),
            'debited' => self::decimal(DB::table('wallet_transactions')->where('user_id', $customerId)->sum('debit')),
            'wallet_balance' => self::decimal(DB::table('users')->where('id', $customerId)->value('wallet_balance')),
        ];
    }

    protected function assertWalletIsNotNegative(int $customerId): void
    {
        $balance = self::decimal(DB::table('users')->where('id', $customerId)->value('wallet_balance'), scale: 8);
        $this->assertGreaterThanOrEqual(0, bccomp($balance, '0', 8), "wallet balance {$balance} is below zero");
    }
}
