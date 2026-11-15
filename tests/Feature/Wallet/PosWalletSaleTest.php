<?php

namespace Tests\Feature\Wallet;

use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Enums\SessionKey;
use App\Http\Controllers\Admin\POS\POSOrderController as AdminPOSOrderController;
use App\Http\Controllers\RestAPI\v3\seller\POSController as SellerApiPOSController;
use App\Http\Controllers\Vendor\POS\POSOrderController as VendorPOSOrderController;
use App\Models\Seller;
use App\Models\User;
use App\Repositories\CustomerRepository;
use App\Services\Address\OrderAddressService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsPosSaleWorld;
use Tests\Support\KeepsTranslateOffLanguageFiles;

/**
 * A point-of-sale sale paid from the customer's wallet is complete or leaves nothing behind: the debit, the order, its lines and the
 * stock are written in one transaction, and a debit the wallet refuses rolls all of them back.
 */
class PosWalletSaleTest extends ApiAddressTestCase
{
    use BuildsPosSaleWorld;
    use KeepsTranslateOffLanguageFiles;

    private const SELLER_ID = 5;
    private const STOCK = 10;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->buildPosSaleWorld(sellerId: self::SELLER_ID);
        $this->keepTranslateOffLanguageFiles();
    }

    protected function tearDown(): void
    {
        $this->assertLanguageFilesUntouched();

        parent::tearDown();
    }

    private function walletBalance(): float
    {
        return (float) DB::table('users')->where('id', $this->customer->id)->value('wallet_balance');
    }

    /** @return array<int, int> stock by product id */
    private function stock(): array
    {
        return DB::table('products')->orderBy('id')->pluck('current_stock', 'id')->map(fn ($stock): int => (int) $stock)->all();
    }

    private function assertNothingWasPersisted(): void
    {
        $this->assertSame(100.0, $this->walletBalance(), 'the wallet is unchanged');
        $this->assertSame(0, DB::table('wallet_transactions')->count(), 'no ledger row');
        $this->assertSame(0, DB::table('orders')->count(), 'no order');
        $this->assertSame(0, DB::table('order_details')->count(), 'no order line');
        $this->assertSame([1 => self::STOCK, 2 => self::STOCK], $this->stock(), 'no stock was taken');
    }

    /**
     * The balance check of the sale reads the wallet with enough in it; the wallet is drained by another payment right after, before
     * the sale debits it. Only the check made under the customer's lock can see the drained wallet.
     */
    private function drainTheWalletRightAfterTheBalanceCheck(): void
    {
        $repository = Mockery::mock(CustomerRepository::class)->makePartial();
        $repository->shouldReceive('getFirstWhere')->andReturnUsing(function (array $params): ?User {
            $customerAsChecked = User::where($params)->first();
            DB::table('users')->where('id', $this->customer->id)->update(['wallet_balance' => 5]);

            return $customerAsChecked;
        });
        $this->app->instance(CustomerRepositoryInterface::class, $repository);
    }

    /** @return array<string, mixed> one line of the seller app's cart */
    private function sellerLine(int $productId, int $quantity, float $price): array
    {
        return [
            'id' => $productId, 'quantity' => $quantity, 'price' => $price, 'discounted_price' => $price, 'discount' => 0, 'applied_tax_amount' => 0,
            'variant' => null, 'variation' => [], 'coupon_discount' => 0, 'extra_discount' => 0,
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    private function sellerSale(array $lines, float $paidAmount, string $paymentMethod = 'wallet', ?string $idempotencyKey = null, int $sellerId = self::SELLER_ID): JsonResponse
    {
        $input = [
            'customer_id' => $this->customer->id, 'cart' => [], 'modified_cart' => $lines, 'fulfillment' => 'instant',
            'payment_method' => $paymentMethod, 'paid_amount' => $paidAmount,
        ];
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($idempotencyKey !== null) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }
        $request = Request::create('/api/v3/seller/pos/place-order', 'POST', [], [], [], $server, (string) json_encode($input));
        $request['seller'] = (new Seller())->forceFill(['id' => $sellerId]);

        return app(SellerApiPOSController::class)->place_order($request);
    }

    public function test_a_seller_app_wallet_sale_is_charged_the_server_total_whatever_the_client_says_it_paid(): void
    {
        $response = $this->sellerSale([$this->sellerLine(productId: 1, quantity: 2, price: 30.0)], paidAmount: 1.0);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(40.0, $this->walletBalance());
        $this->assertSame(60.0, (float) DB::table('wallet_transactions')->value('debit'));
        $this->assertSame([60.0, 60.0], [(float) DB::table('orders')->value('order_amount'), (float) DB::table('orders')->value('paid_amount')]);
    }

    public function test_a_seller_app_wallet_sale_the_wallet_cannot_cover_at_the_server_total_is_refused_even_when_the_client_paid_less(): void
    {
        $response = $this->sellerSale([$this->sellerLine(productId: 1, quantity: 4, price: 30.0)], paidAmount: 10.0);

        $this->assertTrue($response->getData(true)['need_balance'] ?? false, 'the operator is told the wallet does not cover the sale');
        $this->assertNothingWasPersisted();
    }

    public function test_a_seller_app_cash_sale_paid_below_the_server_total_is_refused_with_nothing_written(): void
    {
        $response = $this->sellerSale([$this->sellerLine(productId: 1, quantity: 2, price: 30.0)], paidAmount: 50.0, paymentMethod: 'cash');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(translate('paid_amount_is_less_than_total_amount'), $response->getData(true)['message']);
        $this->assertNothingWasPersisted();
    }

    public function test_a_seller_app_cash_sale_paid_at_the_server_total_is_written_once_without_a_wallet_debit(): void
    {
        $response = $this->sellerSale([$this->sellerLine(productId: 1, quantity: 2, price: 30.0)], paidAmount: 60.0, paymentMethod: 'cash');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(100.0, $this->walletBalance());
        $this->assertSame(0, DB::table('wallet_transactions')->count());
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame([60.0, 60.0], [(float) DB::table('orders')->value('order_amount'), (float) DB::table('orders')->value('paid_amount')]);
    }

    /** A 37.54 SAR sale is 10.010667 USD at 3.75 SAR to the dollar; the wallet is debited that once, not converted a second time to 2.67 USD. */
    public function test_a_seller_app_wallet_sale_in_a_store_currency_debits_the_usd_value_of_the_server_total_once(): void
    {
        DB::table('business_settings')->where('type', 'currency_model')->update(['value' => json_encode('multi_currency')]);
        DB::table('currencies')->where('id', 1)->update(['exchange_rate' => 3.75]);
        DB::table('currencies')->insert(['id' => 2, 'name' => 'US Dollar', 'code' => 'USD', 'symbol' => '$', 'exchange_rate' => 1, 'status' => 1]);
        Cache::flush();
        $this->useTaxIncludedPrices();

        $response = $this->sellerSale([$this->sellerLine(productId: 1, quantity: 1, price: 37.54)], paidAmount: 0.01);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([10.011, 89.99], [round((float) DB::table('wallet_transactions')->value('debit'), 3), round($this->walletBalance(), 2)]);
    }

    /** The order is written after the debit; a failure there takes the debit, the order, its lines and the stock back with it. */
    public function test_a_seller_app_wallet_sale_that_fails_after_the_debit_rolls_back_the_debit_and_the_order(): void
    {
        $orderAddresses = Mockery::mock(OrderAddressService::class);
        $orderAddresses->shouldReceive('snapshot')->andThrow(new \RuntimeException('the order could not be written'));
        $this->app->instance(OrderAddressService::class, $orderAddresses);

        try {
            $this->sellerSale([$this->sellerLine(productId: 1, quantity: 2, price: 30.0)], paidAmount: 60.0);
            $this->fail('the sale must not complete');
        } catch (\RuntimeException $exception) {
            $this->assertSame('the order could not be written', $exception->getMessage());
        }

        $this->assertNothingWasPersisted();
    }

    public function test_a_repeated_seller_app_wallet_sale_with_the_same_key_returns_the_first_order_and_debits_once(): void
    {
        $lines = [$this->sellerLine(productId: 1, quantity: 2, price: 30.0)];
        $orderId = $this->sellerSale($lines, paidAmount: 60.0, idempotencyKey: 'sale-1')->getData(true)['order_id'];

        $second = $this->sellerSale($lines, paidAmount: 60.0, idempotencyKey: 'sale-1');

        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame($orderId, $second->getData(true)['order_id']);
        $this->assertSame(40.0, $this->walletBalance(), 'the second submit would not have covered 60 from 40: it is answered, not charged');
        $this->assertSame([1, 1], [DB::table('orders')->count(), DB::table('wallet_transactions')->count()]);
        $this->assertSame([1 => 8, 2 => self::STOCK], $this->stock());
    }

    public function test_the_same_key_from_another_seller_is_a_sale_of_its_own(): void
    {
        $this->sellerSale([$this->sellerLine(productId: 1, quantity: 2, price: 30.0)], paidAmount: 60.0, idempotencyKey: 'sale-1');

        $other = $this->sellerSale([$this->sellerLine(productId: 1, quantity: 1, price: 30.0)], paidAmount: 30.0, idempotencyKey: 'sale-1', sellerId: 6);

        $this->assertSame(200, $other->getStatusCode());
        $this->assertSame(2, DB::table('orders')->count());
        $this->assertSame(10.0, $this->walletBalance());
    }

    public function test_a_failed_attempt_does_not_use_up_its_key(): void
    {
        $lines = [$this->sellerLine(productId: 1, quantity: 2, price: 30.0)];
        $orderAddresses = Mockery::mock(OrderAddressService::class);
        $orderAddresses->shouldReceive('snapshot')->andThrow(new \RuntimeException('the order could not be written'));
        $this->app->instance(OrderAddressService::class, $orderAddresses);
        try {
            $this->sellerSale($lines, paidAmount: 60.0, idempotencyKey: 'sale-2');
            $this->fail('the first attempt must not complete');
        } catch (\RuntimeException) {
        }
        $this->app->forgetInstance(OrderAddressService::class);

        $retry = $this->sellerSale($lines, paidAmount: 60.0, idempotencyKey: 'sale-2');

        $this->assertSame(200, $retry->getStatusCode());
        $this->assertSame([1, 40.0], [DB::table('orders')->count(), $this->walletBalance()]);
    }

    public function test_an_idempotency_key_longer_than_the_column_is_refused_with_nothing_written(): void
    {
        $response = $this->sellerSale([$this->sellerLine(productId: 1, quantity: 2, price: 30.0)], paidAmount: 60.0, idempotencyKey: str_repeat('k', 192));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNothingWasPersisted();
    }

    public function test_a_seller_app_wallet_sale_debits_the_wallet_and_writes_the_order_once(): void
    {
        $response = $this->sellerSale([$this->sellerLine(1, 2, 30), $this->sellerLine(2, 1, 20)], 80);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(20.0, $this->walletBalance());
        $this->assertSame(1, DB::table('wallet_transactions')->count());
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(2, DB::table('order_details')->count());
        $this->assertSame([1 => 8, 2 => 9], $this->stock());
    }

    public function test_a_seller_app_wallet_sale_with_a_line_short_of_stock_leaves_the_wallet_untouched(): void
    {
        $response = $this->sellerSale([$this->sellerLine(1, 2, 30), $this->sellerLine(2, self::STOCK + 1, 20)], 80);

        $this->assertTrue($response->getData(true)['product_not_available'] ?? false, 'the sale is refused for the short line');
        $this->assertNothingWasPersisted();
    }

    public function test_a_seller_app_wallet_sale_drained_after_the_balance_check_persists_nothing(): void
    {
        $this->drainTheWalletRightAfterTheBalanceCheck();

        $response = $this->sellerSale([$this->sellerLine(1, 2, 30), $this->sellerLine(2, 1, 20)], 80);

        $this->assertTrue($response->getData(true)['need_balance'] ?? false, 'the operator is told the balance does not cover the sale');
        $this->assertSame(5.0, $this->walletBalance(), 'the drained wallet is not debited again');
        DB::table('users')->where('id', $this->customer->id)->update(['wallet_balance' => 100]);
        $this->assertNothingWasPersisted();
    }

    /** @return array<string, array{0: class-string}> */
    public static function panels(): array
    {
        return [
            'admin panel' => [AdminPOSOrderController::class],
            'vendor panel' => [VendorPOSOrderController::class],
        ];
    }

    /** Puts two lines in the panel's session cart for the customer, the way the POS screen does. */
    private function panelCart(): void
    {
        $cartId = 'saved-customer-' . $this->customer->id;
        $line = fn (int $productId, int $quantity, float $price): array => [
            'id' => $productId, 'customerId' => $this->customer->id, 'customerOnHold' => false, 'quantity' => $quantity, 'price' => $price,
            'name' => 'Product ' . $productId, 'productType' => 'physical', 'image' => null, 'discount' => 0, 'variant' => '', 'variations' => [],
        ];
        session([
            SessionKey::CURRENT_USER => $cartId,
            SessionKey::CART_NAME => [$cartId],
            $cartId => [$line(1, 2, 30), $line(2, 1, 20)],
        ]);
    }

    /** @param class-string $controller */
    private function panelSale(string $controller): JsonResponse
    {
        $this->panelCart();
        $request = Request::create('/pos/place-order', 'POST', ['amount' => 80, 'type' => 'wallet', 'fulfillment' => 'instant']);

        return app($controller)->placeOrder($request);
    }

    #[DataProvider('panels')]
    public function test_a_panel_wallet_sale_debits_the_wallet_and_writes_the_order_once(string $controller): void
    {
        $this->panelSale($controller);

        $this->assertSame(20.0, $this->walletBalance());
        $this->assertSame(1, DB::table('wallet_transactions')->count());
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(2, DB::table('order_details')->count());
        $this->assertSame([1 => 8, 2 => 9], $this->stock());
    }

    #[DataProvider('panels')]
    public function test_a_panel_wallet_sale_drained_after_the_balance_check_persists_nothing(string $controller): void
    {
        $this->drainTheWalletRightAfterTheBalanceCheck();

        $this->panelSale($controller);

        $this->assertSame(5.0, $this->walletBalance(), 'the drained wallet is not debited again');
        $this->assertNotNull(session(session(SessionKey::CURRENT_USER)), 'the cart is kept so the operator can take another payment');
        DB::table('users')->where('id', $this->customer->id)->update(['wallet_balance' => 100]);
        $this->assertNothingWasPersisted();
    }
}
