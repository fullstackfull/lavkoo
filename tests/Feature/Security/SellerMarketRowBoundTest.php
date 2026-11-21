<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Services\Marketplace\SellerMarketTotals;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSellerMarketWorld;
use Tests\Support\GrantsSellerMarkets;
use Tests\TestCase;

/**
 * What a granted seller is actually answered, row by row (K-09, K-04.2).
 *
 * The companion of SellerMarketScopeTest and deliberately not the same test. That one proves the refusal: a request *naming* a
 * market outside the grant is refused on every surface. This one proves the narrowing: a request naming **no** market is answered
 * only the rows of the markets it holds. The distinction is the whole reason this file exists — the refusal matrix was fourteen
 * exercises of one group middleware, so removing the narrowing left it green, and a surface that answered every market passed
 * exactly like one that was bound.
 *
 * So every assertion here is about rows. Each names an id, a count or a figure that only exists in a market the token does not
 * hold, and each fails when the bound on its own surface is removed — proved by mutation, one surface at a time, not by reasoning.
 *
 * The world is the real one: a real bearer token through the real middleware chain of the real route, and the tables the
 * seller-centre screens read are built, because a controller that answers 500 on a missing table proves nothing about narrowing.
 *
 * Orders: 101 (SA), 102 (AE), 103 (KW), 104 (no record), 105 (SA, no currency). The grant under test is SA alone, which holds
 * 101, 104 and 105 — 104 because an order with no record belongs to the default market, as the whole platform reads it.
 */
class SellerMarketRowBoundTest extends TestCase
{
    use BuildsSellerMarketWorld;
    use DatabaseTransactions;
    use GrantsSellerMarkets;

    /** The orders of markets the grant under test does not hold. Neither id may appear in any answer. */
    private const ORDERS_OUTSIDE = [102, 103];

    /** The orders the grant under test holds. */
    private const ORDERS_INSIDE = [101, 104, 105];

    /** A product in a market no test grants, priced and stocked so a figure of it is unmistakable in any total. */
    private const PRODUCT_OUTSIDE = 977;

    private const PRODUCT_INSIDE = 955;

    private const STAFF_TOKEN = 'row-bound-staff-token-that-is-longer-than-thirty-characters';

    /** The one order status under which every order writer below runs to its write rather than refusing on a precondition. */
    private const DELIVERED_SO_THE_WRITE_IS_OTHERWISE_ALLOWED = 'delivered';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerMarketWorld();
        $this->createTheMarketGrantTable();
        $this->giveProductsTheirStockColumns();
        $this->createTheSellerLedger();
        $this->createTheDigitalCatalogueTables();
        $this->createTheSellerCentreTables();

        $this->insertSellerProduct(id: self::PRODUCT_INSIDE, sellerId: self::SELLER, market: 'SA', stock: 5, unitPrice: 10);
        $this->insertSellerProduct(id: self::PRODUCT_OUTSIDE, sellerId: self::SELLER, market: self::UNGRANTED_MARKET, stock: 777, unitPrice: 99999);
    }

    protected function tearDown(): void
    {
        $this->dropTheSellerCentreTables();
        $this->dropTheMarketGrantTable();
        $this->tearDownSellerMarketWorld();

        parent::tearDown();
    }

    /** The grant under test: Saudi Arabia and nothing else, with the switch on. */
    private function grantSaudiAlone(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');
    }

    /**
     * Every shape of reading one order by its id. Each is a route of its own, and before this each answered 200 with another
     * market's record while `orders/list` on the same token refused it.
     *
     * @return array<string, array{0: string}>
     */
    public static function singleOrderReads(): array
    {
        return ['the order detail' => ['%d'], 'its financial breakdown' => ['%d/breakdown'], 'its invoice' => ['%d/invoice']];
    }

    #[DataProvider('singleOrderReads')]
    public function test_reading_one_order_by_its_id_reaches_only_the_granted_markets(string $shape): void
    {
        $this->grantSaudiAlone();

        foreach (self::ORDERS_INSIDE as $orderId) {
            $this->sellerApi('get', 'orders/' . sprintf($shape, $orderId))
                ->assertStatus(200);
        }

        foreach (self::ORDERS_OUTSIDE as $orderId) {
            $answer = $this->sellerApi('get', 'orders/' . sprintf($shape, $orderId));

            $answer->assertStatus(404);
            $this->assertStringNotContainsString(
                'ref-' . $orderId,
                $answer->getContent(),
                "orders/{$shape} must carry nothing of an order in a market the token was not granted",
            );
        }
    }

    public function test_the_order_detail_answers_exactly_the_orders_the_order_list_answers(): void
    {
        $this->grantSaudiAlone();

        $listed = array_column((array) $this->sellerApi('post', 'orders/list', ['limit' => 50, 'offset' => 1])->json('orders'), 'id');

        $readable = [];
        foreach ([101, 102, 103, 104, 105] as $orderId) {
            if ($this->sellerApi('get', "orders/{$orderId}")->status() === 200) {
                $readable[] = $orderId;
            }
        }

        sort($listed);
        $this->assertSame(
            $listed,
            $readable,
            'the orders that can be opened one by one are the orders the list offers, with nothing reachable past it',
        );
    }

    public function test_forcing_a_status_onto_an_order_of_another_market_changes_nothing(): void
    {
        $this->grantSaudiAlone();

        $answer = $this->sellerApi('put', 'orders/order-detail-status/102', ['id' => 102, 'order_status' => 'canceled']);

        $answer->assertStatus(404);
        $this->assertSame(
            'pending',
            DB::table('orders')->where('id', 102)->value('order_status'),
            'an order of a market outside the grant keeps the status it had',
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function singleProductReads(): array
    {
        return [
            'the edit form' => ['get', 'products/edit/%d'],
            'the review list' => ['get', 'products/review-list/%d'],
        ];
    }

    #[DataProvider('singleProductReads')]
    public function test_reading_one_product_by_its_id_reaches_only_the_granted_markets(string $method, string $shape): void
    {
        $this->grantSaudiAlone();

        $this->sellerApi($method, sprintf($shape, self::PRODUCT_INSIDE))->assertStatus(200);

        $answer = $this->sellerApi($method, sprintf($shape, self::PRODUCT_OUTSIDE));

        $answer->assertStatus(404);
        $this->assertStringNotContainsString('99999', $answer->getContent(), "{$shape} must carry no figure of another market's product");
    }

    /**
     * The two catalogue lists a shop reaches by naming its own id. They are not `products/list`, they carry the whole product
     * row, and the market bound had not been put on either of them — found by enumerating the route file rather than the screens.
     *
     * @return array<string, array{0: string}>
     */
    public static function catalogueListsReachedByShopId(): array
    {
        return ['the whole catalogue' => ['all-products'], 'the catalogue an order edit picks from' => ['edit-order-all-products']];
    }

    #[DataProvider('catalogueListsReachedByShopId')]
    public function test_a_catalogue_list_reached_by_the_shops_own_id_holds_only_the_granted_markets(string $list): void
    {
        $this->grantSaudiAlone();

        $answer = $this->sellerApi('get', 'products/' . self::SELLER . '/' . $list . '?limit=50&offset=1');

        $answer->assertStatus(200);
        $this->assertSame(
            [self::PRODUCT_INSIDE],
            array_column((array) $answer->json('products'), 'id'),
            "products/{$list} must hold the granted market and nothing else",
        );
    }

    public function test_deleting_a_product_of_another_market_leaves_it_where_it_is(): void
    {
        $this->grantSaudiAlone();

        $this->sellerApi('delete', 'products/delete/' . self::PRODUCT_OUTSIDE)->assertStatus(404);

        $this->assertTrue(
            Product::withoutGlobalScopes()->whereKey(self::PRODUCT_OUTSIDE)->exists(),
            'a product of a market outside the grant is not deleted by a token that cannot even read it',
        );
    }

    public function test_the_order_report_answers_the_same_orders_as_the_order_list(): void
    {
        $this->grantSaudiAlone();

        $listed = array_column((array) $this->sellerApi('post', 'orders/list', ['limit' => 50, 'offset' => 1])->json('orders'), 'id');
        $reported = array_column((array) $this->sellerApi('get', 'seller-center/reports/orders')->json('orders.orders'), 'id');

        sort($listed);
        sort($reported);

        $this->assertSame($listed, $reported, 'the report is the list, not a way around it');
        $this->assertSame(self::ORDERS_INSIDE, $reported, 'and it is the granted markets');
    }

    public function test_the_product_report_and_the_stock_report_see_only_the_granted_markets(): void
    {
        $this->grantSaudiAlone();

        $products = array_column((array) $this->sellerApi('get', 'seller-center/reports/products')->json('products.products'), 'id');
        $stock = array_column((array) $this->sellerApi('get', 'seller-center/reports/stock')->json('products'), 'id');

        $this->assertSame([self::PRODUCT_INSIDE], $products, 'the product report holds the granted market and nothing else');
        $this->assertSame([self::PRODUCT_INSIDE], $stock, 'and so does the stock report');
    }

    public function test_the_exported_order_sheet_carries_only_the_granted_markets(): void
    {
        $this->grantSaudiAlone();
        Excel::fake();

        $this->sellerApi('get', 'seller-center/reports/orders/export')->assertStatus(200);
        $sheet = $this->exportedSheet('order-report.xlsx');

        foreach (self::ORDERS_INSIDE as $orderId) {
            $this->assertStringContainsString((string) $orderId, $sheet, "the sheet carries order {$orderId}");
        }

        foreach (self::ORDERS_OUTSIDE as $orderId) {
            $this->assertStringNotContainsString(
                (string) $orderId,
                $sheet,
                "an export file must not carry order {$orderId} off the platform: its market was never granted",
            );
        }
    }

    public function test_the_exported_product_sheet_carries_only_the_granted_markets(): void
    {
        $this->grantSaudiAlone();
        Excel::fake();

        $this->sellerApi('get', 'seller-center/reports/products/export')->assertStatus(200);
        $sheet = $this->exportedSheet('product-report.xlsx');

        $this->assertStringContainsString('Product ' . self::PRODUCT_INSIDE, $sheet, 'the sheet carries the granted market\'s product');
        $this->assertStringNotContainsString('Product ' . self::PRODUCT_OUTSIDE, $sheet, 'and carries no product of a market outside the grant');
        $this->assertStringNotContainsString('99999', $sheet, 'nor its price');
    }

    public function test_the_exported_stock_sheet_carries_only_the_granted_markets(): void
    {
        $this->grantSaudiAlone();
        Excel::fake();

        $this->sellerApi('get', 'seller-center/reports/stock/export')->assertStatus(200);
        $sheet = $this->exportedSheet('product-stock-report.xlsx');

        $this->assertStringContainsString('Product ' . self::PRODUCT_INSIDE, $sheet, 'the sheet carries the granted market\'s product');
        $this->assertStringNotContainsString('777', $sheet, 'and no stock level of a market outside the grant');
    }

    public function test_a_report_of_two_currencies_refuses_the_one_total_and_gives_each_its_own(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');

        $report = $this->sellerApi('get', 'seller-center/reports/orders')->assertStatus(200)->json();

        $this->assertNull($report['amounts'], 'riyals and dirhams are not one amount');
        $this->assertNull($report['payments'], 'nor one payment total');
        $this->assertNull($report['chart']['values'], 'nor one series');
        $this->assertSame(SellerMarketTotals::CURRENCIES_DIFFER, $report['totals_refused_because'], 'and the reason is in the answer');

        $byCurrency = collect($report['amounts_by_currency'])->keyBy('currency_code');
        $this->assertSame(610.0, (float) $byCurrency['SAR']['due'], 'the riyal orders keep their own real figure');
        $this->assertSame(202.0, (float) $byCurrency['AED']['due'], 'and the dirham order keeps its own');
        $this->assertStringNotContainsString('812', json_encode($report), 'the sum of the two is nowhere in the answer');
    }

    public function test_one_currency_is_answered_with_the_one_total_exactly_as_before(): void
    {
        $this->grantSaudiAlone();

        $report = $this->sellerApi('get', 'seller-center/reports/orders')->assertStatus(200)->json();

        $this->assertSame(610.0, (float) $report['amounts']['due'], 'a single-currency period keeps the figure it always printed');
        $this->assertArrayNotHasKey('totals_refused_because', $report, 'and says nothing about a refusal that did not happen');
        $this->assertIsArray($report['chart']['values'], 'and keeps its series');
    }

    /**
     * The product report, which was left out of the per-currency rule entirely while the controller's own docblock asserted
     * that "no screen here adds two currencies". It answered `sold_amount` 1200 — a thousand riyals plus two hundred dirhams —
     * and `discount_given` 10, which is 7 plus 3.
     */
    public function test_the_product_report_refuses_the_one_money_figure_and_keeps_the_count(): void
    {
        $this->sellInTwoCurrencies();

        $report = $this->sellerApi('get', 'seller-center/reports/products')->assertStatus(200)->json();

        $this->assertNull($report['totals']['sold_amount'], 'a thousand riyals and two hundred dirhams are not 1200');
        $this->assertNull($report['totals']['discount_given'], 'nor are 7 riyals and 3 dirhams 10');
        $this->assertSame(2.0, (float) $report['totals']['sold_quantity'], 'but two units sold are two units sold: a count is not money');
        $this->assertSame(SellerMarketTotals::CURRENCIES_DIFFER, $report['totals_refused_because']);

        $byCurrency = collect($report['totals_by_currency'])->keyBy('currency_code');
        $this->assertSame(1000.0, (float) $byCurrency['SAR']['sold_amount'], 'the riyal sale keeps its own real figure');
        $this->assertSame(7.0, (float) $byCurrency['SAR']['discount_given']);
        $this->assertSame(200.0, (float) $byCurrency['AED']['sold_amount'], 'and the dirham sale keeps its own');
        $this->assertSame(3.0, (float) $byCurrency['AED']['discount_given']);
        $this->assertStringNotContainsString('1200', json_encode($report['totals']), 'and the sum of the two is not in the totals');
    }

    /**
     * The three ledger screens that printed one number over two currencies. The briefing was the worst of them: it printed the
     * riyal-plus-dirham total **under the currency code SAR**, which reads as a checked figure rather than an unlabelled one.
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function ledgerBalanceScreens(): array
    {
        return [
            'the shop overview' => ['seller-center/overview', 'finance', ['available', 'balance', 'withdrawable']],
            'the payout screen' => ['seller-center/payouts', '', ['balances', 'withdrawable']],
            'the daily briefing' => ['seller-center/control-tower/briefing', 'balance', ['currency', 'pending', 'withdrawable']],
        ];
    }

    #[DataProvider('ledgerBalanceScreens')]
    public function test_a_ledger_screen_of_two_currencies_refuses_the_one_balance(string $uri, string $block, array $withheld): void
    {
        $this->grantSaudiAlone();
        $this->insertLedgerEntry(sellerId: self::SELLER, currency: 'SAR', credit: 100, debit: 0);
        $this->insertLedgerEntry(sellerId: self::SELLER, currency: 'AED', credit: 40, debit: 0);

        $answer = $this->sellerApi('get', $uri)->assertStatus(200);
        $money = (array) ($block === '' ? $answer->json() : $answer->json($block));

        foreach ($withheld as $key) {
            $this->assertNull($money[$key], "{$uri} must not answer one {$key} over two currencies");
        }

        $this->assertSame(SellerMarketTotals::CURRENCIES_DIFFER, $money['totals_refused_because'], "{$uri} says why");

        $byCurrency = collect($money['balances_by_currency'] ?? $money['balance_by_currency'])->keyBy('currency_code');
        $this->assertSame(100.0, (float) $byCurrency['SAR']['balance'], 'the riyals are still there and still 100');
        $this->assertSame(40.0, (float) $byCurrency['AED']['balance'], 'and the dirhams 40');
        $this->assertSame(100.0, (float) $byCurrency['SAR']['withdrawable'], 'each currency keeps its own withdrawable');
        $this->assertStringNotContainsString('140', json_encode($money), 'and 140 is nowhere in the answer');
    }

    /**
     * The seller's own reconciliation. `reconciles` is a comparison of `recorded.net` with `credited.amount`, so over two
     * currencies it was a verdict about whether one mixed-currency sum matched another.
     */
    public function test_the_reconciliation_refuses_a_verdict_it_cannot_reach_over_two_currencies(): void
    {
        $this->sellInTwoCurrencies();
        $this->insertLedgerEntry(sellerId: self::SELLER, currency: 'SAR', credit: 100, debit: 0);
        $this->insertLedgerEntry(sellerId: self::SELLER, currency: 'AED', credit: 40, debit: 0);

        $answer = $this->sellerApi('get', 'seller-center/finance/reconciliation')->assertStatus(200)->json();

        $this->assertNull($answer['delivered']['gross'], 'riyal sales and dirham sales are not one gross');
        $this->assertNull($answer['credited']['amount'], 'nor one credited amount');
        $this->assertNull($answer['reconciles'], 'and whether they match is not a question with an answer');
        $this->assertSame(SellerMarketTotals::CURRENCIES_DIFFER, $answer['totals_refused_because']);

        $delivered = collect($answer['delivered_by_currency'])->keyBy('currency_code');
        $this->assertSame(993.0, (float) $delivered['SAR']['gross'], 'the riyal side keeps its own real gross');
        $this->assertSame(197.0, (float) $delivered['AED']['gross'], 'and the dirham side its own');

        $credited = collect($answer['credited_by_currency'])->keyBy('currency_code');
        $this->assertSame(100.0, (float) $credited['SAR']['amount']);
        $this->assertSame(40.0, (float) $credited['AED']['amount']);
        $this->assertSame(2, $answer['delivered']['lines'], 'the line count survives: a count is not money');
    }

    /**
     * And the same screen read the orders of every market. `gross` was once composed **entirely** of the two markets the token
     * had been refused, because this walked `order_details` joined to `orders` by seller alone.
     */
    public function test_the_reconciliation_counts_only_the_orders_of_the_granted_markets(): void
    {
        $this->grantSaudiAlone();
        $this->deliverOneLine(orderId: 101, productId: self::PRODUCT_INSIDE, price: 100, discount: 0);
        $this->deliverOneLine(orderId: 102, productId: self::PRODUCT_OUTSIDE, price: 55555, discount: 0);
        $this->deliverOneLine(orderId: 103, productId: self::PRODUCT_OUTSIDE, price: 55555, discount: 0);

        $answer = $this->sellerApi('get', 'seller-center/finance/reconciliation')->assertStatus(200);

        $this->assertSame(100.0, (float) $answer->json('delivered.gross'), 'the gross is the granted market and nothing else');
        $this->assertSame(1, $answer->json('delivered.lines'));
        $this->assertStringNotContainsString('55555', $answer->getContent(), 'no figure of a market the token was refused is in the answer');
        $this->assertStringNotContainsString('111110', $answer->getContent(), 'nor the sum of them');
    }

    /** A delivered sale in riyals and another in dirhams, which is the smallest world in which the money rule has to decide. */
    private function sellInTwoCurrencies(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');

        $this->insertSellerProduct(id: 966, sellerId: self::SELLER, market: 'AE', stock: 3, unitPrice: 200);
        $this->deliverOneLine(orderId: 101, productId: self::PRODUCT_INSIDE, price: 1000, discount: 7);
        $this->deliverOneLine(orderId: 102, productId: 966, price: 200, discount: 3);
    }

    /** Points the one line of an order at a product, marks it delivered and prices it. */
    private function deliverOneLine(int $orderId, int $productId, float $price, float $discount): void
    {
        DB::table('order_details')->where('order_id', $orderId)->update([
            'product_id' => $productId,
            'delivery_status' => self::DELIVERED_SO_THE_WRITE_IS_OTHERWISE_ALLOWED,
            'qty' => 1,
            'price' => $price,
            'discount' => $discount,
        ]);
    }

    public function test_the_printed_report_is_refused_rather_than_printed_with_a_sum_of_two_currencies(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');

        $refused = $this->sellerApi('get', 'seller-center/reports/orders/export-pdf');

        $refused->assertStatus(409);
        $this->assertSame(
            translate(SellerMarketTotals::CURRENCIES_DIFFER),
            $refused->json('errors.0.message'),
            'a PDF has no room for a missing figure, so it says why instead',
        );

        $this->flushHeaders();
        $inOneMarket = $this->withHeaders([
            'Authorization' => 'Bearer ' . self::SELLER_TOKEN,
            'Accept' => 'application/json',
            StorefrontResolver::HEADER => 'SA',
        ])->get('/api/v3/seller/seller-center/reports/orders/export-pdf');

        $inOneMarket->assertStatus(200);
    }

    public function test_the_statement_export_is_a_real_file_and_names_no_market(): void
    {
        $this->grantSaudiAlone();

        $this->insertLedgerEntry(sellerId: self::SELLER, currency: 'SAR', credit: 100, debit: 0);
        $this->insertLedgerEntry(sellerId: self::SELLER, currency: 'AED', credit: 40, debit: 0);

        $export = $this->sellerApi('get', 'seller-center/statement/export');
        $csv = $export->streamedContent();

        $this->assertSame(200, $export->baseResponse->getStatusCode());
        $this->assertNotSame('', $csv, 'the export is a streamed download, not an empty 200: its body is read from the stream');
        $this->assertSame(3, count(array_filter(explode("\n", trim($csv)))), 'a header and both ledger lines');
        $this->assertStringContainsString('100', $csv, 'the riyal line is in the file');
        $this->assertStringContainsString('40', $csv, 'and so is the dirham line: the ledger carries no market, so the account is not narrowed by one');
    }

    public function test_a_return_of_an_order_in_another_market_is_not_found(): void
    {
        $this->grantSaudiAlone();
        $this->insertReturnShipment(id: 1, sellerId: self::SELLER, orderId: 102, productId: self::PRODUCT_OUTSIDE);
        $this->insertReturnShipment(id: 2, sellerId: self::SELLER, orderId: 101, productId: self::PRODUCT_INSIDE);

        $this->sellerApi('get', 'seller-center/returns/2')->assertStatus(200);

        $outside = $this->sellerApi('get', 'seller-center/returns/1');
        $outside->assertStatus(404);
        $this->assertStringNotContainsString('RMA-1', $outside->getContent(), 'the return of a sale in another market carries nothing');

        $listed = array_column((array) $this->sellerApi('get', 'seller-center/returns')->json('returns'), 'id');
        $this->assertSame([2], $listed, 'and the list holds only the returns of the granted markets');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function returnDecisions(): array
    {
        return ['marking it in transit' => ['in-transit'], 'receiving it' => ['receive'], 'rejecting it' => ['reject']];
    }

    #[DataProvider('returnDecisions')]
    public function test_deciding_a_return_of_another_market_changes_nothing(string $decision): void
    {
        $this->grantSaudiAlone();
        $this->insertReturnShipment(id: 1, sellerId: self::SELLER, orderId: 102, productId: self::PRODUCT_OUTSIDE);

        $this->sellerApi('post', "seller-center/returns/1/{$decision}")->assertStatus(404);

        $this->assertSame(
            'authorized',
            DB::table('return_shipments')->where('id', 1)->value('status'),
            'a return of a market outside the grant keeps the status it had',
        );
    }

    /**
     * The audit trail is **withheld** from a limited principal rather than narrowed, because `audit_logs` carries no market.
     *
     * Recorded as a test because it is a real consequence to know about and not a control: the moment any grant exists in a shop,
     * a `staff.manage` holder who is not granted every market loses the trail entirely rather than seeing their own markets' part
     * of it. If that is ever changed to a narrowing, this test is the one that has to change with it.
     */
    public function test_the_audit_trail_is_withheld_from_a_limited_principal_rather_than_narrowed(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');
        $this->insertAuditLine(sellerId: self::SELLER, action: 'seller.bank_details_changed');

        $withheld = $this->sellerApi('get', 'seller-center/security/audit');

        $withheld->assertStatus(403);
        $this->assertSame('market_scope', $withheld->json('errors.0.code'));
        $this->assertStringNotContainsString('bank_details_changed', $withheld->getContent(), 'and no line of the trail travels with the refusal');

        foreach (['AE', 'KW', 'QA', 'BH', 'OM'] as $market) {
            $this->grantMarket(sellerId: self::SELLER, market: $market);
        }

        $this->assertSame(
            1,
            $this->sellerApi('get', 'seller-center/security/audit')->assertStatus(200)->json('total'),
            'a principal granted every market the platform serves reads the whole trail',
        );
    }

    public function test_a_bulk_job_receipt_naming_another_markets_product_is_not_found(): void
    {
        $this->grantSaudiAlone();
        $this->insertBulkJob(id: 1, sellerId: self::SELLER, productIds: [self::PRODUCT_OUTSIDE]);
        $this->insertBulkJob(id: 2, sellerId: self::SELLER, productIds: [self::PRODUCT_INSIDE]);

        $this->sellerApi('get', 'seller-center/bulk-jobs/2')->assertStatus(200);

        $outside = $this->sellerApi('get', 'seller-center/bulk-jobs/1');
        $outside->assertStatus(404);
        $this->assertStringNotContainsString((string) self::PRODUCT_OUTSIDE, $outside->getContent(), 'a receipt names product ids: none of them may be another market\'s');

        $listed = array_column((array) $this->sellerApi('get', 'seller-center/bulk-jobs')->json('jobs'), 'id');
        $this->assertSame([2], $listed, 'and the list of receipts holds only the ones this token may read');
    }

    public function test_a_bulk_job_cannot_be_queued_against_another_markets_product(): void
    {
        $this->grantSaudiAlone();

        $refused = $this->sellerApi('post', 'seller-center/bulk-jobs/price', [
            'product_ids' => [self::PRODUCT_INSIDE, self::PRODUCT_OUTSIDE],
            'mode' => 'set',
            'value' => 1,
        ]);

        $refused->assertStatus(403);
        $this->assertSame('market_scope', $refused->json('errors.0.code'), 'the refusal is the market refusal');
        $this->assertSame(0, DB::table('seller_bulk_jobs')->count(), 'and no job exists to be run later, when the grant is no longer readable');
        $this->assertSame(
            99999.0,
            (float) DB::table('products')->where('id', self::PRODUCT_OUTSIDE)->value('unit_price'),
            'the price of the product in the other market is untouched',
        );
    }

    public function test_an_automation_rule_offers_no_product_outside_the_grant(): void
    {
        $this->grantSaudiAlone();
        $this->insertAutomationRule(id: 1, sellerId: self::SELLER, productIds: [self::PRODUCT_OUTSIDE, self::PRODUCT_INSIDE]);

        $preview = $this->sellerApi('get', 'seller-center/automation/rules/1/preview')->assertStatus(200)->json();

        $this->assertSame(
            [self::PRODUCT_INSIDE],
            array_column((array) $preview['subjects'], 'subject_id'),
            'a rule previewed by a granted token offers only the products of its markets, whatever the rule names',
        );
        $this->assertSame(1, $preview['matched'], 'and counts only those');
    }

    public function test_the_catalogue_a_rule_is_written_against_holds_only_the_granted_markets(): void
    {
        $this->grantSaudiAlone();

        $catalogue = $this->sellerApi('get', 'seller-center/automation/catalogue')->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('99999', $catalogue, 'the shop\'s own catalogue, as a granted token sees it, holds no price of another market');
    }

    public function test_a_staff_member_with_no_row_of_their_own_is_held_to_the_shops_markets(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');
        $role = $this->insertRole(
            sellerId: self::SELLER,
            name: 'Everything the shop can delegate',
            permissions: ['orders.view', 'orders.manage', 'products.view', 'finance.view', 'staff.manage'],
        );
        $this->insertStaff(sellerId: self::SELLER, roleId: $role, token: self::STAFF_TOKEN);

        $this->assertSame(403, $this->listOrdersIn('KW', self::SELLER_TOKEN)->status(), 'the owner of a Saudi-only shop is refused Kuwait');
        $this->assertSame(403, $this->listOrdersIn('KW', self::STAFF_TOKEN)->status(), 'and so is their employee, who holds no row of their own');

        $this->flushHeaders();
        $staffOrders = array_column((array) $this->sellerApi('post', 'orders/list', ['limit' => 50, 'offset' => 1], self::STAFF_TOKEN)->json('orders'), 'id');
        sort($staffOrders);
        $this->assertSame(self::ORDERS_INSIDE, $staffOrders, 'the employee sees the shop\'s markets, not every market there is');

        $me = $this->sellerApi('get', 'seller-center/security/me', token: self::STAFF_TOKEN)->assertStatus(200)->json();
        $this->assertSame('limited', $me['market_scope'], 'and is never told they hold every market of a shop that holds one');
        $this->assertSame(['SA'], $me['allowed_markets']);
    }

    /**
     * The actions that take the whole shop. A row-less staff member of a limited shop used to pass this gate while the owner who
     * created them was refused by it — and these four are the ones worth having: who may sign in, where the money is paid, the
     * trail of what was done, and deleting the shop.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function shopWideActions(): array
    {
        return [
            'staff management' => ['get', 'seller-center/security/staff'],
            'the audit trail' => ['get', 'seller-center/security/audit'],
            'bank details' => ['post', 'payment-information/add'],
            'account deletion' => ['delete', 'account-delete'],
        ];
    }

    #[DataProvider('shopWideActions')]
    public function test_a_row_less_staff_member_of_a_limited_shop_reaches_no_shop_wide_action(string $method, string $uri): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');
        $role = $this->insertRole(sellerId: self::SELLER, name: 'Manager', permissions: ['staff.manage', 'finance.view', 'payouts.request', 'orders.view']);
        $this->insertStaff(sellerId: self::SELLER, roleId: $role, token: self::STAFF_TOKEN);

        $owner = $this->sellerApi($method, $uri);
        $staff = $this->sellerApi($method, $uri, token: self::STAFF_TOKEN);

        $this->assertSame(403, $owner->status(), "{$uri} is refused to the owner of a shop granted one market");
        $this->assertSame('market_scope', $owner->json('errors.0.code'), 'and refused for the market, not for a permission');
        $this->assertSame(403, $staff->status(), "{$uri} must be refused to their employee for at least as good a reason");
        $this->assertSame(['errors'], array_keys((array) $staff->json()), "{$uri} carries no data with its refusal");
        $this->assertContains(
            $staff->json('errors.0.code'),
            ['market_scope', 'owner_only'],
            // Two of the four are gated on being the account holder before the market gate is reached, so a staff member is
            // refused there as a non-owner rather than for the market. Either refusal is the shop staying shut; what must never
            // happen is the 200 this used to answer.
            "{$uri} refuses the employee as a non-owner or for the market, and nothing else",
        );
    }

    public function test_a_staff_grant_naming_a_market_the_shop_does_not_hold_reaches_nothing(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');
        $role = $this->insertRole(sellerId: self::SELLER, name: 'Order clerk', permissions: ['orders.view', 'orders.manage']);
        $staffId = $this->insertStaff(sellerId: self::SELLER, roleId: $role, token: self::STAFF_TOKEN);
        $this->grantMarket(sellerId: self::SELLER, market: 'AE', staffId: $staffId);

        $orders = $this->sellerApi('post', 'orders/list', ['limit' => 50, 'offset' => 1], self::STAFF_TOKEN);

        $orders->assertStatus(200);
        $this->assertSame([], (array) $orders->json('orders'), 'a row granting a market the shop itself does not hold grants nothing');
        $this->assertSame(403, $this->listOrdersIn('AE', self::STAFF_TOKEN)->status(), 'and naming that market is refused');
    }

    public function test_a_staff_member_may_still_be_given_less_than_an_unrestricted_shop_holds(): void
    {
        $this->switchMarketScopesOn();
        $role = $this->insertRole(sellerId: self::SELLER, name: 'Order clerk', permissions: ['orders.view', 'orders.manage']);
        $staffId = $this->insertStaff(sellerId: self::SELLER, roleId: $role, token: self::STAFF_TOKEN);
        $this->grantMarket(sellerId: self::SELLER, market: 'AE', staffId: $staffId);

        $orders = $this->sellerApi('post', 'orders/list', ['limit' => 50, 'offset' => 1], self::STAFF_TOKEN);

        $this->assertSame(
            ['AE'],
            array_values(array_unique(array_column((array) $orders->json('orders'), 'storefront_country'))),
            'a shop with no grant of its own lends nothing, so its employee keeps exactly the market they were given',
        );
    }

    /**
     * The sharpest of them. A refund request carries no market of its own, so before this every finder in the refund family
     * narrowed by seller alone: the same token that was answered 404 on `orders/103` **approved the refund on that order**,
     * wrote a `refund_statuses` row, and read the whole record — order id, amount and note — back out of `refund/list`.
     */
    public function test_a_refund_on_an_order_of_another_market_is_neither_decided_nor_read(): void
    {
        $this->grantSaudiAlone();
        $outsideLine = $this->insertRefundRequest(id: 11, orderId: 103, productId: self::PRODUCT_OUTSIDE, amount: 250);
        $this->insertRefundRequest(id: 12, orderId: 101, productId: self::PRODUCT_INSIDE, amount: 50);

        $this->sellerApi('get', 'orders/103')->assertStatus(404);

        $decision = $this->sellerApi('post', 'refund/refund-status-update', [
            'refund_status' => 'approved', 'refund_request_id' => 11, 'note' => 'approved from outside the grant',
        ]);

        $decision->assertStatus(404);
        $this->assertSame('pending', DB::table('refund_requests')->where('id', 11)->value('status'), 'the money decision was not taken');
        $this->assertNull(DB::table('refund_requests')->where('id', 11)->value('change_by'), 'and nobody is recorded as having taken it');
        $this->assertSame(0, DB::table('refund_statuses')->where('refund_request_id', 11)->count(), 'and no status line was written');
        $this->assertSame(
            0,
            (int) DB::table('order_details')->where('id', $outsideLine)->value('refund_request'),
            'and the order line of the other market is still at the stage it was',
        );

        $listed = $this->sellerApi('get', 'refund/list')->assertStatus(200);
        $this->assertSame([12], array_column((array) $listed->json(), 'id'), 'the list holds only the refunds of the granted markets');
        $this->assertStringNotContainsString('250', $listed->getContent(), 'and no amount of a refund in a market the token cannot read');

        $single = $this->sellerApi('get', 'refund/single-item', ['id' => 11])->assertStatus(200);
        $this->assertNull($single->json('id'), 'the single item carries no refund of another market');

        $details = $this->sellerApi('get', 'refund/refund-details', ['order_details_id' => $outsideLine]);
        $details->assertStatus(404);
        $this->assertStringNotContainsString('250', $details->getContent(), 'nor do its details');
    }

    /**
     * The eight writers that key off an order id. Seven of them found by seller alone, so the token refused `orders/102` wrote
     * to it anyway: `update-payment-status` moved the money, and the other six moved the courier, the delivery man, the status,
     * the charge and the address.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: string}>
     */
    public static function orderWrites(): array
    {
        return [
            'marking it paid' => ['post', 'orders/update-payment-status', ['payment_status' => 'paid'], 'payment_status'],
            'handing it to a courier' => ['post', 'orders/assign-third-party-delivery', ['delivery_service_name' => 'Courier of another market'], 'delivery_service_name'],
            'assigning a delivery man' => ['put', 'orders/assign-delivery-man', ['delivery_man_id' => 9], 'delivery_man_id'],
            'rewriting its whole detail' => ['post', 'orders/order-detail-info-update', ['payment_status' => 'paid', 'order_status' => 'confirmed'], 'order_status'],
            'changing its delivery charge' => ['put', 'orders/delivery-charge-date-update', ['deliveryman_charge' => 77], 'deliveryman_charge'],
            'rewriting its address' => ['post', 'orders/address-update', ['address_type' => 'shipping', 'contact_person_name' => 'Somebody else'], 'shipping_address_data'],
        ];
    }

    #[DataProvider('orderWrites')]
    public function test_a_write_to_an_order_of_another_market_changes_nothing(string $method, string $uri, array $payload, string $column): void
    {
        $this->grantSaudiAlone();
        DB::table('delivery_men')->insert(['id' => 9, 'f_name' => 'Delivery', 'l_name' => 'Man']);
        // Delivered and not cash-pending, so that the only thing standing between this request and the write is the market
        // bound: a precondition that refuses it anyway would make the mutation below pass with the bound removed.
        DB::table('orders')->where('id', 102)->update(['order_status' => self::DELIVERED_SO_THE_WRITE_IS_OTHERWISE_ALLOWED]);
        $before = DB::table('orders')->where('id', 102)->value($column);

        $refused = $this->sellerApi($method, $uri, ['order_id' => 102, 'id' => 102] + $payload);

        $refused->assertStatus(404);
        $this->assertSame(
            $before,
            DB::table('orders')->where('id', 102)->value($column),
            "{$uri} must leave orders.{$column} of a market outside the grant exactly as it was",
        );
    }

    public function test_a_digital_file_cannot_be_attached_to_a_line_of_an_order_in_another_market(): void
    {
        $this->grantSaudiAlone();
        $outsideLine = (int) DB::table('order_details')->where('order_id', 102)->value('id');

        $refused = $this->sellerApi('put', 'orders/order-wise-product-upload', [
            'order_id' => $outsideLine,
            'digital_file_ready' => UploadedFile::fake()->create('delivered.pdf', 1, 'application/pdf'),
            'digital_file_after_sell' => UploadedFile::fake()->create('delivered.pdf', 1, 'application/pdf'),
        ]);

        $this->assertSame(202, $refused->status(), 'a line of an order in a market outside the grant is not there to be written to');
        $this->assertNull(
            DB::table('order_details')->where('id', $outsideLine)->value('digital_file_after_sell'),
            'and nothing was attached to it',
        );
    }

    /**
     * The till. It is a way into the catalogue like any other and the market bound had been put on none of it: `pos/product-list`
     * served the ungranted market's product in full, and a barcode is a product id by another name.
     */
    public function test_the_till_reaches_only_the_granted_markets(): void
    {
        $this->grantSaudiAlone();

        $list = $this->sellerApi('get', 'pos/product-list?limit=50&offset=1')->assertStatus(200);
        $this->assertSame([self::PRODUCT_INSIDE], array_column((array) $list->json('products'), 'id'), 'the till lists the granted market and nothing else');
        $this->assertStringNotContainsString('99999', $list->getContent(), 'and no price of another market');

        $byBarcode = $this->sellerApi('get', 'pos/products?code=CODE' . self::PRODUCT_OUTSIDE)->assertStatus(200);
        $this->assertNull($byBarcode->json('id'), 'a barcode of another market scans to nothing');

        $this->assertSame(
            [self::PRODUCT_INSIDE],
            array_column((array) $this->sellerApi('get', 'pos/product-list?limit=50&offset=1&name=Product')->json('products'), 'id'),
            'and a search in the till is bound too',
        );
    }

    /** The rendered contents of the spreadsheet a request downloaded: what the file would actually carry. */
    private function exportedSheet(string $fileName): string
    {
        $rendered = '';

        Excel::assertDownloaded($fileName, function (object $export) use (&$rendered): bool {
            $rendered = $export->view()->render();

            return true;
        });

        return $rendered;
    }

    private function listOrdersIn(string $market, string $token): TestResponse
    {
        $this->flushHeaders();

        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
            StorefrontResolver::HEADER => $market,
        ])->json('post', '/api/v3/seller/orders/list', ['limit' => 50, 'offset' => 1]);
    }
}
