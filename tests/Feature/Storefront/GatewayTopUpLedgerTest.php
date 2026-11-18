<?php

namespace Tests\Feature\Storefront;

use App\Events\AddFundToWalletEvent;
use App\Models\PaymentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsLedgerWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * E-05 criteria 1 and 5 for a gateway top-up, through the success hook every gateway calls (add_fund_to_wallet_success): the ledger row names the payment, and carries the
 * storefront the payment was started in and the currency it was taken in. The gateway reports back in a request that names no storefront, so what the request says is never
 * what is recorded. The credit itself, its reference and its payment method are what they were.
 */
class GatewayTopUpLedgerTest extends TestCase
{
    use BuildsLedgerWorld;
    use RestoresLanguageFiles;

    private const PAYMENT = '5b1c1c9a-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildLedgerWorld();
        $this->ledgerCustomer(id: 1, wallet: 50.0);
        Event::fake([AddFundToWalletEvent::class]);
    }

    protected function tearDown(): void
    {
        $this->dropLedgerWorld();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @param array<string, mixed> $additionalData */
    private function aPaidTopUp(array $additionalData, string $currency = 'SAR', float $amount = 25.0): PaymentRequest
    {
        DB::table('payment_requests')->insert([
            'id' => self::PAYMENT, 'payer_id' => '1', 'payment_amount' => $amount, 'currency_code' => $currency, 'payment_method' => 'my_fatoorah',
            'additional_data' => json_encode($additionalData + ['payment_mode' => 'web']), 'is_paid' => true,
        ]);

        return PaymentRequest::findOrFail(self::PAYMENT);
    }

    public function test_the_credit_of_a_kuwaiti_top_up_names_its_payment_its_storefront_its_currency_and_its_company(): void
    {
        $entity = $this->registerEntity(country: 'KW');
        $this->switchMarketsOn(currency: true, entities: true);
        $payment = $this->aPaidTopUp(additionalData: ['storefront' => 'KW'], currency: 'KWD');

        add_fund_to_wallet_success($payment);

        $row = $this->newestLedgerRow();
        $this->assertSame(['payment', self::PAYMENT, 'KW', 'KWD', $entity->id], [$row['source_type'], $row['source_id'], $row['storefront_country'], $row['currency_code'], $row['legal_entity_id']]);
        $this->assertSame([null, null], [$row['actor_type'], $row['actor_id']], 'a gateway is not a staff member');
        $this->assertSame(['add_fund', 'add_funds_to_wallet', 'my_fatoorah', 25.0], [$row['transaction_type'], $row['reference'], $row['payment_method'], $row['credit']]);
        $this->assertEqualsWithDelta(75.0, (float) DB::table('users')->where('id', 1)->value('wallet_balance'), 0.0001);
    }

    public function test_the_request_the_gateway_calls_back_with_never_decides_the_storefront(): void
    {
        $this->switchMarketsOn(currency: true, entities: true);
        $this->registerEntity(country: 'SA');
        $payment = $this->aPaidTopUp(additionalData: ['storefront' => 'KW'], currency: 'KWD');

        $this->servedFrom(country: 'SA', callback: fn () => add_fund_to_wallet_success($payment));

        $this->assertSame('KW', $this->newestLedgerRow()['storefront_country'], 'the callback names no storefront, so the default one is bound; the payment says Kuwait');
    }

    public function test_a_top_up_started_while_storefronts_were_off_records_its_payment_and_no_market_even_if_they_are_on_when_it_is_credited(): void
    {
        $this->switchMarketsOn(currency: true, entities: true);
        $this->registerEntity(country: 'SA');
        $payment = $this->aPaidTopUp(additionalData: []);

        $this->servedFrom(country: 'SA', callback: fn () => add_fund_to_wallet_success($payment));

        $row = $this->newestLedgerRow();
        $this->assertSame(['payment', self::PAYMENT, null, null, null], [$row['source_type'], $row['source_id'], $row['storefront_country'], $row['currency_code'], $row['legal_entity_id']]);
    }

    public function test_a_saudi_top_up_with_every_switch_off_is_what_it_always_was_and_names_its_payment(): void
    {
        $payment = $this->aPaidTopUp(additionalData: []);

        add_fund_to_wallet_success($payment);

        $row = $this->newestLedgerRow();
        $this->assertSame(
            ['payment', self::PAYMENT, null, null, null, null, null],
            [$row['source_type'], $row['source_id'], $row['storefront_country'], $row['currency_code'], $row['legal_entity_id'], $row['actor_type'], $row['actor_id']],
        );
        $this->assertSame(
            ['user_id' => 1, 'transaction_id' => '<uuid>', 'credit' => 25.0, 'debit' => 0.0, 'admin_bonus' => 0.0, 'balance' => 75.0, 'transaction_type' => 'add_fund', 'payment_method' => 'my_fatoorah', 'reference' => 'add_funds_to_wallet', 'created_at' => '2026-12-05 10:00:00', 'updated_at' => '2026-12-05 10:00:00'],
            array_diff_key($row, array_flip(self::LEDGER_COLUMNS)),
        );
    }

    public function test_a_payment_that_is_not_paid_credits_nothing_and_records_nothing(): void
    {
        $payment = $this->aPaidTopUp(additionalData: []);
        $payment->update(['is_paid' => false]);

        add_fund_to_wallet_success($payment->fresh());

        $this->assertSame(0, DB::table('wallet_transactions')->count());
    }

    public function test_before_the_migration_the_credit_is_written_exactly_as_it_always_was(): void
    {
        $this->dropLedgerWorld();
        $this->buildLedgerWorld(attributed: false);
        $this->ledgerCustomer(id: 1, wallet: 50.0);
        $this->switchMarketsOn(currency: true, entities: true);
        $payment = $this->aPaidTopUp(additionalData: ['storefront' => 'KW'], currency: 'KWD');

        add_fund_to_wallet_success($payment);

        $this->assertSame([], array_intersect(self::LEDGER_COLUMNS, Schema::getColumnListing('wallet_transactions')), 'the migration has not run');
        $this->assertSame(
            ['user_id' => 1, 'transaction_id' => '<uuid>', 'credit' => 25.0, 'debit' => 0.0, 'admin_bonus' => 0.0, 'balance' => 75.0, 'transaction_type' => 'add_fund', 'payment_method' => 'my_fatoorah', 'reference' => 'add_funds_to_wallet', 'created_at' => '2026-12-05 10:00:00', 'updated_at' => '2026-12-05 10:00:00'],
            $this->newestLedgerRow(),
        );
    }
}
