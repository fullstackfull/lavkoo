<?php

namespace Tests\Feature\Refund;

use App\Http\Controllers\Vendor\Order\OrderController;
use App\Models\Seller;
use App\Services\CustomerWalletService;
use App\Services\OrderEditReturnAmountService;
use App\Services\Refund\RefundClaims;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\Refund\RefundFlows;
use Tests\TestCase;

/**
 * E-13: a return amount a vendor gives back reaches the customer's wallet, or it is not taken off the order at all.
 *
 * The screen read the signed-in CUSTOMER of the request to decide who to pay. A seller's request never has one, so it always answered
 * 'offline', the wallet branch never ran and the seller's earnings were never reduced — while the amount owed was cleared and the order
 * total lowered all the same. The customer's money was erased without being paid, every time a vendor returned an amount to a wallet.
 *
 * The customer is the one the ORDER names, as the admin screen has always read it. What cannot be paid is not cleared.
 */
class VendorReturnAmountToWalletTest extends TestCase
{
    private const SELLER = 1;

    protected function setUp(): void
    {
        parent::setUp();

        RefundFlows::createTables();
        RefundFlows::seedSettingsAndCustomer();
        DB::table('seller_wallets')->insert([
            'seller_id' => self::SELLER, 'total_earning' => 500, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        RefundFlows::dropTables();

        parent::tearDown();
    }

    private function returnTheAmount(string $amount, string $method = 'wallet'): void
    {
        // The handler compares the posted amount with the stored one using `!==`, which only matches while the driver returns the decimal
        // as a string, as MySQL does. The same line the admin characterization needs, for the same reason.
        DB::connection()->getPdo()->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $this->actingAs((new Seller())->forceFill(['id' => self::SELLER, 'f_name' => 'Shop']), 'seller');
        $request = Request::create('/vendor/orders/return-amount', 'POST', [
            'order_id' => RefundFlows::ORDER_ID,
            'amount' => $amount,
            'order_return_payment_method' => $method,
            'order_return_payment_note' => 'returned by the shop',
        ]);

        app(OrderController::class)->orderReturnAmountToCustomer(
            $request,
            app(CustomerWalletService::class),
            app(OrderEditReturnAmountService::class),
            app(RefundClaims::class),
        );
    }

    /** @return array<string, float|string> the money the order, the customer and the seller hold */
    private function money(): array
    {
        return [
            'wallet' => (float) DB::table('users')->where('id', RefundFlows::CUSTOMER_ID)->value('wallet_balance'),
            'owed' => (float) DB::table('orders')->where('id', RefundFlows::ORDER_ID)->value('edit_return_amount'),
            'earning' => (float) DB::table('seller_wallets')->where('seller_id', self::SELLER)->value('total_earning'),
            'credits' => (string) DB::table('wallet_transactions')->where('transaction_type', 'return_order_amount_by_admin')->count(),
        ];
    }

    public function test_the_amount_reaches_the_customers_wallet_and_leaves_the_sellers_earnings(): void
    {
        RefundFlows::seedOrderLine(orderStatus: 'confirmed', sellerId: self::SELLER, editReturnAmount: RefundFlows::RETURN_AMOUNT, sellerIs: 'seller');
        $opening = (float) DB::table('users')->where('id', RefundFlows::CUSTOMER_ID)->value('wallet_balance');

        $this->returnTheAmount(RefundFlows::storedReturnAmount());

        $this->assertSame(
            ['wallet' => $opening + RefundFlows::RETURN_AMOUNT, 'owed' => 0.0, 'earning' => 500.0 - RefundFlows::RETURN_AMOUNT, 'credits' => '1'],
            $this->money(),
            'the customer was paid once, the order owes nothing and the seller carried it',
        );
    }

    public function test_a_second_submission_of_the_same_screen_pays_nothing_more(): void
    {
        RefundFlows::seedOrderLine(orderStatus: 'confirmed', sellerId: self::SELLER, editReturnAmount: RefundFlows::RETURN_AMOUNT, sellerIs: 'seller');
        $posted = RefundFlows::storedReturnAmount();
        $this->returnTheAmount($posted);
        $afterTheFirst = $this->money();

        $this->returnTheAmount($posted);

        $this->assertSame($afterTheFirst, $this->money(), 'the claim is gone, so there is nothing left to pay');
    }

    /** A guest order has no wallet to be paid into, so the amount it owes is left owed rather than quietly cleared. */
    public function test_a_guest_order_is_refused_and_keeps_what_it_owes(): void
    {
        RefundFlows::seedOrderLine(orderStatus: 'confirmed', sellerId: self::SELLER, editReturnAmount: RefundFlows::RETURN_AMOUNT, sellerIs: 'seller');
        DB::table('orders')->where('id', RefundFlows::ORDER_ID)->update(['is_guest' => 1]);
        $before = $this->money();

        $this->returnTheAmount(RefundFlows::storedReturnAmount());

        $this->assertSame($before, $this->money(), 'nothing was paid and nothing was cleared');
        $this->assertSame(RefundFlows::RETURN_AMOUNT, (float) DB::table('orders')->where('id', RefundFlows::ORDER_ID)->value('edit_return_amount'));
    }

    /** Paid by hand, outside the wallet: the amount is still claimed once, so the screen cannot be submitted twice. */
    public function test_a_manual_return_clears_the_amount_once_and_credits_no_wallet(): void
    {
        RefundFlows::seedOrderLine(orderStatus: 'confirmed', sellerId: self::SELLER, editReturnAmount: RefundFlows::RETURN_AMOUNT, sellerIs: 'seller');
        $opening = (float) DB::table('users')->where('id', RefundFlows::CUSTOMER_ID)->value('wallet_balance');

        $posted = RefundFlows::storedReturnAmount();
        $this->returnTheAmount($posted, method: 'manually');
        $this->returnTheAmount($posted, method: 'manually');

        $this->assertSame(
            ['wallet' => $opening, 'owed' => 0.0, 'earning' => 500.0, 'credits' => '0'],
            $this->money(),
            'no wallet was touched, and the amount was cleared once',
        );
    }

    /** An order of another seller is not this seller's to return, and the scope check runs before any claim. */
    public function test_another_sellers_order_is_not_returnable_and_keeps_what_it_owes(): void
    {
        RefundFlows::seedOrderLine(orderStatus: 'confirmed', sellerId: self::SELLER + 7, editReturnAmount: RefundFlows::RETURN_AMOUNT, sellerIs: 'seller');

        $this->returnTheAmount(RefundFlows::storedReturnAmount());

        $this->assertSame(
            RefundFlows::RETURN_AMOUNT,
            (float) DB::table('orders')->where('id', RefundFlows::ORDER_ID)->value('edit_return_amount'),
            'the amount of an order this seller does not own is untouched',
        );
        $this->assertSame(0, DB::table('wallet_transactions')->where('transaction_type', 'return_order_amount_by_admin')->count());
    }
}
