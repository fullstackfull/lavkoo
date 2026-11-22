<?php

namespace Tests\Feature\Storefront;

use App\Events\RefundEvent;
use App\Models\LegalEntity;
use App\Models\PaymentGatewayAccount;
use App\Models\User;
use App\Services\Payments\GatewayAccountRegistry;
use App\Services\Payments\GatewayAccountSecrets;
use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsStorefrontRefundWorld;

/**
 * D1-10, criterion 1 and the recording half of criterion 4: a refund row names the gateway account the original payment was taken through and the company
 * that took it, read from the stamp the payment carries and never worked out again from the settings of the day.
 *
 * Everything the assertions read is written by the application: the stamp by App\Services\Payments\PaymentAccountBinding from inside the payment the
 * customer starts, the order and its reference by the gateway's own success hook, and the refund row by the route the app calls. The one step a test
 * cannot obtain is the gateway saying the money arrived, so the payment is marked paid exactly as every gateway controller marks it
 * (App\Http\Controllers\Payment_Methods\PaymobController: payment_method, is_paid, transaction_id) and nothing else is written by hand.
 */
class RefundNamesThePaymentAccountTest extends ApiAddressTestCase
{
    use BuildsStorefrontRefundWorld;

    private const GATEWAY = 'paymob_accept';

    private const START_PAYMENT = '/api/v1/digital-payment';

    /**
     * What the refund route did on the base of this branch (edfefa0b, the merge of pull requests 103 and 152) for a paid order whose payment carries no
     * stamp, measured through these same routes before a line of this change was written, with the quoting of identifiers taken off so the figures read
     * the same on both engines.
     *
     * The dormant path has to still do exactly this: the same work in the same order, the same writes, and the refund row made of the same values. The
     * only statements this change may add are read-only questions to the database about its own schema -- whether the two new columns are there -- which
     * are left out of the comparison and counted separately.
     */
    private const BASE_WORK = 20;

    private const BASE_WORK_DIGEST = '81a0cbb94f130341a1712e41e0f3a2b720defaeda6e6ef047ba872dd62e84b2a';

    private const BASE_WRITES = [
        'insert into refund_requests (order_details_id, customer_id, status, amount, product_id, order_id, refund_reason, storefront_country, currency_code, currency_rate, updated_at, created_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'update order_details set refund_request = ?, updated_at = ? where id = ?',
    ];

    private const BASE_INSERT = 'insert into refund_requests (order_details_id, customer_id, status, amount, product_id, order_id, refund_reason, storefront_country, currency_code, currency_rate, updated_at, created_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) -- [1,1,pending,250,2,100001,wrong item,AE,AED,1,<moment>,<moment>]';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTheSixStorefrontsWorld();
        (require database_path('migrations/2026_11_27_100100_add_legal_entity_to_orders_table.php'))->up();
        Event::fake([RefundEvent::class]);

        $this->buildTheRefundTables();
        (require database_path('migrations/2026_12_04_100000_add_payment_account_to_refund_tables.php'))->up();
        $this->installTheCompanyAndAccountTables();
    }

    protected function tearDown(): void
    {
        $this->dropTheRefundTables();
        Schema::dropIfExists('payment_gateway_accounts');
        Schema::dropIfExists('legal_entities');
        $this->forgetWhatWasRemembered();

        parent::tearDown();
    }

    private function installTheCompanyAndAccountTables(): void
    {
        Schema::dropIfExists('payment_gateway_accounts');
        Schema::dropIfExists('legal_entities');

        foreach (['2026_11_27_100000_create_legal_entities_table.php', '2026_11_28_100000_create_payment_gateway_accounts_table.php'] as $migration) {
            (require database_path('migrations/' . $migration))->up();
        }
    }

    private function forgetWhatWasRemembered(): void
    {
        StorefrontCatalog::flush();
        app(LegalEntityRegistry::class)->flush();
        app(GatewayAccountRegistry::class)->flush();
    }

    /** Per-company gateway accounts switched on for Paymob, which is off by default (GCC_PAYMENT_ACCOUNTS). */
    private function turnCompanyAccountsOn(): void
    {
        config([
            'gcc.storefronts.legal_entities.enabled' => true,
            'gcc.storefronts.legal_entities.required_to_sell' => false,
            'gcc.storefronts.payment_accounts.enabled' => true,
            'gcc.storefronts.payment_accounts.gateways' => [self::GATEWAY],
        ]);
        $this->forgetWhatWasRemembered();
    }

    private function aCompanyIn(string $country): LegalEntity
    {
        $entity = LegalEntity::create([
            'country' => $country,
            'legal_name' => "Lavkoo {$country} Trading Co.",
            'trade_name' => "Lavkoo {$country}",
            'tax_registration_number' => "TAX-{$country}-001",
            'commercial_registration_number' => "CR-{$country}-777",
            'invoice_series_key' => "INV-{$country}",
            'address' => "1 Example Street, {$country}",
            'status' => true,
        ]);
        $this->forgetWhatWasRemembered();

        return $entity;
    }

    private function anAccountFor(string $country, ?int $company, bool $active = true): PaymentGatewayAccount
    {
        $account = PaymentGatewayAccount::create([
            'storefront_country' => $country,
            'gateway' => self::GATEWAY,
            'environment' => 'test',
            'legal_entity_id' => $company,
            'label' => "{$country} paymob test",
            'webhook_path_token' => Str::random(48),
            'status' => $active,
        ]);

        app(GatewayAccountSecrets::class)->put(account: $account, secrets: ['secret_key' => "sk-{$country}", 'integration_id' => '2222', 'public_key' => "pk-{$country}", 'hmac' => "hmac-{$country}"]);
        $this->forgetWhatWasRemembered();

        return $account->refresh();
    }

    /**
     * A paid order of that storefront, placed the way the app places one: the customer fills a cart, starts a real gateway payment (which is where the
     * stamp is written), the gateway reports the money arrived, and the success hook builds the order with the payment's reference on it.
     *
     * @return array{0: User, 1: int, 2: int, 3: object} who ordered, the order, its line and the payment row
     */
    private function payFrom(string $country, int $productId, string $transaction): array
    {
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, $country, $productId);

        $this->asCustomer($customer)->withHeader('X-Storefront', $country)
            ->postJson(self::START_PAYMENT, ['payment_method' => self::GATEWAY, 'payment_platform' => 'app', 'payment_request_from' => 'app', 'customer_id' => $customer->id, 'is_guest' => 0])
            ->assertOk();

        $payment = DB::table('payment_requests')->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();

        // What every gateway controller writes when the gateway says the money arrived, and the only line of this test the application does not write.
        DB::table('payment_requests')->where('id', $payment->id)->update(['payment_method' => self::GATEWAY, 'is_paid' => 1, 'transaction_id' => $transaction]);
        $payment = DB::table('payment_requests')->where('id', $payment->id)->first();

        request()->replace([]);
        digital_payment_success(['is_paid' => 1, 'payment_method' => self::GATEWAY, 'transaction_id' => $transaction, 'additional_data' => $payment->additional_data]);

        $orderId = (int) DB::table('orders')->where('customer_id', $customer->id)->value('id');

        return [$customer, $orderId, (int) DB::table('order_details')->where('order_id', $orderId)->value('id'), $payment];
    }

    /** The statement with the quoting of its identifiers taken off, so the same statement reads the same on SQLite and on MySQL. */
    private static function plain(string $sql): string
    {
        return str_replace(['"', '`'], '', $sql);
    }

    /** A read-only question to the database about its own schema, which is what Schema::hasColumn asks and what this change asks two more of. */
    private static function isASchemaQuestion(string $sql): bool
    {
        return preg_match('/sqlite_master|pragma_|information_schema|^\\s*show (columns|tables|full columns)/i', $sql) === 1;
    }

    /** @param list<string> $statements @return list<string> what the route does to the data, with the schema questions left out */
    private function workAmong(array $statements): array
    {
        return array_values(array_map(self::plain(...), array_filter($statements, static fn (string $sql): bool => !self::isASchemaQuestion($sql))));
    }

    /** @param list<string> $statements @return list<string> the statements that change the database */
    private function writesAmong(array $statements): array
    {
        return array_values(array_filter($this->workAmong($statements), static fn (string $sql): bool => preg_match('/^\\s*(insert|update|delete|replace|alter|create|drop|truncate)\\b/i', $sql) === 1));
    }

    /**
     * Every statement the refund route issues, as the text of the statement, and the write that makes the refund row with its values.
     *
     * The statement text carries no clock and no identifier, so two runs of the same code give the same list byte for byte; the one write that
     * matters is kept with its values, with the moments masked, so that what the row is made of can be compared too.
     *
     * @return array{statements: list<string>, insert: string}
     */
    private function theRefundRouteDoes(User $customer, string $country, int $lineId): array
    {
        $statements = [];
        $insert = '';
        DB::listen(static function ($query) use (&$statements, &$insert): void {
            $statements[] = $query->sql;

            if (str_contains($query->sql, 'insert into "refund_requests"') || str_contains($query->sql, 'insert into `refund_requests`')) {
                $insert = $query->sql . ' -- ' . preg_replace('/\\d{4}-\\d{2}-\\d{2}[ T]\\d{2}:\\d{2}:\\d{2}/', '<moment>', json_encode($query->bindings));
            }
        });

        $this->askForRefund($customer, $country, $lineId);

        return ['statements' => $statements, 'insert' => $insert];
    }

    /** What the base writes, and what this change writes, for the same paid order through the same routes. */
    public function test_a_refund_of_a_paid_order_names_the_account_and_the_company_that_took_the_payment(): void
    {
        $this->turnCompanyAccountsOn();
        $company = $this->aCompanyIn('AE');
        $account = $this->anAccountFor('AE', $company->id);

        [$customer, $orderId, $lineId] = $this->payFrom('AE', 2, 'TXN-AE-1');
        $this->askForRefund($customer, 'AE', $lineId);

        $refund = DB::table('refund_requests')->first();
        $this->assertSame(
            [$account->id, $company->id],
            [(int) $refund->payment_gateway_account_id, (int) $refund->payment_legal_entity_id],
            'the refund names the merchant account the money was taken through and the company that took it',
        );
        $this->assertSame(['AE', 'AED', $orderId], [$refund->storefront_country, $refund->currency_code, (int) $refund->order_id]);
        $this->assertEquals(250, $refund->amount, 'the amount is the order line\'s, as it always was');
    }

    /** The account is read from the payment, so the refund still names the account that took the money after that account row has been replaced by another. */
    public function test_the_refund_names_the_account_that_took_the_money_after_it_was_replaced(): void
    {
        $this->turnCompanyAccountsOn();
        $company = $this->aCompanyIn('AE');
        $took = $this->anAccountFor('AE', $company->id);

        [$customer, , $lineId] = $this->payFrom('AE', 2, 'TXN-AE-2');

        $took->delete();
        $another = $this->anAccountFor('AE', $company->id);
        $this->assertNotSame($took->id, $another->id, 'the market is served by a different account row now');

        $this->askForRefund($customer, 'AE', $lineId);

        $refund = DB::table('refund_requests')->first();
        $this->assertSame($took->id, (int) $refund->payment_gateway_account_id, 'the account the payment was taken through, not the one serving the market now');
        $this->assertSame($company->id, (int) $refund->payment_legal_entity_id);
    }

    /**
     * The company that sold and the company whose merchant account took the money are two facts, so they are two columns.
     *
     * They come apart as soon as one company takes the money of more than one market, which is what a gateway account registered against another
     * country's company is: the Emirates storefront sells as the Emirates company and its Paymob account is the Kuwaiti company's.
     */
    public function test_the_company_that_sold_and_the_company_that_took_the_money_are_both_kept(): void
    {
        $this->turnCompanyAccountsOn();
        $sold = $this->aCompanyIn('AE');
        $tookTheMoney = $this->aCompanyIn('KW');
        $this->anAccountFor('AE', $tookTheMoney->id);

        [$customer, $orderId, $lineId] = $this->payFrom('AE', 2, 'TXN-AE-3');
        DB::table('orders')->where('id', $orderId)->update(['legal_entity_id' => $sold->id]);

        $this->askForRefund($customer, 'AE', $lineId);

        $refund = DB::table('refund_requests')->first();
        $this->assertSame($sold->id, (int) $refund->legal_entity_id, 'the company that sold the order');
        $this->assertSame($tookTheMoney->id, (int) $refund->payment_legal_entity_id, 'the company whose merchant account took the money');
        $this->assertNotSame((int) $refund->legal_entity_id, (int) $refund->payment_legal_entity_id);
    }

    /**
     * The dormant path, and the common one: a payment carrying no stamp. The refund route must do what it did before this change, to the statement, so
     * the whole list of statements it issues and the values it writes the row with are compared with what the base issued and wrote, not described.
     */
    public function test_a_refund_of_a_payment_with_no_stamp_records_nothing_and_does_exactly_what_the_base_did(): void
    {
        [$customer, , $lineId] = $this->payFrom('AE', 2, 'TXN-OFF-1');

        $done = $this->theRefundRouteDoes($customer, 'AE', $lineId);

        $work = $this->workAmong($done['statements']);
        $this->assertSame(self::BASE_WORK, count($work), 'the refund route does the work the base did, and no more');
        $this->assertSame(self::BASE_WORK_DIGEST, hash('sha256', implode("\n", $work)), 'every statement of the refund route that touches the data, in order, is the one the base issued');
        $this->assertSame(self::BASE_WRITES, $this->writesAmong($done['statements']), 'the refund route writes what the base wrote, and nothing else');
        $this->assertSame(self::BASE_INSERT, self::plain($done['insert']), 'the write that makes the refund row names the columns the base named, with the values the base wrote');
        $this->assertSame([], array_values(array_filter($done['statements'], static fn (string $sql): bool => str_contains($sql, 'payment_requests'))), 'while company accounts are off the payments table is never asked');

        $refund = DB::table('refund_requests')->first();
        $this->assertSame([null, null], [$refund->payment_gateway_account_id, $refund->payment_legal_entity_id]);
        $this->assertEquals(250, $refund->amount);
    }

    /** A cash-on-delivery order never had a payment, which is most of them: the refund is made as it always was. */
    public function test_a_cash_on_delivery_refund_names_no_account(): void
    {
        $this->turnCompanyAccountsOn();
        $this->anAccountFor('AE', $this->aCompanyIn('AE')->id);

        [$customer, , $lineId] = $this->placeFrom('AE', 2);
        $this->askForRefund($customer, 'AE', $lineId);

        $refund = DB::table('refund_requests')->first();
        $this->assertSame([null, null], [$refund->payment_gateway_account_id, $refund->payment_legal_entity_id]);
        $this->assertEquals(250, $refund->amount, 'the amount of the line, as it always was');
    }

    /** A payment that was stamped, refunded in a shop that has not run this migration: the columns are not there and the row is made without them. */
    public function test_a_refund_before_the_migration_is_made_without_the_columns(): void
    {
        $this->turnCompanyAccountsOn();
        $this->anAccountFor('AE', $this->aCompanyIn('AE')->id);
        (require database_path('migrations/2026_12_04_100000_add_payment_account_to_refund_tables.php'))->down();

        [$customer, , $lineId] = $this->payFrom('AE', 2, 'TXN-AE-4');
        $this->askForRefund($customer, 'AE', $lineId);

        $this->assertSame(1, DB::table('refund_requests')->count());
        $this->assertFalse(Schema::hasColumn('refund_requests', 'payment_gateway_account_id'));
    }
}
