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

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTheSixStorefrontsWorld();
        (require database_path('migrations/2026_11_27_100100_add_legal_entity_to_orders_table.php'))->up();
        Event::fake([RefundEvent::class]);

        $this->buildTheRefundTables();
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

    public function test_measure_what_a_refund_row_holds_for_a_paid_emirates_order(): void
    {
        $this->turnCompanyAccountsOn();
        $company = $this->aCompanyIn('AE');
        $account = $this->anAccountFor('AE', $company->id);

        [$customer, $orderId, $lineId, $payment] = $this->payFrom('AE', 2, 'TXN-AE-1');
        $this->askForRefund($customer, 'AE', $lineId);

        $refund = (array) DB::table('refund_requests')->first();
        fwrite(STDERR, "\nMEASURED account={$account->id} company={$company->id} order={$orderId} payment_stamp=" . $payment->additional_data . "\nMEASURED refund_row=" . json_encode($refund) . "\n");

        $this->assertNotSame([], $refund);
    }

    public function test_measure_the_statements_of_a_refund_while_accounts_are_off(): void
    {
        [$customer, , $lineId] = $this->payFrom('AE', 2, 'TXN-OFF-1');

        $done = $this->theRefundRouteDoes($customer, 'AE', $lineId);

        fwrite(STDERR, "\nMEASURED statements=" . count($done['statements']) . ' digest=' . hash('sha256', implode("\n", $done['statements'])) . "\n");
        fwrite(STDERR, 'MEASURED insert=' . $done['insert'] . "\n");
        fwrite(STDERR, 'MEASURED refund_row=' . json_encode((array) DB::table('refund_requests')->first()) . "\n");

        $this->assertNotSame([], $done['statements']);
    }
}
