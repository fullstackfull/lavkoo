<?php

namespace Tests\Feature\Wallet;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsWalletCheckoutWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A wallet order of nothing is refused, and writes nothing.
 *
 * Found by the MariaDB race in WalletBalanceCurrencyConcurrencyTest, where two requests spend the same balance on the same cart. The winner
 * places the order and clears the cart; the loser then counts an EMPTY cart, so its amount is 0.00, and zero is covered by any balance --
 * including the balance the winner has just emptied. It therefore passed the wallet check and went on to place an order of nothing: no order
 * rows, because there was nothing to order, and a committed wallet ledger row debiting 0.00 with the reference `order payment`.
 *
 * No money is lost by that row and none is owed. What it costs is the ledger's meaning: `order_place` / `order payment` is the record of a
 * customer paying for an order, and a row saying so with no order behind it is what a reconciliation reads. The race made it rare rather
 * than impossible, and two tabs, a retried request or a slow network reach the same state with no race at all -- which is what these tests
 * use, so the proof does not depend on timing.
 *
 * What decides it is the CART and not the amount. An order worth 0.00 is a real order when a cart holds a free product, and the platform
 * places it as paid with a zero-debit row; that is pinned, as it is, by
 * {@see WalletDebitBoundaryCharacterizationTest::test_api_wallet_order_of_zero_is_currently_placed_as_paid_with_a_zero_debit_row}, which
 * is why the guard asks whether there is anything to order rather than whether the total is zero. The last test here holds that line.
 */
class EmptyCartWalletOrderTest extends TestCase
{
    use BuildsWalletCheckoutWorld;
    use RestoresLanguageFiles;

    private const BALANCE = '10.01';

    private const PRICE = '10.01';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        $this->buildWalletCheckoutWorld();
        $this->useStoreCurrency(code: 'SAR', rate: '3.75', decimals: 2);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function walletRowsOf(int $customerId): int
    {
        return DB::table('wallet_transactions')->where('user_id', $customerId)->count();
    }

    public function test_a_wallet_order_of_an_emptied_cart_is_refused_and_writes_no_ledger_row(): void
    {
        $customer = $this->shopperWithCart(walletBalance: self::BALANCE, price: self::PRICE);
        DB::table('carts')->where('customer_id', $customer->id)->delete();

        $response = $this->placeApiWalletOrder($customer);

        $this->assertSame(403, $response->status(), 'an order of nothing is refused');
        $this->assertSame(0, $this->walletRowsOf($customer->id), 'and no ledger row says a payment happened');
        $this->assertSame(0, DB::table('orders')->where('customer_id', $customer->id)->count());
    }

    /** And the balance is untouched, to the cent: the refusal happens before anything is locked or written. */
    public function test_the_balance_is_exactly_what_it_was(): void
    {
        $customer = $this->shopperWithCart(walletBalance: self::BALANCE, price: self::PRICE);
        DB::table('carts')->where('customer_id', $customer->id)->delete();

        $this->placeApiWalletOrder($customer);

        $this->assertSame(self::BALANCE, rtrim(rtrim((string) DB::table('users')->where('id', $customer->id)->value('wallet_balance'), '0'), '.'));
    }

    /** A customer with nothing in their wallet and nothing in their cart is refused the same way, and not for want of balance. */
    public function test_an_empty_cart_is_refused_even_when_the_wallet_is_empty_too(): void
    {
        $customer = $this->shopperWithCart(walletBalance: '0', price: self::PRICE);
        DB::table('carts')->where('customer_id', $customer->id)->delete();

        $response = $this->placeApiWalletOrder($customer);

        $this->assertSame(403, $response->status());
        $this->assertSame(0, $this->walletRowsOf($customer->id));
    }

    /** The control, so the refusal above is about the empty cart and not about the endpoint: the same customer with their cart intact pays. */
    public function test_the_same_order_with_its_cart_still_in_place_is_placed_and_debited_once(): void
    {
        $customer = $this->shopperWithCart(walletBalance: self::BALANCE, price: self::PRICE);

        $response = $this->placeApiWalletOrder($customer);

        $this->assertSame(200, $response->status(), $response->getContent());
        $this->assertSame(1, $this->walletRowsOf($customer->id));
        $this->assertSame(1, DB::table('orders')->where('customer_id', $customer->id)->count());
    }

    /**
     * And the line the guard must not cross: a cart holding a FREE product is something to order, so the order is placed with its
     * zero-debit row exactly as the platform has always placed it. Asking whether the total is zero instead of whether the cart is empty
     * would refuse this, which is a different change from the one this fix makes.
     */
    public function test_a_cart_holding_a_free_product_is_still_ordered(): void
    {
        $customer = $this->shopperWithCart(walletBalance: self::BALANCE, price: '0');

        $response = $this->placeApiWalletOrder($customer);

        $this->assertSame(200, $response->status(), $response->getContent());
        $this->assertSame(1, DB::table('orders')->where('customer_id', $customer->id)->count());
        $this->assertSame(1, $this->walletRowsOf($customer->id), 'the zero-debit row of a free order is what the platform writes today');
    }
}
