<?php

namespace Tests\Feature\Wallet;

use App\Enums\SessionKey;
use App\Models\Admin;
use App\Models\User;
use App\Services\Customer\WalletOrderDebit;
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
 * A wallet order debits its USD amount rounded half up to whole cents, once (WalletOrderDebit), in every store currency. The store amount is
 * still what the customer is shown (Convert::default), but it is no longer turned back into USD for the debit. Before this, a 10.01 USD order
 * debited Convert::usd(Convert::default(10.01)): 10.010667 in SAR, which was refused against 10.01 USD, and 10.00 in KWD and BHD, which left a
 * cent behind. The admin POS is not changed here; its tests pin what it does today.
 *
 * The rates are fixtures (SAR 3.75, KWD 0.307, BHD 0.376, OMR 0.3845 to the dollar), each currency with its own decimal_point_settings.
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

    /** @return array<string, array{code: string, rate: string, decimals: int}> */
    public static function storeCurrencies(): array
    {
        return [
            'SAR' => ['code' => 'SAR', 'rate' => '3.75', 'decimals' => 2],
            'USD' => ['code' => 'USD', 'rate' => '1', 'decimals' => 2],
            'KWD' => ['code' => 'KWD', 'rate' => '0.307', 'decimals' => 3],
            'BHD' => ['code' => 'BHD', 'rate' => '0.376', 'decimals' => 3],
            'OMR' => ['code' => 'OMR', 'rate' => '0.3845', 'decimals' => 3],
        ];
    }

    /** @return array<string, array{code: string, rate: string, decimals: int, shownAmount: float}> */
    public static function storeAmountsShownForTenDollarsOneCent(): array
    {
        return [
            'SAR' => ['code' => 'SAR', 'rate' => '3.75', 'decimals' => 2, 'shownAmount' => 37.54],
            'USD' => ['code' => 'USD', 'rate' => '1', 'decimals' => 2, 'shownAmount' => 10.01],
            'KWD' => ['code' => 'KWD', 'rate' => '0.307', 'decimals' => 3, 'shownAmount' => 3.07],
            'BHD' => ['code' => 'BHD', 'rate' => '0.376', 'decimals' => 3, 'shownAmount' => 3.76],
            'OMR' => ['code' => 'OMR', 'rate' => '0.3845', 'decimals' => 3, 'shownAmount' => 3.85],
        ];
    }

    /** @return array<string, array{code: string, rate: string, decimals: int, walletBalance: string, placed: bool}> */
    public static function walletBalancesAroundTheDebit(): array
    {
        $balances = [
            'one cent short' => ['10.00', false],
            'more than half a cent short' => ['10.004', false],
            'half a cent short rounds up to the debit' => ['10.005', true],
            'float residue below the debit' => ['10.00999999999999978684', true],
        ];

        $cases = [];
        foreach (self::storeCurrencies() as $currency => $store) {
            foreach ($balances as $balanceCase => [$walletBalance, $placed]) {
                $cases["{$currency}, {$balanceCase}"] = $store + ['walletBalance' => $walletBalance, 'placed' => $placed];
            }
        }

        return $cases;
    }

    #[DataProvider('storeAmountsShownForTenDollarsOneCent')]
    public function test_the_store_amount_shown_is_unchanged_and_the_debit_is_the_usd_order_amount(string $code, string $rate, int $decimals, float $shownAmount): void
    {
        $this->useStoreCurrency(code: $code, rate: $rate, decimals: $decimals);

        $this->assertSame($shownAmount, Convert::default(10.01));
        $this->assertSame(10.01, WalletOrderDebit::forOrderAmount(10.01)->amountInUsd());
    }

    #[DataProvider('storeCurrencies')]
    public function test_api_wallet_order_equal_to_the_balance_in_usd_is_placed_and_empties_the_wallet(string $code, string $rate, int $decimals): void
    {
        $this->useStoreCurrency(code: $code, rate: $rate, decimals: $decimals);
        $customer = $this->shopperWithCart(walletBalance: '10.01', price: '10.01');

        $this->placeApiWalletOrder($customer)->assertOk()->assertJsonPath('order_ids', [100001]);

        $this->assertSame(['orders' => 1, 'paid_orders' => 1, 'wallet_transactions' => 1, 'debited' => '10.010000', 'wallet_balance' => '0.000000'], $this->walletLedgerOf(customerId: $customer->id));
        $this->assertWalletIsNotNegative(customerId: $customer->id);
    }

    #[DataProvider('storeCurrencies')]
    public function test_web_wallet_checkout_equal_to_the_balance_in_usd_is_placed_and_empties_the_wallet(string $code, string $rate, int $decimals): void
    {
        $this->useStoreCurrency(code: $code, rate: $rate, decimals: $decimals);
        $customer = $this->shopperWithCart(walletBalance: '10.01', price: '10.01');

        $this->completeWebWalletCheckout($customer)->assertRedirect(route('account-oder'));

        $this->assertSame(['orders' => 1, 'paid_orders' => 1, 'wallet_transactions' => 1, 'debited' => '10.010000', 'wallet_balance' => '0.000000'], $this->walletLedgerOf(customerId: $customer->id));
        $this->assertWalletIsNotNegative(customerId: $customer->id);
    }

    #[DataProvider('walletBalancesAroundTheDebit')]
    public function test_api_wallet_order_is_placed_only_when_the_balance_covers_the_usd_debit_in_whole_cents(string $code, string $rate, int $decimals, string $walletBalance, bool $placed): void
    {
        $this->useStoreCurrency(code: $code, rate: $rate, decimals: $decimals);
        $customer = $this->shopperWithCart(walletBalance: $walletBalance, price: '10.01');
        $before = $this->walletLedgerOf(customerId: $customer->id);

        $response = $this->placeApiWalletOrder($customer);

        if ($placed) {
            $response->assertOk()->assertJsonPath('order_ids', [100001]);
            $this->assertSame(['orders' => 1, 'paid_orders' => 1, 'wallet_transactions' => 1, 'debited' => '10.010000', 'wallet_balance' => '0.000000'], $this->walletLedgerOf(customerId: $customer->id));
        } else {
            $response->assertStatus(403)->assertExactJson(['message' => 'inefficient balance in your wallet to pay for this order']);
            $this->assertSame($before, $this->walletLedgerOf(customerId: $customer->id));
            $this->assertSame(10, (int) DB::table('products')->where('id', 1)->value('current_stock'));
        }
        $this->assertWalletIsNotNegative(customerId: $customer->id);
    }

    #[DataProvider('walletBalancesAroundTheDebit')]
    public function test_web_wallet_checkout_is_placed_only_when_the_balance_covers_the_usd_debit_in_whole_cents(string $code, string $rate, int $decimals, string $walletBalance, bool $placed): void
    {
        $this->useStoreCurrency(code: $code, rate: $rate, decimals: $decimals);
        $customer = $this->shopperWithCart(walletBalance: $walletBalance, price: '10.01');
        $before = $this->walletLedgerOf(customerId: $customer->id);

        $response = $this->completeWebWalletCheckout($customer);

        if ($placed) {
            $response->assertRedirect(route('account-oder'));
            $this->assertSame(['orders' => 1, 'paid_orders' => 1, 'wallet_transactions' => 1, 'debited' => '10.010000', 'wallet_balance' => '0.000000'], $this->walletLedgerOf(customerId: $customer->id));
        } else {
            $response->assertRedirect(route('checkout-payment'));
            $this->assertSame(['Inefficient balance in your wallet to pay for this order!!'], array_column(session('toastr::messages', []), 'message'));
            $this->assertSame($before, $this->walletLedgerOf(customerId: $customer->id));
            $this->assertSame(10, (int) DB::table('products')->where('id', 1)->value('current_stock'));
        }
        $this->assertWalletIsNotNegative(customerId: $customer->id);
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
