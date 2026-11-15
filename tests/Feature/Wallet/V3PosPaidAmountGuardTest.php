<?php

namespace Tests\Feature\Wallet;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Address\ApiAddressTestCase;

/**
 * The seller app's point of sale taking payment from the customer's wallet, through the real route and its middleware.
 *
 * The wallet is debited before the order is written, so a paid amount that is not positive has to be refused before anything at all:
 * a negative one would turn the debit into a credit of the customer's wallet, and a zero one would write an empty ledger row.
 */
class V3PosPaidAmountGuardTest extends ApiAddressTestCase
{
    private const SELLER_TOKEN = 'seller-pos-token-0123456789abcdefghijklmnop';
    private const SELLER_ID = 5;
    private const CUSTOMER_ID = 7;
    private const PRODUCT_ID = 1;
    private const STOCK = 10;
    private const WALLET_BALANCE = 100.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        Schema::table('sellers', static function (Blueprint $table): void {
            $table->string('auth_token')->nullable();
        });
        Schema::create('wallet_transactions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('transaction_id')->nullable();
            $table->decimal('credit', 24, 3)->default(0);
            $table->decimal('debit', 24, 3)->default(0);
            $table->decimal('admin_bonus', 24, 3)->default(0);
            $table->decimal('balance', 24, 3)->default(0);
            $table->string('transaction_type')->nullable();
            $table->string('reference')->nullable();
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });
        DB::table('business_settings')->insert(['type' => 'wallet_status', 'value' => '1']);
        Cache::flush();
        $this->useTaxIncludedPrices();

        DB::table('sellers')->insert(['id' => self::SELLER_ID, 'f_name' => 'Pharmacy', 'status' => 'approved', 'auth_token' => self::SELLER_TOKEN]);
        DB::table('users')->insert(['id' => self::CUSTOMER_ID, 'f_name' => 'Sara', 'phone' => '0551234567', 'wallet_balance' => self::WALLET_BALANCE]);
        DB::table('products')->insert([
            'id' => self::PRODUCT_ID, 'name' => 'Paracetamol', 'added_by' => 'seller', 'user_id' => self::SELLER_ID, 'unit_price' => 40, 'current_stock' => self::STOCK,
        ]);
    }

    private function placeOrder(string $paymentMethod, float|int $paidAmount): TestResponse
    {
        $cartLine = [
            'id' => self::PRODUCT_ID, 'quantity' => 1, 'variant' => null, 'variation' => [], 'price' => 40, 'discounted_price' => 40,
            'discount' => 0, 'applied_tax_amount' => 0,
        ];

        return $this->withHeader('Authorization', 'Bearer ' . self::SELLER_TOKEN)->postJson('/api/v3/seller/pos/place-order', [
            'customer_id' => self::CUSTOMER_ID, 'cart' => [$cartLine], 'modified_cart' => [$cartLine], 'fulfillment' => 'instant',
            'payment_method' => $paymentMethod, 'paid_amount' => $paidAmount,
        ]);
    }

    private function walletBalance(): float
    {
        return (float) DB::table('users')->where('id', self::CUSTOMER_ID)->value('wallet_balance');
    }

    private function stock(): int
    {
        return (int) DB::table('products')->where('id', self::PRODUCT_ID)->value('current_stock');
    }

    private function assertNothingWasWritten(): void
    {
        $this->assertSame(0, DB::table('orders')->count(), 'no order');
        $this->assertSame(0, DB::table('order_details')->count(), 'no order line');
        $this->assertSame(self::STOCK, $this->stock(), 'no stock taken');
        $this->assertSame(0, DB::table('wallet_transactions')->count(), 'no ledger row');
        $this->assertSame(self::WALLET_BALANCE, $this->walletBalance(), 'the wallet is untouched');
    }

    private function assertRefusedAsAWrongPaidAmount(TestResponse $response): void
    {
        $response->assertStatus(403);
        $this->assertSame('paid_amount', $response->json('errors.0.code'));
        $this->assertNotEmpty($response->json('errors.0.message'));
    }

    public function test_a_wallet_payment_with_a_negative_paid_amount_is_refused_before_anything_is_written(): void
    {
        $this->assertNothingWasWritten();

        $response = $this->placeOrder(paymentMethod: 'wallet', paidAmount: -50);

        $this->assertRefusedAsAWrongPaidAmount($response);
        $this->assertNothingWasWritten();
    }

    public function test_a_wallet_payment_of_zero_is_refused_before_anything_is_written(): void
    {
        $this->assertNothingWasWritten();

        $response = $this->placeOrder(paymentMethod: 'wallet', paidAmount: 0);

        $this->assertRefusedAsAWrongPaidAmount($response);
        $this->assertNothingWasWritten();
    }

    public function test_a_positive_wallet_payment_places_the_order_and_debits_the_converted_amount(): void
    {
        $this->assertNothingWasWritten();

        $response = $this->placeOrder(paymentMethod: 'wallet', paidAmount: 40);

        $response->assertOk();
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(self::STOCK - 1, $this->stock());
        $this->assertSame(self::WALLET_BALANCE - currencyConverter(amount: 40), $this->walletBalance());
        $entry = DB::table('wallet_transactions')->sole();
        $this->assertSame(['order_place', 40.0, 0.0], [$entry->transaction_type, (float) $entry->debit, (float) $entry->credit]);
    }

    public function test_a_cash_payment_with_a_negative_paid_amount_is_currently_placed_as_before(): void
    {
        $this->assertNothingWasWritten();

        $response = $this->placeOrder(paymentMethod: 'cash', paidAmount: -50);

        $response->assertOk();
        $order = DB::table('orders')->sole();
        $this->assertSame(['cash', -50.0], [$order->payment_method, (float) $order->paid_amount]);
        $this->assertSame(self::STOCK - 1, $this->stock());
        $this->assertSame(0, DB::table('wallet_transactions')->count());
        $this->assertSame(self::WALLET_BALANCE, $this->walletBalance());
    }
}
