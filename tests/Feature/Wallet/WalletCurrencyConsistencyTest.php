<?php

namespace Tests\Feature\Wallet;

use App\Enums\SessionKey;
use App\Models\Admin;
use App\Models\User;
use App\Utils\Convert;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsWalletCheckoutWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The wallet is kept in USD and an order amount is in USD, but the wallet checkouts debit Convert::default($amount), which is rounded to two
 * decimals in the store currency, and the writer turns that back into USD with Convert::usd, unrounded. These tests record, for SAR, USD, KWD,
 * BHD and OMR as the store default, what the balance is compared with, what is debited in USD and what is left.
 *
 * The rates are fixtures (SAR 3.75, KWD 0.307, BHD 0.376, OMR 0.3845 to the dollar), each currency with its own decimal_point_settings.
 * A name with "currently" marks today's behaviour, not necessarily the intended one.
 */
class WalletCurrencyConsistencyTest extends TestCase
{
    use BuildsWalletCheckoutWorld;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        $this->buildWalletCheckoutWorld();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @return array<string, array{code: string, rate: string, decimals: int, defaultAmount: float, flooredAmount: float, debitedUsd: string, overOrderAmount: string}> */
    public static function conversionsOfTenDollarsOneCent(): array
    {
        return [
            'SAR' => ['code' => 'SAR', 'rate' => '3.75', 'decimals' => 2, 'defaultAmount' => 37.54, 'flooredAmount' => 37.53, 'debitedUsd' => '10.010667', 'overOrderAmount' => '0.000667'],
            'USD' => ['code' => 'USD', 'rate' => '1', 'decimals' => 2, 'defaultAmount' => 10.01, 'flooredAmount' => 10.01, 'debitedUsd' => '10.010000', 'overOrderAmount' => '0.000000'],
            'KWD' => ['code' => 'KWD', 'rate' => '0.307', 'decimals' => 3, 'defaultAmount' => 3.07, 'flooredAmount' => 3.073, 'debitedUsd' => '10.000000', 'overOrderAmount' => '-0.010000'],
            'BHD' => ['code' => 'BHD', 'rate' => '0.376', 'decimals' => 3, 'defaultAmount' => 3.76, 'flooredAmount' => 3.763, 'debitedUsd' => '10.000000', 'overOrderAmount' => '-0.010000'],
            'OMR' => ['code' => 'OMR', 'rate' => '0.3845', 'decimals' => 3, 'defaultAmount' => 3.85, 'flooredAmount' => 3.848, 'debitedUsd' => '10.013004', 'overOrderAmount' => '0.003004'],
        ];
    }

    #[DataProvider('conversionsOfTenDollarsOneCent')]
    public function test_the_store_currency_conversions_of_an_order_amount_are(string $code, string $rate, int $decimals, float $defaultAmount, float $flooredAmount, string $debitedUsd, string $overOrderAmount): void
    {
        $this->useStoreCurrency(code: $code, rate: $rate, decimals: $decimals);

        $this->assertSame($defaultAmount, Convert::default(10.01), 'Convert::default rounds to two decimals whatever the decimal setting');
        $this->assertSame($flooredAmount, usdToDefaultCurrency(amount: 10.01), 'usdToDefaultCurrency floors to the decimal setting');
        $this->assertSame($debitedUsd, self::decimal(Convert::usd(Convert::default(10.01))), 'what the writer debits for a 10.01 USD order');
        $this->assertSame($overOrderAmount, bcsub(self::decimal(Convert::usd(Convert::default(10.01))), '10.010000', 6), 'debited minus the order amount');
    }

    /** @return array<string, array{code: string, rate: string, decimals: int, ledger: array<string, int|string>}> */
    public static function anOrderEqualToTheBalanceInUsd(): array
    {
        $placed = static fn (string $debited, string $left): array => ['orders' => 1, 'paid_orders' => 1, 'wallet_transactions' => 1, 'debited' => $debited, 'wallet_balance' => $left];

        return [
            'SAR' => ['code' => 'SAR', 'rate' => '3.75', 'decimals' => 2, 'ledger' => $placed('10.010667', '-0.000667')],
            'USD' => ['code' => 'USD', 'rate' => '1', 'decimals' => 2, 'ledger' => $placed('10.010000', '0.000000')],
            'KWD' => ['code' => 'KWD', 'rate' => '0.307', 'decimals' => 3, 'ledger' => $placed('10.000000', '0.010000')],
            'BHD' => ['code' => 'BHD', 'rate' => '0.376', 'decimals' => 3, 'ledger' => $placed('10.000000', '0.010000')],
            'OMR' => ['code' => 'OMR', 'rate' => '0.3845', 'decimals' => 3, 'ledger' => $placed('10.013004', '-0.003004')],
        ];
    }

    /** The balance check compares the 10.01 USD order amount; the debit is the rounded store amount turned back into USD. */
    #[DataProvider('anOrderEqualToTheBalanceInUsd')]
    public function test_api_wallet_order_equal_to_the_balance_in_usd_currently_debits_the_rounded_store_amount(string $code, string $rate, int $decimals, array $ledger): void
    {
        $this->useStoreCurrency(code: $code, rate: $rate, decimals: $decimals);
        $customer = $this->shopperWithCart(walletBalance: '10.01', price: '10.01');

        $this->placeApiWalletOrder($customer)->assertOk();

        $this->assertSame('10.01', self::decimal(DB::table('orders')->value('order_amount'), scale: 2), 'the amount the balance was checked against');
        $this->assertSame($ledger, $this->walletLedgerOf(customerId: $customer->id));
    }

    #[DataProvider('anOrderEqualToTheBalanceInUsd')]
    public function test_web_wallet_checkout_equal_to_the_balance_in_usd_currently_debits_the_rounded_store_amount(string $code, string $rate, int $decimals, array $ledger): void
    {
        $this->useStoreCurrency(code: $code, rate: $rate, decimals: $decimals);
        $customer = $this->shopperWithCart(walletBalance: '10.01', price: '10.01');

        $this->completeWebWalletCheckout($customer)->assertRedirect(route('account-oder'));

        $this->assertSame($ledger, $this->walletLedgerOf(customerId: $customer->id));
    }

    /** @return array<string, array{code: string, rate: string, decimals: int, exactAmount: string, oneUnitOver: string}> */
    public static function posSalesOfTheWholeBalance(): array
    {
        return [
            'SAR' => ['code' => 'SAR', 'rate' => '3.75', 'decimals' => 2, 'exactAmount' => '37.50', 'oneUnitOver' => '37.51'],
            'USD' => ['code' => 'USD', 'rate' => '1', 'decimals' => 2, 'exactAmount' => '10.00', 'oneUnitOver' => '10.01'],
            'KWD' => ['code' => 'KWD', 'rate' => '0.307', 'decimals' => 3, 'exactAmount' => '3.070', 'oneUnitOver' => '3.071'],
            'BHD' => ['code' => 'BHD', 'rate' => '0.376', 'decimals' => 3, 'exactAmount' => '3.760', 'oneUnitOver' => '3.761'],
            'OMR' => ['code' => 'OMR', 'rate' => '0.3845', 'decimals' => 3, 'exactAmount' => '3.845', 'oneUnitOver' => '3.846'],
        ];
    }

    /** The admin POS converts the store amount with currencyConverter for the check and for the debit, so both sides are in one unit. */
    #[DataProvider('posSalesOfTheWholeBalance')]
    public function test_pos_wallet_sale_compares_and_debits_in_one_unit(string $code, string $rate, int $decimals, string $exactAmount, string $oneUnitOver): void
    {
        $this->useStoreCurrency(code: $code, rate: $rate, decimals: $decimals);
        $this->createAdminTables();
        DB::table('products')->insert(['id' => 1, 'name' => 'Paracetamol', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 100, 'current_stock' => 10]);
        $refused = $this->makeCustomer(overrides: ['wallet_balance' => '10']);
        $paid = $this->makeCustomer(overrides: ['wallet_balance' => '10']);

        $this->placePosWalletSale(customer: $refused, amount: $oneUnitOver)->assertOk()->assertExactJson([]);
        $this->assertSame(['Need Sufficient Amount Balance'], $this->toastMessages());
        $this->assertSame(['orders' => 0, 'paid_orders' => 0, 'wallet_transactions' => 0, 'debited' => '0.000000', 'wallet_balance' => '10.000000'], $this->walletLedgerOf(customerId: $refused->id));

        $this->placePosWalletSale(customer: $paid, amount: $exactAmount)->assertOk();
        $this->assertSame(['orders' => 1, 'paid_orders' => 1, 'wallet_transactions' => 1, 'debited' => '10.000000', 'wallet_balance' => '0.000000'], $this->walletLedgerOf(customerId: $paid->id));
        $this->assertWalletIsNotNegative(customerId: $paid->id);
    }

    /**
     * The order is written as paid before the wallet is touched, and the result of the debit is not looked at: with the wallet switched off
     * CustomerManager::create_wallet_transaction returns false, and a paid order is left that nothing paid for. PR 12
     * (gcc/p0-wallet-debit-result-handling) moves the debit into the order transaction and changes this.
     */
    public function test_api_wallet_order_whose_debit_is_refused_is_currently_left_paid_with_nothing_debited(): void
    {
        $customer = $this->shopperWithCart(walletBalance: '100', price: '100');
        DB::table('business_settings')->where('type', 'wallet_status')->update(['value' => '0']);

        $this->placeApiWalletOrder($customer)->assertOk()->assertJsonPath('order_ids', [100001]);

        $this->assertSame(['pay_by_wallet', 'paid'], [DB::table('orders')->value('payment_method'), DB::table('orders')->value('payment_status')]);
        $this->assertSame(['orders' => 1, 'paid_orders' => 1, 'wallet_transactions' => 0, 'debited' => '0.000000', 'wallet_balance' => '100.000000'], $this->walletLedgerOf(customerId: $customer->id));
    }

    /** As the REST order above; PR 12 (gcc/p0-wallet-debit-result-handling) changes it. */
    public function test_web_wallet_checkout_whose_debit_is_refused_is_currently_left_paid_with_nothing_debited(): void
    {
        $customer = $this->shopperWithCart(walletBalance: '100', price: '100');
        DB::table('business_settings')->where('type', 'wallet_status')->update(['value' => '0']);

        $this->completeWebWalletCheckout($customer)->assertRedirect(route('account-oder'));

        $this->assertSame([100001], session('order_success_ids'));
        $this->assertSame(['orders' => 1, 'paid_orders' => 1, 'wallet_transactions' => 0, 'debited' => '0.000000', 'wallet_balance' => '100.000000'], $this->walletLedgerOf(customerId: $customer->id));
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

    private function placePosWalletSale(User $customer, string $amount): TestResponse
    {
        $cartId = 'saved-customer-' . $customer->id;
        $cart = [[
            'id' => 1, 'customerId' => $customer->id, 'customerOnHold' => false, 'quantity' => 1, 'price' => 100, 'name' => 'Paracetamol',
            'productType' => 'physical', 'image' => null, 'discount' => 0, 'variant' => '', 'variations' => [],
        ]];

        return $this->actingAs(Admin::findOrFail(1), 'admin')
            ->withSession([SessionKey::CURRENT_USER => $cartId, SessionKey::CART_NAME => [$cartId], $cartId => $cart, 'laravel-toaster-magic::messages' => []])
            ->postJson(route('admin.pos.place-order'), ['amount' => $amount, 'type' => 'wallet', 'fulfillment' => 'instant']);
    }

    /** @return list<string> */
    private function toastMessages(): array
    {
        return array_column(array_values((array) session('laravel-toaster-magic::messages', [])), 'message');
    }
}
