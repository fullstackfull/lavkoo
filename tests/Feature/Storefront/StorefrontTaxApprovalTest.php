<?php

namespace Tests\Feature\Storefront;

use App\Exceptions\StorefrontNotSellableException;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontSellingGate;
use App\Services\Storefront\StorefrontTaxApproval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Modules\TaxModule\app\Models\SystemTaxSetup;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\Support\BuildsTaxModuleWorld;

/**
 * D1-07 and GCC-05: a storefront other than the default one does not sell until a person with the authority has approved the tax its orders carry, or has decided, in a
 * document, that they carry none. The Saudi setup is never a stand-in: a storefront with no decision cannot sell however complete its currency and legal texts are, even
 * while the platform has a Saudi setup with rates; one decided to charge no tax sells with no tax; and a decision stops counting when what the storefront is taxed with
 * changes. The service records what it is told and approves nothing itself. The rates in the tests are examples, not rules.
 */
class StorefrontTaxApprovalTest extends ApiAddressTestCase
{
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontMoneyWorld;
    use BuildsTaxModuleWorld;

    private const COD = '/api/v1/customer/order/place';

    private const KUWAITI_VAT = 4;

    private const OWNER = 'The finance director';

    private const DOCUMENT = 'Tax opinion KW-2026-11';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSixStorefrontCheckoutWorld();
        $this->createTaxModuleTables();
        $this->seedTheTaxRates();
        DB::table('taxes')->insert(['id' => self::KUWAITI_VAT, 'name' => 'VAT of Kuwait', 'tax_rate' => 5, 'is_active' => 1, 'is_default' => 1]);
        $this->forgetEveryTaxDecision();
    }

    /** The shared world opens every storefront for sale; this class tests the decision itself, so it starts with none on record. */
    private function forgetEveryTaxDecision(): void
    {
        DB::table('business_settings')->where('type', 'like', 'storefront_tax_approval_%')->delete();
        DB::table('audit_logs')->delete();
        clearWebConfigCacheKeys();
    }

    private function approval(): StorefrontTaxApproval
    {
        return app(StorefrontTaxApproval::class);
    }

    private function approveNoTax(string $country = 'KW'): array
    {
        return $this->approval()->approve(country: $country, decision: StorefrontTaxApproval::DECISION_NO_TAX, approvedBy: self::OWNER, reference: self::DOCUMENT);
    }

    private function approveSetup(string $country = 'KW'): array
    {
        return $this->approval()->approve(country: $country, decision: StorefrontTaxApproval::DECISION_SETUP, approvedBy: 'The tax adviser', reference: self::DOCUMENT);
    }

    private function statusOf(string $country = 'KW'): string
    {
        return $this->approval()->statusOf($country)['status'];
    }

    private function ownSetup(string $country = 'KW', array $taxIds = [self::KUWAITI_VAT], string $type = 'order_wise', bool $included = false): SystemTaxSetup
    {
        return $this->openTheTaxSetup(type: $type, taxIds: $taxIds, included: $included, country: $country);
    }

    private function checkOutFrom(string $country, int $productId): TestResponse
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();

        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: $productId);

        return $this->asCustomer($customer)->withHeader('X-Storefront', $country)->getJson(self::COD);
    }

    private function taxOfTheOrder(): float
    {
        return round((float) DB::table('orders')->value('total_tax_amount'), 3);
    }

    // ---- who needs a decision ------------------------------------------------------------------------------------------------------------

    public function test_the_default_storefront_needs_no_decision_and_cannot_be_given_one(): void
    {
        $this->assertSame(StorefrontTaxApproval::NOT_REQUIRED, $this->statusOf('SA'));
        $this->assertTrue($this->approval()->isApproved('SA'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SA is the default storefront: its tax is the platform\'s');
        $this->approveNoTax('SA');
    }

    public function test_a_storefront_with_no_decision_on_record_is_not_approved(): void
    {
        foreach (['AE', 'KW', 'QA', 'BH', 'OM'] as $country) {
            $this->assertSame(StorefrontTaxApproval::MISSING, $this->statusOf($country), $country);
            $this->assertFalse($this->approval()->isApproved($country), $country);
        }
    }

    public function test_a_country_that_is_no_configured_storefront_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('EG is not a configured storefront.');

        $this->approveNoTax('EG');
    }

    // ---- what a decision must say --------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function incompleteDecisions(): array
    {
        return [
            'no decision' => [['decision' => 'maybe', 'by' => self::OWNER, 'reference' => self::DOCUMENT], 'The decision must be setup'],
            'no approver' => [['decision' => 'no_tax', 'by' => '   ', 'reference' => self::DOCUMENT], 'Say who approved it'],
            'an approver far too long' => [['decision' => 'no_tax', 'by' => str_repeat('a', 192), 'reference' => self::DOCUMENT], 'Say who approved it'],
            'no document' => [['decision' => 'no_tax', 'by' => self::OWNER, 'reference' => ''], 'Say where the decision is written down'],
            'a document far too long' => [['decision' => 'no_tax', 'by' => self::OWNER, 'reference' => str_repeat('d', 192)], 'Say where the decision is written down'],
            'an assistant' => [['decision' => 'no_tax', 'by' => 'Claude', 'reference' => self::DOCUMENT], 'not a tool or an assistant'],
            'a pipeline' => [['decision' => 'no_tax', 'by' => 'CI pipeline', 'reference' => self::DOCUMENT], 'not a tool or an assistant'],
            'a workflow bot' => [['decision' => 'no_tax', 'by' => 'GitHub Actions', 'reference' => self::DOCUMENT], 'not a tool or an assistant'],
        ];
    }

    /** @param array<string, string> $given */
    #[DataProvider('incompleteDecisions')]
    public function test_a_decision_that_does_not_say_what_who_and_where_is_refused_and_nothing_is_recorded(array $given, string $message): void
    {
        try {
            $this->approval()->approve(country: 'KW', decision: $given['decision'], approvedBy: $given['by'], reference: $given['reference']);
            $this->fail('The decision should have been refused.');
        } catch (InvalidArgumentException $refusal) {
            $this->assertStringContainsString($message, $refusal->getMessage());
        }

        $this->assertSame(0, DB::table('business_settings')->where('type', 'like', 'storefront_tax_approval_%')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_a_person_whose_name_merely_contains_the_letters_of_a_tool_is_not_refused(): void
    {
        $record = $this->approval()->approve(country: 'KW', decision: StorefrontTaxApproval::DECISION_NO_TAX, approvedBy: 'Lisa Ciobanu, tax adviser', reference: self::DOCUMENT);

        $this->assertSame('Lisa Ciobanu, tax adviser', $record['approved_by']);
    }

    public function test_a_setup_can_be_approved_only_when_it_charges_something(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('KW has no tax setup of its own with a rate, so there are no rates to approve');

        $this->approveSetup('KW');
    }

    public function test_a_setup_with_no_rate_cannot_be_approved_as_a_setup_but_as_the_decision_that_there_is_no_tax(): void
    {
        $this->ownSetup(taxIds: []);

        try {
            $this->approveSetup('KW');
            $this->fail('A setup with no rate has no rates to approve.');
        } catch (InvalidArgumentException) {
            $this->assertSame(StorefrontTaxApproval::MISSING, $this->statusOf());
        }

        $this->approveNoTax('KW');

        $this->assertSame(StorefrontTaxApproval::APPROVED, $this->statusOf());
    }

    public function test_a_decision_of_no_tax_is_refused_for_a_storefront_that_charges_a_tax(): void
    {
        $this->ownSetup();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('KW has a tax setup that charges a tax');

        $this->approveNoTax('KW');
    }

    public function test_a_product_wise_setup_is_approved_as_a_setup_and_never_as_no_tax(): void
    {
        $this->ownSetup(taxIds: [], type: 'product_wise');

        try {
            $this->approveNoTax('KW');
            $this->fail('A setup that leaves its rates to each product is not a decision of no tax.');
        } catch (InvalidArgumentException) {
            $this->assertSame(StorefrontTaxApproval::MISSING, $this->statusOf());
        }

        $this->approveSetup('KW');

        $this->assertSame(StorefrontTaxApproval::APPROVED, $this->statusOf());
    }

    public function test_a_tax_on_shipping_alone_is_a_tax_and_not_no_tax(): void
    {
        $setup = $this->ownSetup(taxIds: []);
        DB::table('tax_additional_setups')->insert(['name' => 'shipping', 'system_tax_setup_id' => $setup->id, 'tax_ids' => json_encode([self::VAT]), 'is_active' => 1]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('KW has a tax setup that charges a tax');

        $this->approveNoTax('KW');
    }

    // ---- what is recorded ----------------------------------------------------------------------------------------------------------------

    public function test_the_decision_records_who_what_where_and_when_and_leaves_an_audit_line(): void
    {
        $this->ownSetup();

        $record = $this->approveSetup('KW');

        $this->assertSame('setup', $record['decision']);
        $this->assertSame(['The tax adviser', self::DOCUMENT], [$record['approved_by'], $record['reference']]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $record['approved_at']);
        $this->assertSame(64, strlen((string) $record['fingerprint']));
        $this->assertEquals($record, json_decode((string) DB::table('business_settings')->where('type', StorefrontTaxApproval::settingName('KW'))->value('value'), true));

        $line = DB::table('audit_logs')->where('action', 'storefront.tax_decision.approved')->first();
        $this->assertSame(['storefront', 'KW'], [$line->subject_type, $line->subject_id]);
        $this->assertEquals($record, json_decode((string) $line->after, true));
        $this->assertNull($line->before);
        $this->assertEquals([['VAT of Kuwait', 5]], array_map(static fn (array $rate): array => [$rate['name'], $rate['rate']], json_decode((string) $line->context, true)['taxed_with']['rates']));
    }

    public function test_a_decision_of_no_tax_carries_no_fingerprint(): void
    {
        $this->assertNull($this->approveNoTax('KW')['fingerprint']);
    }

    public function test_approving_again_replaces_the_decision_and_the_audit_line_keeps_the_one_before(): void
    {
        $first = $this->approveNoTax('KW');

        $second = $this->approval()->approve(country: 'KW', decision: StorefrontTaxApproval::DECISION_NO_TAX, approvedBy: 'The owner', reference: 'Minutes of 2026-12-01');

        $this->assertSame(1, DB::table('business_settings')->where('type', StorefrontTaxApproval::settingName('KW'))->count());
        $this->assertSame('The owner', $this->approval()->statusOf('KW')['approved_by']);
        $line = DB::table('audit_logs')->where('action', 'storefront.tax_decision.approved')->orderByDesc('id')->first();
        $this->assertEquals([$first, $second], [json_decode((string) $line->before, true), json_decode((string) $line->after, true)]);
    }

    public function test_a_decision_whose_audit_line_cannot_be_written_is_not_recorded(): void
    {
        Schema::drop('audit_logs');

        try {
            $this->approveNoTax('KW');
            $this->fail('A decision with no trace must not be recorded.');
        } catch (RuntimeException $failure) {
            $this->assertStringContainsString('audit_logs has not been migrated', $failure->getMessage());
        }

        $this->assertSame(0, DB::table('business_settings')->where('type', StorefrontTaxApproval::settingName('KW'))->count());
        $this->assertSame(StorefrontTaxApproval::MISSING, $this->statusOf());
    }

    public function test_a_decision_for_one_storefront_says_nothing_about_another(): void
    {
        $this->approveNoTax('KW');

        $this->assertSame([StorefrontTaxApproval::APPROVED, StorefrontTaxApproval::MISSING, StorefrontTaxApproval::MISSING], [$this->statusOf('KW'), $this->statusOf('AE'), $this->statusOf('QA')]);
    }

    // ---- when a decision stops counting --------------------------------------------------------------------------------------------------

    public function test_an_approved_setup_goes_stale_when_a_rate_the_type_the_included_flag_or_the_shipping_tax_changes_and_holds_again_when_they_are_put_back(): void
    {
        $setup = $this->ownSetup();
        $this->approveSetup('KW');
        $this->assertSame(StorefrontTaxApproval::APPROVED, $this->statusOf());

        DB::table('taxes')->where('id', self::KUWAITI_VAT)->update(['tax_rate' => 7]);
        $this->assertSame(StorefrontTaxApproval::STALE, $this->statusOf(), 'a rate');
        DB::table('taxes')->where('id', self::KUWAITI_VAT)->update(['tax_rate' => 5]);
        $this->assertSame(StorefrontTaxApproval::APPROVED, $this->statusOf(), 'the rate put back');

        $setup->update(['is_included' => 1]);
        $this->assertSame(StorefrontTaxApproval::STALE, $this->statusOf(), 'the included flag');
        $setup->update(['is_included' => 0]);

        $setup->update(['tax_ids' => [self::KUWAITI_VAT, self::MUNICIPALITY_FEE]]);
        $this->assertSame(StorefrontTaxApproval::STALE, $this->statusOf(), 'a second tax');
        $setup->update(['tax_ids' => [self::KUWAITI_VAT]]);

        $setup->update(['tax_type' => 'product_wise']);
        $this->assertSame(StorefrontTaxApproval::STALE, $this->statusOf(), 'the type');
        $setup->update(['tax_type' => 'order_wise']);

        DB::table('tax_additional_setups')->insert(['name' => 'shipping', 'system_tax_setup_id' => $setup->id, 'tax_ids' => json_encode([self::VAT]), 'is_active' => 1]);
        $this->assertSame(StorefrontTaxApproval::STALE, $this->statusOf(), 'a tax on shipping');
        DB::table('tax_additional_setups')->delete();

        $this->assertSame(StorefrontTaxApproval::APPROVED, $this->statusOf());
    }

    public function test_an_approved_setup_survives_a_row_made_again_with_the_same_rates_because_the_decision_is_about_values_and_not_ids(): void
    {
        $setup = $this->ownSetup();
        $this->approveSetup('KW');
        $copy = DB::table('taxes')->insertGetId(['name' => 'VAT of Kuwait', 'tax_rate' => 5, 'is_active' => 1, 'is_default' => 1]);

        $setup->update(['tax_ids' => [$copy]]);

        $this->assertSame(StorefrontTaxApproval::APPROVED, $this->statusOf());
    }

    public function test_an_approved_setup_goes_stale_when_it_is_switched_off_and_the_storefront_falls_back_to_nothing(): void
    {
        $setup = $this->ownSetup();
        $this->approveSetup('KW');

        $setup->update(['is_active' => 0]);

        $this->assertSame(StorefrontTaxApproval::STALE, $this->statusOf());
        $this->assertFalse($this->approval()->isApproved('KW'));
    }

    public function test_a_decision_of_no_tax_goes_stale_when_the_storefront_gets_a_rate_and_holds_again_when_it_loses_it(): void
    {
        $this->approveNoTax('KW');
        $this->assertSame(StorefrontTaxApproval::APPROVED, $this->statusOf());

        $setup = $this->ownSetup();
        $this->assertSame(StorefrontTaxApproval::STALE, $this->statusOf());

        $setup->update(['tax_ids' => []]);
        $this->assertSame(StorefrontTaxApproval::APPROVED, $this->statusOf());
    }

    public function test_the_platform_setup_changing_never_touches_a_storefronts_decision(): void
    {
        $platform = $this->openTheTaxSetup();
        $this->approveNoTax('KW');

        $platform->update(['tax_ids' => [self::VAT], 'is_included' => 1]);
        DB::table('taxes')->where('id', self::VAT)->update(['tax_rate' => 20]);

        $this->assertSame(StorefrontTaxApproval::APPROVED, $this->statusOf());
    }

    /** @return array<string, array{0: mixed}> */
    public static function rowsThatApproveNothing(): array
    {
        return [
            'not a decision' => [json_encode(['decision' => 'maybe', 'approved_by' => self::OWNER, 'reference' => self::DOCUMENT, 'approved_at' => '2026-11-18T10:00:00+00:00'])],
            'no approver' => [json_encode(['decision' => 'no_tax', 'approved_by' => '', 'reference' => self::DOCUMENT, 'approved_at' => '2026-11-18T10:00:00+00:00'])],
            'no document' => [json_encode(['decision' => 'no_tax', 'approved_by' => self::OWNER, 'approved_at' => '2026-11-18T10:00:00+00:00'])],
            'no date' => [json_encode(['decision' => 'no_tax', 'approved_by' => self::OWNER, 'reference' => self::DOCUMENT])],
            'a setup with no fingerprint' => [json_encode(['decision' => 'setup', 'approved_by' => self::OWNER, 'reference' => self::DOCUMENT, 'approved_at' => '2026-11-18T10:00:00+00:00'])],
            'a word' => ['approved'],
        ];
    }

    #[DataProvider('rowsThatApproveNothing')]
    public function test_a_row_that_is_not_a_complete_decision_approves_nothing(string $value): void
    {
        DB::table('business_settings')->insert(['type' => StorefrontTaxApproval::settingName('KW'), 'value' => $value]);
        clearWebConfigCacheKeys();

        $this->assertSame(StorefrontTaxApproval::INCOMPLETE, $this->statusOf());
        $this->assertFalse($this->approval()->isApproved('KW'));
    }

    // ---- withdrawing it ------------------------------------------------------------------------------------------------------------------

    public function test_a_decision_can_be_withdrawn_and_the_storefront_stops_being_approved(): void
    {
        $record = $this->approveNoTax('KW');

        $this->assertTrue($this->approval()->revoke(country: 'KW', revokedBy: 'The owner'));

        $this->assertSame(StorefrontTaxApproval::MISSING, $this->statusOf());
        $line = DB::table('audit_logs')->where('action', 'storefront.tax_decision.revoked')->first();
        $this->assertEquals([$record, 'The owner'], [json_decode((string) $line->before, true), json_decode((string) $line->context, true)['revoked_by']]);
    }

    public function test_withdrawing_a_decision_that_is_not_there_says_so_and_leaves_no_audit_line(): void
    {
        $this->assertFalse($this->approval()->revoke(country: 'KW'));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'storefront.tax_decision.revoked')->count());
    }

    public function test_the_default_storefront_has_no_decision_to_withdraw(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SA is the default storefront');

        $this->approval()->revoke(country: 'SA');
    }

    // ---- the gate ------------------------------------------------------------------------------------------------------------------------

    private function gate(): StorefrontSellingGate
    {
        return app(StorefrontSellingGate::class);
    }

    public function test_a_storefront_with_its_texts_and_its_currency_still_cannot_sell_without_a_tax_decision(): void
    {
        $this->assertFalse($this->gate()->maySell(country: 'KW', currencyCode: 'KWD'));

        $this->approveNoTax('KW');

        $this->assertTrue($this->gate()->maySell(country: 'KW', currencyCode: 'KWD'));
    }

    public function test_a_tax_decision_alone_opens_nothing_the_texts_and_the_currency_are_still_needed(): void
    {
        config(['gcc.storefronts.legal.grandfathered' => ['SA']]);
        $this->approveNoTax('KW');

        $this->assertFalse($this->gate()->maySell(country: 'KW', currencyCode: 'KWD'), 'no approved legal texts');

        config(['gcc.storefronts.legal.grandfathered' => ['SA', 'KW']]);
        DB::table('currencies')->where('code', 'KWD')->delete();
        cache()->forget('active_currency_list');

        $this->assertFalse($this->gate()->maySell(country: 'KW', currencyCode: 'KWD'), 'no currency');
    }

    public function test_the_gate_refuses_with_the_not_sellable_error_and_the_stale_decision_closes_it_again(): void
    {
        $setup = $this->ownSetup();
        $this->approveSetup('KW');
        $context = new StorefrontContext(country: 'KW', currency: 'KWD', storefrontsEnabled: true);
        $this->gate()->assertMaySell($context);

        $setup->update(['is_included' => 1]);

        $this->expectException(StorefrontNotSellableException::class);
        $this->gate()->assertMaySell($context);
    }

    public function test_the_default_storefront_and_a_platform_with_storefronts_off_are_never_asked_for_a_decision(): void
    {
        $this->assertTrue($this->gate()->maySell(country: 'SA', currencyCode: 'SAR'));

        config(['gcc.storefronts.enabled' => false]);

        $this->assertTrue($this->gate()->maySell(country: 'KW', currencyCode: 'KWD'));
    }

    public function test_the_storefront_list_says_a_storefront_cannot_sell_until_its_tax_is_decided_and_again_when_the_decision_is_withdrawn(): void
    {
        $listed = fn (): array => collect($this->getJson('/api/v1/storefronts')->assertOk()->json('storefronts'))->pluck('sellable', 'country')->all();

        $this->assertFalse($listed()['KW']);

        $this->approveNoTax('KW');
        $this->assertTrue($listed()['KW']);
        $this->assertTrue($listed()['SA']);

        $this->approval()->revoke(country: 'KW');
        $this->assertFalse($listed()['KW']);
    }

    // ---- the checkout --------------------------------------------------------------------------------------------------------------------

    public function test_a_checkout_from_a_storefront_with_no_tax_decision_is_refused_even_though_the_platform_has_a_saudi_setup(): void
    {
        $this->openTheTaxSetup();

        $response = $this->checkOutFrom('KW', 3);

        $response->assertStatus(422)->assertJsonPath('code', 'storefront_not_sellable');
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_a_storefront_decided_to_charge_no_tax_sells_with_no_tax_and_never_with_the_saudi_rates(): void
    {
        $this->openTheTaxSetup();
        $this->approveNoTax('KW');

        $this->checkOutFrom('KW', 3)->assertOk();

        $this->assertSame(0.0, $this->taxOfTheOrder());
        $this->assertSame(0, DB::table('order_taxes')->count());
        $this->assertSame(30.0, round((float) DB::table('orders')->value('order_amount'), 3));
    }

    public function test_the_default_storefront_keeps_the_platform_rates_whatever_the_other_storefronts_are_decided(): void
    {
        $this->openTheTaxSetup();
        $this->approveNoTax('KW');

        $this->checkOutFrom('SA', 1)->assertOk();

        $this->assertSame(20.0, $this->taxOfTheOrder());
    }

    public function test_a_storefront_with_approved_rates_sells_with_them_until_they_change(): void
    {
        $this->openTheTaxSetup();
        $this->ownSetup();
        $this->approveSetup('KW');

        $this->checkOutFrom('KW', 3)->assertOk();
        $this->assertSame(1.5, $this->taxOfTheOrder());

        DB::table('taxes')->where('id', self::KUWAITI_VAT)->update(['tax_rate' => 7]);

        $this->checkOutFrom('KW', 3)->assertStatus(422)->assertJsonPath('code', 'storefront_not_sellable');
        $this->assertSame(1, DB::table('orders')->count(), 'the second order was never placed');
    }

    public function test_a_withdrawn_decision_stops_the_next_checkout_and_leaves_the_order_already_placed(): void
    {
        $this->approveNoTax('KW');
        $this->checkOutFrom('KW', 3)->assertOk();

        $this->approval()->revoke(country: 'KW');

        $this->checkOutFrom('KW', 3)->assertStatus(422);
        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_the_wallet_and_the_payment_routes_ask_the_same_question(): void
    {
        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: 'KW', productId: 3);

        foreach (['/api/v1/customer/order/place-by-wallet', '/api/v1/customer/order/place'] as $route) {
            $this->asCustomer($customer)->withHeader('X-Storefront', 'KW')->getJson($route)->assertStatus(422)->assertJsonPath('code', 'storefront_not_sellable');
        }
    }
}
