<?php

namespace Tests\Feature\Wallet;

use App\Enums\Address\AddressChannel;
use App\Models\User;
use App\Services\Wallet\WalletPaymentRefused;
use App\Utils\OrderManager;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\Wallet\WalletTables;

/**
 * A wallet checkout pays inside the order transaction: a paid order is committed together with the debit that paid for it, or not
 * at all. The balance check of the controllers runs before the customer's row is locked; only the writer's check, made under the
 * lock, decides, and a refusal there leaves no order, no stock movement and no wallet movement behind.
 */
class WalletCheckoutTest extends ApiAddressTestCase
{
    private const WALLET = '/api/v1/customer/order/place-by-wallet';

    /** The one cart line of putOneProductInTheCart() costs this much. */
    private const CART_TOTAL = 100.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        WalletTables::createLedger();
        WalletTables::setSetting('wallet_status', '1');
    }

    private function stock(): int
    {
        return (int) DB::table('products')->where('id', 1)->value('current_stock');
    }

    private function walletOf(User $customer): float
    {
        return (float) DB::table('users')->where('id', $customer->id)->value('wallet_balance');
    }

    private function assertNothingWasTaken(User $customer, float $walletBalance): void
    {
        $this->assertSame(0, DB::table('orders')->count(), 'no order');
        $this->assertSame(0, DB::table('order_details')->count(), 'no order line');
        $this->assertSame(10, $this->stock(), 'no stock was taken');
        $this->assertSame($walletBalance, $this->walletOf($customer), 'the wallet is as it was');
        $this->assertSame(0, DB::table('wallet_transactions')->count(), 'no ledger row');
    }

    /** A customer whose request carries a balance of $seenBalance while the database already holds $storedBalance. */
    private function customerWithAStaleBalance(float $seenBalance, float $storedBalance): User
    {
        $customer = $this->makeCustomer(['wallet_balance' => $seenBalance]);
        DB::table('users')->where('id', $customer->id)->update(['wallet_balance' => $storedBalance]);
        $this->putOneProductInTheCart($customer->id);

        return $customer;
    }

    private function generateWalletOrderFor(User $customer, float $amount): array
    {
        request()->merge(['user' => $customer]);

        return OrderManager::generateOrder(data: [
            'is_guest' => 0, 'customer_id' => $customer->id, 'address_channel' => AddressChannel::Api, 'order_status' => 'confirmed',
            'payment_method' => 'pay_by_wallet', 'payment_status' => 'paid', 'transaction_ref' => '', 'requestObj' => request(),
            'walletPayment' => ['customerId' => $customer->id, 'amount' => $amount],
        ]);
    }

    public function test_a_wallet_order_is_paid_with_exactly_one_debit_written_with_it(): void
    {
        $customer = $this->makeCustomer(['wallet_balance' => 150]);
        $this->putOneProductInTheCart($customer->id);

        $this->asCustomer($customer)->getJson(self::WALLET)->assertOk()->assertJsonPath('order_ids.0', 100001);

        $this->assertSame(50.0, $this->walletOf($customer));
        $this->assertSame(9, $this->stock());
        $ledger = DB::table('wallet_transactions')->get();
        $this->assertCount(1, $ledger);
        $this->assertEquals([self::CART_TOTAL, 'order_place', 'order payment'], [$ledger[0]->debit, $ledger[0]->transaction_type, $ledger[0]->reference]);
        $this->assertSame('paid', DB::table('orders')->where('id', 100001)->value('payment_status'));
    }

    public function test_a_balance_drained_after_the_controller_checked_it_refuses_the_order_and_takes_nothing(): void
    {
        $customer = $this->customerWithAStaleBalance(seenBalance: 150, storedBalance: 40);

        $this->asCustomer($customer)->getJson(self::WALLET)
            ->assertStatus(403)
            ->assertExactJson(['message' => 'inefficient balance in your wallet to pay for this order']);

        $this->assertNothingWasTaken($customer, walletBalance: 40.0);
    }

    public function test_a_refused_order_can_be_placed_on_retry_once_the_wallet_is_topped_up(): void
    {
        $customer = $this->customerWithAStaleBalance(seenBalance: 150, storedBalance: 40);
        $this->asCustomer($customer)->getJson(self::WALLET)->assertStatus(403);

        DB::table('users')->where('id', $customer->id)->update(['wallet_balance' => 150]);
        $this->asCustomer($customer)->getJson(self::WALLET)->assertOk();

        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(50.0, $this->walletOf($customer));
        $this->assertSame(1, DB::table('wallet_transactions')->count());
    }

    public function test_a_wallet_switched_off_between_the_check_and_the_write_refuses_the_order(): void
    {
        $customer = $this->makeCustomer(['wallet_balance' => 150]);
        $this->putOneProductInTheCart($customer->id);
        WalletTables::setSetting('wallet_status', '0');

        $this->asCustomer($customer)->getJson(self::WALLET)->assertStatus(403);

        $this->assertNothingWasTaken($customer, walletBalance: 150.0);
    }

    public function test_the_order_writer_called_directly_throws_on_a_refused_debit_and_rolls_the_order_back(): void
    {
        $customer = $this->customerWithAStaleBalance(seenBalance: 150, storedBalance: 40);

        try {
            $this->generateWalletOrderFor($customer, amount: self::CART_TOTAL);
            $this->fail('A refused wallet debit must not be reported as an order, nor as an empty result.');
        } catch (WalletPaymentRefused $refusal) {
            $this->assertSame([$customer->id, self::CART_TOTAL], [$refusal->customerId, $refusal->amount]);
        }

        $this->assertNothingWasTaken($customer, walletBalance: 40.0);
    }

    public function test_an_order_refused_for_stock_inside_the_writer_takes_nothing_from_the_wallet(): void
    {
        $customer = $this->makeCustomer(['wallet_balance' => 150]);
        $this->putOneProductInTheCart($customer->id);
        DB::table('products')->where('id', 1)->update(['variation' => json_encode([['type' => 'Box', 'qty' => 0]])]);
        DB::table('carts')->where('customer_id', $customer->id)->update(['variant' => 'Box']);

        $this->assertSame([], $this->generateWalletOrderFor($customer, amount: self::CART_TOTAL));

        $this->assertNothingWasTaken($customer, walletBalance: 150.0);
    }

    public function test_an_order_without_a_wallet_payment_does_not_touch_the_wallet(): void
    {
        $customer = $this->makeCustomer(['wallet_balance' => 150]);
        $this->putOneProductInTheCart($customer->id);
        request()->merge(['user' => $customer]);

        $orderIds = OrderManager::generateOrder(data: [
            'is_guest' => 0, 'customer_id' => $customer->id, 'address_channel' => AddressChannel::Api, 'order_status' => 'pending',
            'payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid', 'transaction_ref' => '', 'requestObj' => request(),
        ]);

        $this->assertCount(1, $orderIds);
        $this->assertSame(150.0, $this->walletOf($customer));
        $this->assertSame(0, DB::table('wallet_transactions')->count());
    }
}
