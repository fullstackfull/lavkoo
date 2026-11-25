<?php

namespace Tests\Feature\Storefront;

use App\Models\StorefrontLegalAcceptance;
use App\Services\Address\AddressSchema;
use App\Services\Storefront\CheckoutLegalAcceptance;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\Support\KeepsOrderDebugLogClean;

/**
 * GCC-11 and J-15: what a customer agreed to at checkout is written down, against the order it belongs to.
 *
 * `StorefrontLegalAcceptanceRecorder` could write a receipt for a long time and NOTHING CALLED IT: the platform kept no record of what any
 * customer ever agreed to, in any market. These tests drive the real `GET /api/v1/customer/order/place` over HTTP, with a real cart, a real
 * customer, a real saved address and the real middleware stack, and read the rows that were written.
 *
 * The first two tests are the ones that protect what ships. Nothing is required until an operator lists a document type in
 * `gcc.storefronts.legal.explicit_acceptance`, which ships EMPTY, so the Saudi checkout writes nothing and behaves exactly as it does today;
 * and a required type the client did NOT name writes no row, because a receipt for a text nobody was shown would be worse than none.
 */
class CheckoutLegalAcceptanceTest extends ApiAddressTestCase
{
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontLegalDocuments;
    use KeepsOrderDebugLogClean;

    private const ACCEPTANCE_MIGRATION = '2026_11_24_110000_create_storefront_legal_acceptances_table.php';

    private const PLACE_ORDER = '/api/v1/customer/order/place';

    /** The product each storefront sells, as the six-storefront world seeds them. */
    private const PRODUCT_OF = ['SA' => 1, 'KW' => 3];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSixStorefrontCheckoutWorld();
        $this->buildStorefrontLegalDocumentsTable();
        $this->rememberOrderDebugLog();

        // The feature's own migration, run the way `php artisan migrate --path=…` runs it, so the table is exactly what production gets.
        if (!Schema::hasTable('storefront_legal_acceptances')) {
            (require database_path('migrations/' . self::ACCEPTANCE_MIGRATION))->up();
        }

        DB::table('storefront_legal_acceptances')->delete();
        config(['gcc.storefronts.legal.store_identity' => true]);
    }

    protected function tearDown(): void
    {
        $this->restoreOrderDebugLog();

        parent::tearDown();
    }

    private function requireAcceptanceOf(string ...$types): void
    {
        config(['gcc.storefronts.legal.explicit_acceptance' => $types]);
    }

    /** @return array{0: User, 1: int} the customer and the order they placed, with the types the client says it showed */
    private function placeAnOrderOn(string $country, array $showed = [], string $locale = 'en'): array
    {
        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: self::PRODUCT_OF[$country]);

        $query = $showed === [] ? '' : '?' . http_build_query(['accepted_documents' => $showed]);
        $orderId = $this->asCustomer($customer)
            ->withHeader('X-Storefront', $country)
            ->withHeader('lang', $locale)
            ->getJson(self::PLACE_ORDER . $query)
            ->assertOk()
            ->json('order_ids.0');

        return [$customer, (int) $orderId];
    }

    /**
     * Places one order the way a shopper on the WEBSITE places it: cash on delivery, their own saved address in the session, the
     * storefront header their browser sends. A different controller, a different guard and a different request shape from the API's.
     *
     * @return int the order that was placed
     */
    private function placeAWebOrderOn(string $country, array $showed = []): int
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->makeCustomer();
        $address = $this->makeStructuredAddress($this->ids, $customer->id);
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: self::PRODUCT_OF[$country]);

        $this->actingAs($customer, 'customer')
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'X-Storefront' => $country])
            ->withSession(['address_id' => $address->id, 'billing_address_id' => $address->id])
            ->post(route('checkout-complete'), ['payment_method' => 'cash_on_delivery'] + ($showed === [] ? [] : ['accepted_documents' => $showed]))
            ->assertOk()
            ->assertJsonPath('status', 1);

        return (int) DB::table('orders')->orderByDesc('id')->value('id');
    }

    /** @return list<array<string, mixed>> the receipts written, as rows */
    private function receipts(): array
    {
        return StorefrontLegalAcceptance::query()->orderBy('id')->get()->map(static fn (StorefrontLegalAcceptance $row): array => [
            'storefront_country' => $row->storefront_country,
            'document_type' => $row->document_type,
            'locale' => $row->locale,
            'document_version' => $row->document_version,
            'order_id' => $row->order_id,
            'customer_id' => $row->customer_id,
        ])->all();
    }

    public function test_with_no_type_requiring_acceptance_a_checkout_writes_nothing(): void
    {
        $this->publishRequiredLegalDocuments(country: 'KW', reviewed: false);

        [, $orderId] = $this->placeAnOrderOn('KW', showed: ['terms', 'privacy']);

        $this->assertGreaterThan(0, $orderId, 'the order was placed');
        $this->assertSame([], $this->receipts(), 'nothing is required, so nothing is recorded');
    }

    public function test_a_required_type_the_client_did_not_name_writes_no_receipt(): void
    {
        $this->requireAcceptanceOf('terms');
        $this->publishRequiredLegalDocuments(country: 'KW', reviewed: false);

        [, $orderId] = $this->placeAnOrderOn('KW');

        $this->assertGreaterThan(0, $orderId, 'and the checkout is not refused for it: the gate is an owner decision');
        $this->assertSame([], $this->receipts());
    }

    public function test_a_required_type_the_client_named_is_recorded_against_the_order(): void
    {
        $this->requireAcceptanceOf('terms');
        $this->publishRequiredLegalDocuments(country: 'KW', reviewed: false);

        [$customer, $orderId] = $this->placeAnOrderOn('KW', showed: ['terms']);

        $this->assertSame([[
            'storefront_country' => 'KW',
            'document_type' => 'terms',
            'locale' => 'en',
            'document_version' => 1,
            'order_id' => $orderId,
            'customer_id' => $customer->id,
        ]], $this->receipts());
    }

    /** The hash of the text itself, so what was agreed to can be compared with what the shop serves today. */
    public function test_the_receipt_carries_the_hash_of_the_text_that_was_shown(): void
    {
        $this->requireAcceptanceOf('terms');
        $this->publishRequiredLegalDocuments(country: 'KW', reviewed: false);

        $this->placeAnOrderOn('KW', showed: ['terms']);

        $this->assertSame(
            (string) DB::table('storefront_legal_documents')->where(['country_code' => 'KW', 'document_type' => 'terms', 'locale' => 'en'])->value('body_hash'),
            (string) StorefrontLegalAcceptance::query()->value('document_hash'),
        );
    }

    public function test_every_required_type_the_client_named_gets_its_own_receipt(): void
    {
        $this->requireAcceptanceOf('terms', 'privacy');
        $this->publishRequiredLegalDocuments(country: 'KW', reviewed: false);

        $this->placeAnOrderOn('KW', showed: ['terms', 'privacy']);

        $this->assertSame(['privacy', 'terms'], collect($this->receipts())->pluck('document_type')->sort()->values()->all());
    }

    /** A name the configuration does not require is ignored, whatever the client sends. */
    public function test_a_type_that_is_not_required_is_ignored(): void
    {
        $this->requireAcceptanceOf('terms');
        $this->publishRequiredLegalDocuments(country: 'KW', reviewed: false);

        $this->placeAnOrderOn('KW', showed: ['terms', 'something_invented', 'refund']);

        $this->assertSame(['terms'], collect($this->receipts())->pluck('document_type')->all());
    }

    /** The market is the ORDER's, so a receipt and the order it belongs to can never name different markets. */
    public function test_the_receipt_names_the_market_the_order_was_sold_in(): void
    {
        $this->requireAcceptanceOf('terms');
        $this->publishRequiredLegalDocuments(country: 'KW', reviewed: false);
        $this->publishRequiredLegalDocuments(country: 'SA', reviewed: false);

        [, $kuwaitiOrder] = $this->placeAnOrderOn('KW', showed: ['terms']);
        [, $saudiOrder] = $this->placeAnOrderOn('SA', showed: ['terms']);

        $byOrder = collect($this->receipts())->pluck('storefront_country', 'order_id')->all();
        $this->assertSame('KW', $byOrder[$kuwaitiOrder]);
        $this->assertSame('SA', $byOrder[$saudiOrder]);
    }

    /** The locale recorded is the one the API answered in -- the  header the customer app already sends -- and not a second claim from the client. */
    public function test_the_receipt_names_the_language_the_text_was_served_in(): void
    {
        $this->requireAcceptanceOf('terms');
        $this->publishRequiredLegalDocuments(country: 'KW', reviewed: false);

        $this->placeAnOrderOn('KW', showed: ['terms'], locale: 'ar');

        $this->assertSame('ar', (string) StorefrontLegalAcceptance::query()->value('locale'));
    }

    /**
     * Each receipt names the market its OWN order records, not the one the request was served from.
     *
     * At the three checkout endpoints the two agree by construction, so the difference is only visible when the rows say different things:
     * the service is asked here for two orders whose rows name different markets, which is what makes reading the order load-bearing
     * rather than decoration.
     */
    public function test_each_receipt_names_the_market_its_own_order_records(): void
    {
        $this->requireAcceptanceOf('terms');
        $this->publishRequiredLegalDocuments(country: 'KW', reviewed: false);
        $this->publishRequiredLegalDocuments(country: 'SA', reviewed: false);
        [, $kuwaitiOrder] = $this->placeAnOrderOn('KW', showed: ['terms']);
        DB::table('storefront_legal_acceptances')->delete();
        $saudiOrder = $kuwaitiOrder + 1000;
        DB::table('orders')->insert(['id' => $saudiOrder, 'customer_id' => 1, 'storefront_country' => 'SA', 'order_amount' => 1, 'created_at' => now(), 'updated_at' => now()]);

        app(CheckoutLegalAcceptance::class)->record(
            request: Request::create('/', 'GET', ['accepted_documents' => ['terms']]),
            orderIds: [$kuwaitiOrder, $saudiOrder],
        );

        $this->assertSame(
            [$kuwaitiOrder => 'KW', $saudiOrder => 'SA'],
            collect($this->receipts())->pluck('storefront_country', 'order_id')->all(),
        );
    }

    /** A required text the storefront has not published is a gap in the record, not a lost order. */
    public function test_a_missing_text_does_not_refuse_the_checkout(): void
    {
        $this->requireAcceptanceOf('terms');
        $this->publishRequiredLegalDocuments(country: 'KW', withheldType: 'terms', reviewed: false);

        [, $orderId] = $this->placeAnOrderOn('KW', showed: ['terms']);

        $this->assertGreaterThan(0, $orderId, 'the order exists and may already be paid for');
        $this->assertSame([], $this->receipts());
    }

    /** Identity is kept only when the privacy settings allow it, which the recorder decides and this does not override. */
    public function test_identity_is_not_kept_when_the_settings_refuse_it(): void
    {
        $this->requireAcceptanceOf('terms');
        config(['gcc.storefronts.legal.store_identity' => false]);
        $this->publishRequiredLegalDocuments(country: 'KW', reviewed: false);

        [, $orderId] = $this->placeAnOrderOn('KW', showed: ['terms']);

        $this->assertNull(StorefrontLegalAcceptance::query()->value('customer_id'));
        $this->assertSame($orderId, (int) StorefrontLegalAcceptance::query()->value('order_id'), 'the order is still named');
    }

    /**
     * The WEBSITE writes a receipt too, and that is the criterion this file exists for (I2-05).
     *
     * The recorder was called from the three `/api/v1` endpoints the customer app posts to, and from nowhere else. A customer buying on
     * the website went through `Web\WebController` instead, which never called it, so the whole web channel placed orders that needed an
     * accepted text and recorded nothing -- and the gap was invisible, because the three paths that were covered were the three with
     * tests. The call now sits inside `OrderManager::generateOrder`, which both controllers go through.
     *
     * This test posts to `/checkout-complete` and reads the row. It fails on the arrangement it replaced.
     */
    public function test_the_website_writes_a_receipt_and_not_only_the_customer_app(): void
    {
        $this->requireAcceptanceOf('terms');
        $this->publishRequiredLegalDocuments(country: 'KW', reviewed: false);

        $orderId = $this->placeAWebOrderOn('KW', showed: ['terms']);

        $this->assertSame([[
            'storefront_country' => 'KW',
            'document_type' => 'terms',
            'locale' => 'en',
            'document_version' => 1,
            'order_id' => $orderId,
            'customer_id' => DB::table('orders')->where('id', $orderId)->value('customer_id'),
        ]], $this->receipts(), 'a web order carries its own receipt, naming its own order');
    }

    /** The website obeys the same two gates as the app: nothing required, nothing recorded. */
    public function test_the_website_records_nothing_while_no_type_requires_acceptance(): void
    {
        $this->publishRequiredLegalDocuments(country: 'KW', reviewed: false);

        $orderId = $this->placeAWebOrderOn('KW', showed: ['terms', 'privacy']);

        $this->assertGreaterThan(0, $orderId, 'the order was placed');
        $this->assertSame([], $this->receipts(), 'the shipped configuration requires nothing, so the website is untouched');
    }

    /**
     * One receipt per order and per type, and not two.
     *
     * While the three API endpoints called the service themselves AND the order writer called it, every order of those three endpoints
     * got two identical receipts -- and the table has no unique key to stop it, because a receipt is written once and never updated. Two
     * rows saying the same thing is a corrupt record of consent, not a harmless duplicate: it is the document a regulator would be shown.
     * This is why the outer calls were removed rather than left as belt and braces.
     */
    public function test_one_order_and_one_type_write_exactly_one_receipt(): void
    {
        $this->requireAcceptanceOf('terms');
        $this->publishRequiredLegalDocuments(country: 'KW', reviewed: false);

        [, $orderId] = $this->placeAnOrderOn('KW', showed: ['terms']);

        $this->assertSame(
            1,
            StorefrontLegalAcceptance::query()->where(['order_id' => $orderId, 'document_type' => 'terms'])->count(),
            'exactly one receipt: a second caller writing the same row again is a duplicated record of consent',
        );
    }

    /**
     * A checkout that places no order writes no receipt.
     *
     * The receipt is written inside `generateOrder`'s transaction, so it cannot outlive the order it is about. Proven here from the
     * outside, which is what a test can see: a cart whose stock has gone places nothing, and leaves nothing behind claiming the customer
     * accepted terms for it. What this does NOT prove is the rollback of a refusal raised AFTER the order row was inserted -- that needs
     * a stock race this cannot force -- and the lexical position of the call is pinned instead by
     * {@see \Tests\Unit\Architecture\LegalReceiptHasOneWriterTest}.
     */
    public function test_a_checkout_that_places_no_order_leaves_no_receipt(): void
    {
        $this->requireAcceptanceOf('terms');
        $this->publishRequiredLegalDocuments(country: 'KW', reviewed: false);

        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: 'KW', productId: self::PRODUCT_OF['KW']);
        DB::table('products')->where('id', self::PRODUCT_OF['KW'])->update(['current_stock' => 0]);

        $this->asCustomer($customer)
            ->withHeader('X-Storefront', 'KW')
            ->withHeader('lang', 'en')
            ->getJson(self::PLACE_ORDER . '?' . http_build_query(['accepted_documents' => ['terms']]));

        $this->assertSame(0, DB::table('orders')->count(), 'nothing was placed');
        $this->assertSame([], $this->receipts(), 'and nothing records a consent for an order that does not exist');
    }
}
