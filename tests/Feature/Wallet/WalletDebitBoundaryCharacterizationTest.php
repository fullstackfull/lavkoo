<?php

namespace Tests\Feature\Wallet;

use App\Enums\SessionKey;
use App\Models\Admin;
use App\Models\User;
use App\Utils\Convert;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsAddressSchema;
use Tests\Support\BuildsApiAddressSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Where the wallet stops paying: an amount equal to the balance, one cent above it, and nothing at all, through the REST v1 wallet order,
 * the website's wallet checkout and the admin POS wallet sale. Each test pins what the code does today; a name with "currently" marks an
 * outcome that is today's behaviour and not necessarily the intended one.
 */
class WalletDebitBoundaryCharacterizationTest extends TestCase
{
    use BuildsAddressSchema;
    use BuildsApiAddressSchema;
    use RestoresLanguageFiles;

    private const API_WALLET = '/api/v1/customer/order/place-by-wallet';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();

        $this->buildAddressSchema();
        $this->createApiStoreTables();
        $this->createOrderPipelineTables();
        $this->useThrowawayPassportKeys();

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

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @return array{orders: int, order_details: int, wallet_transactions: int, wallet_balance: float} */
    private function ledgerOf(int $customerId): array
    {
        return [
            'orders' => DB::table('orders')->count(),
            'order_details' => DB::table('order_details')->count(),
            'wallet_transactions' => DB::table('wallet_transactions')->count(),
            'wallet_balance' => (float) DB::table('users')->where('id', $customerId)->value('wallet_balance'),
        ];
    }

    private function shopperWithCart(float $walletBalance, float $price = 100): User
    {
        $customer = $this->makeCustomer(overrides: ['wallet_balance' => $walletBalance]);
        $this->putOneProductInTheCart(callerId: $customer->id);
        DB::table('products')->where('id', 1)->update(['unit_price' => $price]);
        DB::table('carts')->where('customer_id', $customer->id)->update(['price' => $price]);

        return $customer;
    }

    private function asApiCustomer(User $customer): static
    {
        $this->actingAs($customer, 'api');

        return $this->withHeader('Authorization', 'Bearer test-token');
    }

    public function test_api_wallet_order_for_exactly_the_balance_is_placed_paid_and_empties_the_wallet(): void
    {
        $customer = $this->shopperWithCart(walletBalance: 100);

        $response = $this->asApiCustomer($customer)->getJson(self::API_WALLET);

        $response->assertOk()->assertJsonPath('order_ids', [100001]);
        $order = DB::table('orders')->where('id', 100001)->first();
        $this->assertSame(['pay_by_wallet', 'paid', 'confirmed'], [$order->payment_method, $order->payment_status, $order->order_status]);
        $this->assertEquals(100, $order->order_amount);
        $this->assertSame(['orders' => 1, 'order_details' => 1, 'wallet_transactions' => 1, 'wallet_balance' => 0.0], $this->ledgerOf(customerId: $customer->id));
        $transaction = DB::table('wallet_transactions')->first();
        $this->assertSame(['order_place', 'order payment'], [$transaction->transaction_type, $transaction->reference]);
        $this->assertEquals([100, 0, 0], [$transaction->debit, $transaction->credit, $transaction->balance]);
    }

    public function test_api_wallet_order_one_cent_above_the_balance_is_refused_with_nothing_written(): void
    {
        $customer = $this->shopperWithCart(walletBalance: 99.99);
        $before = $this->ledgerOf(customerId: $customer->id);

        $response = $this->asApiCustomer($customer)->getJson(self::API_WALLET);

        $response->assertStatus(403)->assertExactJson(['message' => 'inefficient balance in your wallet to pay for this order']);
        $this->assertSame(['orders' => 0, 'order_details' => 0, 'wallet_transactions' => 0, 'wallet_balance' => 99.99], $before);
        $this->assertSame($before, $this->ledgerOf(customerId: $customer->id));
        $this->assertSame(10, (int) DB::table('products')->where('id', 1)->value('current_stock'));
    }

    public function test_api_wallet_order_of_zero_is_currently_placed_as_paid_with_a_zero_debit_row(): void
    {
        $customer = $this->shopperWithCart(walletBalance: 50, price: 0);

        $response = $this->asApiCustomer($customer)->getJson(self::API_WALLET);

        $response->assertOk()->assertJsonPath('order_ids', [100001]);
        $order = DB::table('orders')->where('id', 100001)->first();
        $this->assertSame(['pay_by_wallet', 'paid'], [$order->payment_method, $order->payment_status]);
        $this->assertEquals(0, $order->order_amount);
        $this->assertSame(['orders' => 1, 'order_details' => 1, 'wallet_transactions' => 1, 'wallet_balance' => 50.0], $this->ledgerOf(customerId: $customer->id));
        $this->assertEquals([0, 50], [DB::table('wallet_transactions')->value('debit'), DB::table('wallet_transactions')->value('balance')]);
    }

    private function completeWebWalletCheckout(User $customer): TestResponse
    {
        $address = $this->makeLegacyAddress(customerId: $customer->id);

        return $this->actingAs($customer, 'customer')
            ->withSession(['address_id' => $address->id, 'billing_address_id' => $address->id])
            ->from(route('checkout-payment'))
            ->post(route('checkout-complete-wallet'));
    }

    public function test_web_wallet_checkout_for_exactly_the_balance_is_placed_paid_and_empties_the_wallet(): void
    {
        $customer = $this->shopperWithCart(walletBalance: 100);

        $response = $this->completeWebWalletCheckout($customer);

        $response->assertRedirect(route('account-oder'));
        $this->assertSame([100001], session('order_success_ids'));
        $order = DB::table('orders')->where('id', 100001)->first();
        $this->assertSame(['pay_by_wallet', 'paid', 'confirmed'], [$order->payment_method, $order->payment_status, $order->order_status]);
        $this->assertSame(['orders' => 1, 'order_details' => 1, 'wallet_transactions' => 1, 'wallet_balance' => 0.0], $this->ledgerOf(customerId: $customer->id));
        $this->assertEquals([100, 0], [DB::table('wallet_transactions')->value('debit'), DB::table('wallet_transactions')->value('balance')]);
    }

    public function test_web_wallet_checkout_one_cent_above_the_balance_goes_back_with_nothing_written(): void
    {
        $customer = $this->shopperWithCart(walletBalance: 99.99);
        $before = $this->ledgerOf(customerId: $customer->id);

        $response = $this->completeWebWalletCheckout($customer);

        $response->assertRedirect(route('checkout-payment'));
        $this->assertSame(['orders' => 0, 'order_details' => 0, 'wallet_transactions' => 0, 'wallet_balance' => 99.99], $before);
        $this->assertSame($before, $this->ledgerOf(customerId: $customer->id));
        $this->assertSame(10, (int) DB::table('products')->where('id', 1)->value('current_stock'));
        $this->assertSame(['Inefficient balance in your wallet to pay for this order!!'], array_column(session('toastr::messages', []), 'message'));
    }

    private function createAdminTables(): void
    {
        Schema::create('admin_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->text('module_access')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('admin_role_id')->default(1);
            $table->integer('status')->default(1);
            $table->timestamps();
        });
        DB::table('admin_roles')->insert(['id' => 1, 'name' => 'Master Admin', 'status' => 1]);
        DB::table('admins')->insert(['id' => 1, 'name' => 'Master Admin', 'email' => 'master@example.test', 'admin_role_id' => 1]);
    }

    private function placePosWalletSale(User $customer, float $amount): TestResponse
    {
        $this->createAdminTables();
        $cartId = 'saved-customer-' . $customer->id;
        $cart = [[
            'id' => 1, 'customerId' => $customer->id, 'customerOnHold' => false, 'quantity' => 1, 'price' => 100, 'name' => 'Paracetamol',
            'productType' => 'physical', 'image' => null, 'discount' => 0, 'variant' => '', 'variations' => [],
        ]];

        return $this->actingAs(Admin::findOrFail(1), 'admin')
            ->withSession([SessionKey::CURRENT_USER => $cartId, SessionKey::CART_NAME => [$cartId], $cartId => $cart])
            ->postJson(route('admin.pos.place-order'), ['amount' => $amount, 'type' => 'wallet', 'fulfillment' => 'instant']);
    }

    /** @return list<string> */
    private function toastMessages(): array
    {
        return array_column(array_values((array) session('laravel-toaster-magic::messages', [])), 'message');
    }

    public function test_pos_wallet_sale_for_exactly_the_balance_is_accepted_and_empties_the_wallet(): void
    {
        $customer = $this->makeCustomer(overrides: ['wallet_balance' => 100]);
        $this->putPosProductOnTheShelf();

        $response = $this->placePosWalletSale(customer: $customer, amount: 100);

        $response->assertOk();
        $this->assertSame(['Order placed successfully'], $this->toastMessages());
        $order = DB::table('orders')->where('id', 100001)->first();
        $this->assertSame(['wallet', 'paid', 'POS'], [$order->payment_method, $order->payment_status, $order->order_type]);
        $this->assertEquals([100, 100], [$order->order_amount, $order->paid_amount]);
        $this->assertSame(['orders' => 1, 'order_details' => 1, 'wallet_transactions' => 1, 'wallet_balance' => 0.0], $this->ledgerOf(customerId: $customer->id));
        $transaction = DB::table('wallet_transactions')->first();
        $this->assertSame(['order_place', 'order_place_in_pos'], [$transaction->transaction_type, $transaction->reference]);
        $this->assertEquals([100, 0], [$transaction->debit, $transaction->balance]);
        $this->assertSame(9, (int) DB::table('products')->where('id', 1)->value('current_stock'));
    }

    public function test_pos_wallet_sale_one_cent_above_the_balance_is_refused_with_nothing_written(): void
    {
        $customer = $this->makeCustomer(overrides: ['wallet_balance' => 99.99]);
        $this->putPosProductOnTheShelf();
        $before = $this->ledgerOf(customerId: $customer->id);

        $response = $this->placePosWalletSale(customer: $customer, amount: 100);

        $response->assertOk()->assertExactJson([]);
        $this->assertSame(['Need Sufficient Amount Balance'], $this->toastMessages());
        $this->assertSame(['orders' => 0, 'order_details' => 0, 'wallet_transactions' => 0, 'wallet_balance' => 99.99], $before);
        $this->assertSame($before, $this->ledgerOf(customerId: $customer->id));
        $this->assertSame(10, (int) DB::table('products')->where('id', 1)->value('current_stock'));
    }

    /** @return array<string, array{0: float}> */
    public static function amountsThatAreNotAPrice(): array
    {
        return ['zero' => [0.0], 'negative' => [-5.0]];
    }

    #[DataProvider('amountsThatAreNotAPrice')]
    public function test_pos_wallet_sale_of_zero_or_less_is_refused_with_nothing_written(float $amount): void
    {
        $customer = $this->makeCustomer(overrides: ['wallet_balance' => 100]);
        $this->putPosProductOnTheShelf();
        $before = $this->ledgerOf(customerId: $customer->id);

        $response = $this->placePosWalletSale(customer: $customer, amount: $amount);

        $response->assertOk()->assertExactJson([]);
        $this->assertSame(['You cannot place an order with an amount of zero. Please enter a valid amount.'], $this->toastMessages());
        $this->assertSame(['orders' => 0, 'order_details' => 0, 'wallet_transactions' => 0, 'wallet_balance' => 100.0], $before);
        $this->assertSame($before, $this->ledgerOf(customerId: $customer->id));
    }

    public function test_pos_wallet_sale_in_another_default_currency_compares_the_converted_amount_with_the_balance(): void
    {
        $this->useMultiCurrency();
        $customer = $this->makeCustomer(overrides: ['wallet_balance' => 10]);
        $this->putPosProductOnTheShelf();

        $this->placePosWalletSale(customer: $customer, amount: 37.5)->assertOk();

        $this->assertSame(['orders' => 1, 'order_details' => 1, 'wallet_transactions' => 1, 'wallet_balance' => 0.0], $this->ledgerOf(customerId: $customer->id));
        $this->assertEquals(10, DB::table('wallet_transactions')->value('debit'));
    }

    public function test_the_two_default_currency_conversions_currently_disagree_on_a_checkout_amount(): void
    {
        $this->useMultiCurrency();

        $this->assertSame(37.54, Convert::default(10.01), 'rounded to two decimals');
        $this->assertSame(37.53, usdToDefaultCurrency(amount: 10.01), 'floored to the decimal setting');
    }

    public function test_api_wallet_order_in_another_default_currency_debits_the_usd_order_amount(): void
    {
        $this->useMultiCurrency();
        $customer = $this->shopperWithCart(walletBalance: 100, price: 10.01);

        $this->asApiCustomer($customer)->getJson(self::API_WALLET)->assertOk();

        $this->assertEquals(10.01, DB::table('orders')->where('id', 100001)->value('order_amount'));
        $transaction = DB::table('wallet_transactions')->first();
        $this->assertSame([10.01, 89.99], [round((float) $transaction->debit, 6), round((float) $transaction->balance, 6)]);
        $this->assertSame(89.99, round($this->ledgerOf(customerId: $customer->id)['wallet_balance'], 6));
    }

    /** A 10.01 USD order debits 10.01 USD whatever the store currency, so a balance of exactly 10.01 USD pays for it and empties the wallet. */
    public function test_api_wallet_order_for_exactly_the_balance_in_another_default_currency_is_placed_and_empties_the_wallet(): void
    {
        $this->useMultiCurrency();
        $customer = $this->shopperWithCart(walletBalance: 10.01, price: 10.01);

        $this->asApiCustomer($customer)->getJson(self::API_WALLET)->assertOk();

        $ledger = $this->ledgerOf(customerId: $customer->id);
        $this->assertSame([1, 1, 1], [$ledger['orders'], $ledger['order_details'], $ledger['wallet_transactions']]);
        $this->assertSame(['paid', 'pay_by_wallet'], [DB::table('orders')->value('payment_status'), DB::table('orders')->value('payment_method')]);
        $this->assertSame(0.0, round($ledger['wallet_balance'], 6));
    }

    private function putPosProductOnTheShelf(): void
    {
        DB::table('products')->insert(['id' => 1, 'name' => 'Paracetamol', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 100, 'current_stock' => 10]);
    }

    private function useMultiCurrency(): void
    {
        DB::table('currencies')->where('id', 1)->update(['exchange_rate' => 3.75]);
        DB::table('currencies')->insert(['id' => 2, 'name' => 'US Dollar', 'code' => 'USD', 'symbol' => '$', 'exchange_rate' => 1, 'status' => 1]);
        DB::table('business_settings')->where('type', 'currency_model')->update(['value' => json_encode('multi_currency')]);
        DB::table('business_settings')->insert(['type' => 'decimal_point_settings', 'value' => '2']);
        Cache::flush();
        $this->useTaxIncludedPrices();
    }
}
