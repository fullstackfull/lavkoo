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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\KeepsTranslateOffLanguageFiles;

/**
 * A point-of-sale sale paid from the customer's wallet is complete or leaves nothing behind: the debit, the order, its lines and the
 * stock are written in one transaction, and a debit the wallet refuses rolls all of them back.
 */
class PosWalletSaleTest extends ApiAddressTestCase
{
    use KeepsTranslateOffLanguageFiles;

    private const SELLER_ID = 5;
    private const STOCK = 10;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->uuid('transaction_id');
            $table->decimal('credit', 24, 3)->default(0);
            $table->decimal('debit', 24, 3)->default(0);
            $table->decimal('admin_bonus', 24, 3)->default(0);
            $table->decimal('balance', 24, 3)->default(0);
            $table->string('transaction_type', 191)->nullable();
            $table->string('reference', 191)->nullable();
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });
        DB::table('business_settings')->insert(['type' => 'wallet_status', 'value' => '1']);
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Paracetamol', 'added_by' => 'seller', 'user_id' => self::SELLER_ID, 'unit_price' => 30, 'current_stock' => self::STOCK],
            ['id' => 2, 'name' => 'Vitamin C', 'added_by' => 'seller', 'user_id' => self::SELLER_ID, 'unit_price' => 20, 'current_stock' => self::STOCK],
        ]);
        $this->customer = $this->makeCustomer(['wallet_balance' => 100]);
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
    private function sellerSale(array $lines, float $paidAmount): JsonResponse
    {
        $input = [
            'customer_id' => $this->customer->id, 'cart' => [], 'modified_cart' => $lines, 'fulfillment' => 'instant',
            'payment_method' => 'wallet', 'paid_amount' => $paidAmount,
        ];
        $request = Request::create('/api/v3/seller/pos/place-order', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($input));
        $request['seller'] = (new Seller())->forceFill(['id' => self::SELLER_ID]);

        return app(SellerApiPOSController::class)->place_order($request);
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
