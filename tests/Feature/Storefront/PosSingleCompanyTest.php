<?php

namespace Tests\Feature\Storefront;

use App\Enums\SessionKey;
use App\Exceptions\OrderMixesSellingCompaniesException;
use App\Http\Controllers\Admin\POS\POSOrderController as AdminPOSOrderController;
use App\Http\Controllers\RestAPI\v3\seller\POSController as SellerPOSController;
use App\Http\Controllers\Vendor\POS\POSOrderController as VendorPOSOrderController;
use App\Models\Seller;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;

/**
 * A point-of-sale checkout is a checkout: one order names one country and one selling company there too (D1-02). The three tills bypass
 * OrderManager::generateOrder — the admin screen and the vendor screen build an order from a session cart, the seller app's till builds one
 * from a request payload — so each asks the same rule, off the products it has already loaded to price the sale, before it takes any stock
 * or writes any row.
 *
 * Saudi Arabia never meets it: one country in, one order out, and the same number of queries as before.
 */
class PosSingleCompanyTest extends ApiAddressTestCase
{
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;

    private const SELLER_ID = 5;

    private const WALLET_BALANCE = 1000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCartWorld();
        $this->seedTwoCountries();
    }

    // ---- the three tills ---------------------------------------------------------------------------------

    /** A line of the seller app's till payload: the shape its cart screen sends for one unit of a product, or for as many as asked for. */
    private function payloadLine(int $productId, float $price, int $quantity = 1): array
    {
        return [
            'id' => $productId, 'quantity' => $quantity, 'variant' => null, 'price' => $price, 'discounted_price' => $price,
            'discount' => 0, 'applied_tax_amount' => 0, 'coupon_discount' => 0, 'extra_discount' => 0, 'variation' => [],
        ];
    }

    /** A line of a panel till's session cart, as CartService::addCartDataOnSession writes it. */
    private function sessionLine(int $productId, string $name, float $price, int $customerId = 0): array
    {
        return [
            'id' => $productId, 'customerId' => $customerId, 'customerOnHold' => false, 'quantity' => 1, 'price' => $price, 'name' => $name,
            'productType' => 'physical', 'image' => 'x.png', 'discount' => 0, 'variant' => '', 'variations' => [],
        ];
    }

    /**
     * Which customer the till is ringing up: a walk-in by default, or the saved customer whose wallet pays, which is the only session key
     * CartService::getUserId() reads an id out of.
     *
     * @param list<array<string, mixed>> $lines
     */
    private function tillHolds(array $lines, string $tillCustomer = 'walking-customer-1'): void
    {
        session([SessionKey::CURRENT_USER => $tillCustomer]);
        session([$tillCustomer => $lines]);
    }

    private function sellerRequest(array $input): Request
    {
        $request = Request::create('/api/v3/seller/pos/place-order', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($input));
        $request['seller'] = (new Seller())->forceFill(['id' => self::SELLER_ID]);

        return $request;
    }

    /** @param list<array<string, mixed>> $lines */
    private function sellAtTheAdminTill(array $lines, float $amount, string $paymentType = 'cash', string $tillCustomer = 'walking-customer-1')
    {
        $this->tillHolds($lines, $tillCustomer);

        return app(AdminPOSOrderController::class)->placeOrder(Request::create('/admin/pos/order-place', 'POST', [
            'amount' => $amount, 'fulfillment' => 'instant', 'type' => $paymentType, 'paid_amount' => $amount,
        ]));
    }

    /** @param list<array<string, mixed>> $lines */
    private function sellAtTheVendorTill(array $lines, float $amount, string $paymentType = 'cash', string $tillCustomer = 'walking-customer-1')
    {
        $this->tillHolds($lines, $tillCustomer);
        $this->actingAs((new Seller())->forceFill(['id' => self::SELLER_ID, 'f_name' => 'Vendor']), 'seller');

        return app(VendorPOSOrderController::class)->placeOrder(Request::create('/vendor/pos/order-place', 'POST', [
            'amount' => $amount, 'fulfillment' => 'instant', 'type' => $paymentType, 'paid_amount' => $amount,
        ]));
    }

    /** @param list<array<string, mixed>> $lines */
    private function sellAtTheSellerAppTill(array $lines, float $amount, string $paymentMethod = 'cash', int $customerId = 0)
    {
        return app(SellerPOSController::class)->place_order($this->sellerRequest([
            'customer_id' => $customerId, 'cart' => [], 'modified_cart' => $lines, 'fulfillment' => 'instant',
            'payment_method' => $paymentMethod, 'paid_amount' => $amount,
        ]));
    }

    // ---- what landed -------------------------------------------------------------------------------------

    /** @return array<int, list<int>> order id => the product ids its lines name */
    private function ordersWithTheirLines(): array
    {
        $lines = [];
        foreach (DB::table('order_details')->orderBy('id')->get() as $line) {
            $lines[(int) $line->order_id][] = (int) $line->product_id;
        }

        return $lines;
    }

    /** @return list<string> the kinds of toast the till asked the screen to show */
    private function toastTypes(): array
    {
        return array_column(session('laravel-toaster-magic::messages', []), 'type');
    }

    /** @return array<int, int> product id => the stock it still has */
    private function stock(): array
    {
        return DB::table('products')->orderBy('id')->pluck('current_stock', 'id')->map(fn ($units) => (int) $units)->all();
    }

    private function nothingWasSold(): void
    {
        $this->assertSame([], $this->ordersWithTheirLines(), 'no order and no order line was written');
        $this->assertSame(0, DB::table('orders')->count(), 'no order row');
        $this->assertSame([1 => 10, 2 => 4], $this->stock(), 'and no stock was taken');
    }

    private function addTheSellingCompanyColumn(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->unsignedBigInteger(StorefrontCatalog::ENTITY_COLUMN)->nullable());
        StorefrontCatalog::flush();
    }

    // ---- a sale paid from the customer's wallet ----------------------------------------------------------

    /** A saved customer with money in their wallet, and the ledger the debit is written to. @return int their id */
    private function customerWithAFullWallet(): int
    {
        if (!Schema::hasTable('wallet_transactions')) {
            Schema::create('wallet_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('transaction_id')->nullable();
                $table->string('reference')->nullable();
                $table->string('transaction_type')->nullable();
                $table->string('payment_method')->nullable();
                $table->decimal('credit', 24, 2)->default(0);
                $table->decimal('debit', 24, 2)->default(0);
                $table->decimal('balance', 24, 2)->default(0);
                $table->decimal('admin_bonus', 24, 2)->default(0);
                $table->timestamps();
            });
        }
        DB::table('business_settings')->insert(['type' => 'wallet_status', 'value' => '1']);

        return (int) $this->makeCustomer(['wallet_balance' => self::WALLET_BALANCE])->id;
    }

    /** @return array{balance: string, debits: int} what the customer's wallet says after the sale */
    private function wallet(int $customerId): array
    {
        return [
            'balance' => number_format((float) DB::table('users')->where('id', $customerId)->value('wallet_balance'), 2, '.', ''),
            'debits' => DB::table('wallet_transactions')->where('user_id', $customerId)->count(),
        ];
    }

    private function walletIsUntouched(int $customerId): void
    {
        $this->assertSame(
            ['balance' => '1000.00', 'debits' => 0],
            $this->wallet($customerId),
            'a refused sale takes no money and writes no wallet transaction',
        );
    }

    // ---- a till holding two countries sells neither ------------------------------------------------------

    public function test_the_admin_till_refuses_a_sale_of_two_countries_before_it_takes_anything(): void
    {
        $response = $this->sellAtTheAdminTill([$this->sessionLine(1, 'Paracetamol SA', 100), $this->sessionLine(2, 'Paracetamol AE', 250)], 350);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['error'], $this->toastTypes());
        $this->nothingWasSold();
        $this->assertCount(2, session('walking-customer-1'), 'the till still holds what the operator scanned');
    }

    public function test_the_vendor_till_refuses_a_sale_of_two_countries_before_it_takes_anything(): void
    {
        $response = $this->sellAtTheVendorTill([$this->sessionLine(1, 'Paracetamol SA', 100), $this->sessionLine(2, 'Paracetamol AE', 250)], 350);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['error'], $this->toastTypes());
        $this->nothingWasSold();
        $this->assertCount(2, session('walking-customer-1'), 'the till still holds what the operator scanned');
    }

    public function test_the_seller_app_till_refuses_a_sale_of_two_countries_before_it_takes_anything(): void
    {
        $lines = [$this->payloadLine(1, 100), $this->payloadLine(2, 250)];

        try {
            $this->sellAtTheSellerAppTill($lines, 350);
            $this->fail('the sale was not refused');
        } catch (OrderMixesSellingCompaniesException $refusal) {
            $this->assertSame(['storefront_country=SA', 'storefront_country=AE'], $refusal->sellingScopes);
        }

        $this->nothingWasSold();
    }

    public function test_the_refusal_the_seller_app_reads_is_the_json_one_the_customer_api_already_answers(): void
    {
        $refusal = new OrderMixesSellingCompaniesException(sellingScopes: ['storefront_country=SA', 'storefront_country=AE']);

        $response = $refusal->render(Request::create('/api/v3/seller/pos/place-order', 'POST'));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            ['code' => 'order_mixes_selling_companies', 'message' => translate('Product_is_unavailable')],
            json_decode((string) $response->getContent(), true),
        );
    }

    // ---- the company half, the day products carry one ----------------------------------------------------

    public function test_two_saudi_products_of_two_selling_companies_are_refused_at_every_till_once_products_carry_one(): void
    {
        DB::table('products')->where('id', 2)->update(['storefront_country' => 'SA']);
        $this->addTheSellingCompanyColumn();
        DB::table('products')->where('id', 1)->update([StorefrontCatalog::ENTITY_COLUMN => 1]);
        DB::table('products')->where('id', 2)->update([StorefrontCatalog::ENTITY_COLUMN => 2]);

        $this->sellAtTheAdminTill([$this->sessionLine(1, 'One company', 100), $this->sessionLine(2, 'Another company', 250)], 350);
        $this->assertSame(['error'], $this->toastTypes());

        $this->expectException(OrderMixesSellingCompaniesException::class);
        $this->sellAtTheSellerAppTill([$this->payloadLine(1, 100), $this->payloadLine(2, 250)], 350);
    }

    public function test_before_the_country_migration_has_run_no_till_refuses_anything(): void
    {
        $this->removeStorefrontCountryColumn();
        StorefrontCatalog::flush();

        $response = $this->sellAtTheSellerAppTill([$this->payloadLine(1, 100), $this->payloadLine(2, 250)], 350);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([100001 => [1, 2]], $this->ordersWithTheirLines(), 'the database the platform has today is not judged');
    }

    // ---- Saudi Arabia is unchanged -----------------------------------------------------------------------

    public function test_one_saudi_line_is_still_sold_at_every_till_for_the_same_money(): void
    {
        $this->sellAtTheAdminTill([$this->sessionLine(1, 'Paracetamol SA', 100)], 100);
        $this->sellAtTheVendorTill([$this->sessionLine(1, 'Paracetamol SA', 100)], 100);
        $this->sellAtTheSellerAppTill([$this->payloadLine(1, 100)], 100);

        $this->assertSame(['success', 'success'], $this->toastTypes(), 'both screens reported the sale and neither complained');
        $this->assertSame([100001 => [1], 100002 => [1], 100003 => [1]], $this->ordersWithTheirLines());
        $this->assertSame(
            ['100.00', '100.00', '100.00'],
            DB::table('orders')->orderBy('id')->get()->map(fn ($order) => number_format((float) $order->order_amount, 2, '.', ''))->all(),
        );
        $this->assertSame([1 => 7, 2 => 4], $this->stock(), 'three units of the Saudi product left the shelf and nothing else moved');
    }

    /** The queries one sale makes, off the one listener the test registered. */
    private function countingQueries(int &$queries, callable $sale): int
    {
        $before = $queries;
        $sale();

        return $queries - $before;
    }

    public function test_one_saudi_line_costs_each_till_the_same_queries_it_cost_before(): void
    {
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries++;
        });

        $admin = $this->countingQueries($queries, fn () => $this->sellAtTheAdminTill([$this->sessionLine(1, 'Paracetamol SA', 100)], 100));
        $vendor = $this->countingQueries($queries, fn () => $this->sellAtTheVendorTill([$this->sessionLine(1, 'Paracetamol SA', 100)], 100));
        $sellerApp = $this->countingQueries($queries, fn () => $this->sellAtTheSellerAppTill([$this->payloadLine(1, 100)], 100));

        $this->assertSame([15, 15, 19], [$admin, $vendor, $sellerApp], 'every till reads exactly what it read before the guard');
    }

    // ---- the state the company backfill must pass through ------------------------------------------------

    /**
     * One country, one product carrying a company and one the backfill has not reached: the only state a migration that fills a column row
     * by row can ever be observed in. The two halves of the selling scope are compared apart, so the row that names no company neither
     * agrees nor disagrees about one and the sale is lawful. Reading the scope as one joined string refused it at every till.
     */
    public function test_a_half_backfilled_company_column_sells_at_every_till(): void
    {
        DB::table('products')->where('id', 2)->update(['storefront_country' => 'SA']);
        $this->addTheSellingCompanyColumn();
        DB::table('products')->where('id', 1)->update([StorefrontCatalog::ENTITY_COLUMN => 1]);

        $this->sellAtTheAdminTill([$this->sessionLine(1, 'Backfilled', 100), $this->sessionLine(2, 'Not yet reached', 250)], 350);
        $this->sellAtTheVendorTill([$this->sessionLine(1, 'Backfilled', 100), $this->sessionLine(2, 'Not yet reached', 250)], 350);
        $this->sellAtTheSellerAppTill([$this->payloadLine(1, 100), $this->payloadLine(2, 250)], 350);

        $this->assertSame(['success', 'success'], $this->toastTypes(), 'neither screen refused the sale');
        $this->assertSame([100001 => [1, 2], 100002 => [1, 2], 100003 => [1, 2]], $this->ordersWithTheirLines());
    }

    /** The same half-backfilled state at the customer's own checkout, which is the one a shopper meets while the migration runs. */
    public function test_a_half_backfilled_company_column_checks_out_at_the_customer_api(): void
    {
        $customer = $this->makeCustomer();
        DB::table('products')->where('id', 2)->update(['storefront_country' => 'SA']);
        $this->addTheSellingCompanyColumn();
        DB::table('products')->where('id', 1)->update([StorefrontCatalog::ENTITY_COLUMN => 1]);
        $this->cartRow($customer->id, 1, 100);
        $this->cartRow($customer->id, 2, 250);
        DB::table('cart_shippings')->insert(['cart_group_id' => 'group-' . $customer->id . 'c', 'shipping_method_id' => 1, 'shipping_cost' => 25]);

        $this->asCustomer($customer)->getJson('/api/v1/customer/order/place')->assertOk();

        $this->assertSame([100001 => [1, 2]], $this->ordersWithTheirLines());
    }

    // ---- a refused sale keeps nobody's money -------------------------------------------------------------

    public function test_the_admin_till_refusing_a_mixed_wallet_sale_leaves_the_wallet_alone(): void
    {
        $customerId = $this->customerWithAFullWallet();

        $this->sellAtTheAdminTill(
            [$this->sessionLine(1, 'Paracetamol SA', 100, $customerId), $this->sessionLine(2, 'Paracetamol AE', 250, $customerId)],
            350, 'wallet', 'saved-customer-' . $customerId,
        );

        $this->assertSame(['error'], $this->toastTypes());
        $this->nothingWasSold();
        $this->walletIsUntouched($customerId);
    }

    public function test_the_vendor_till_refusing_a_mixed_wallet_sale_leaves_the_wallet_alone(): void
    {
        $customerId = $this->customerWithAFullWallet();

        $this->sellAtTheVendorTill(
            [$this->sessionLine(1, 'Paracetamol SA', 100, $customerId), $this->sessionLine(2, 'Paracetamol AE', 250, $customerId)],
            350, 'wallet', 'saved-customer-' . $customerId,
        );

        $this->assertSame(['error'], $this->toastTypes());
        $this->nothingWasSold();
        $this->walletIsUntouched($customerId);
    }

    public function test_the_seller_app_till_refusing_a_mixed_wallet_sale_leaves_the_wallet_alone(): void
    {
        $customerId = $this->customerWithAFullWallet();

        try {
            $this->sellAtTheSellerAppTill([$this->payloadLine(1, 100), $this->payloadLine(2, 250)], 350, 'wallet', $customerId);
            $this->fail('the sale was not refused');
        } catch (OrderMixesSellingCompaniesException) {
            $this->nothingWasSold();
            $this->walletIsUntouched($customerId);
        }
    }

    /**
     * The refusal this till has always had, and the reason the debit was moved rather than wrapped in a transaction: the out-of-stock line
     * sat behind the same debit, so the same money was lost without this rule being involved at all. One move closes both.
     */
    public function test_the_seller_app_till_refusing_an_out_of_stock_wallet_sale_leaves_the_wallet_alone(): void
    {
        $customerId = $this->customerWithAFullWallet();

        $response = $this->sellAtTheSellerAppTill([$this->payloadLine(1, 100, quantity: 99)], 100, 'wallet', $customerId);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData()->product_not_available, 'the till refused the line it cannot fill');
        $this->nothingWasSold();
        $this->walletIsUntouched($customerId);
    }

    public function test_a_lawful_wallet_sale_still_debits_the_customer_exactly_once(): void
    {
        $customerId = $this->customerWithAFullWallet();

        $this->sellAtTheAdminTill([$this->sessionLine(1, 'Paracetamol SA', 100, $customerId)], 100, 'wallet', 'saved-customer-' . $customerId);

        $this->assertSame(['success'], $this->toastTypes());
        $this->assertSame([100001 => [1]], $this->ordersWithTheirLines());
        $this->assertSame(['balance' => '900.00', 'debits' => 1], $this->wallet($customerId));
        $this->assertSame(
            '100.00',
            number_format((float) DB::table('wallet_transactions')->where('user_id', $customerId)->value('debit'), 2, '.', ''),
            'debited the money the sale was rung up for, once',
        );
    }
}
