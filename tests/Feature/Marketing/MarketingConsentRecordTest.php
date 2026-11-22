<?php

namespace Tests\Feature\Marketing;

use App\Models\CustomerMarketingConsent;
use App\Services\Marketing\MarketingConsent;
use App\Services\Marketing\MarketingConsentGate;
use App\Services\Marketing\MarketingConsentRecorder;
use App\Services\Marketing\MarketingSubject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The consent RECORD: what a row holds, and that nothing ever changes one.
 *
 * Covers A2-13 criterion 1 and criterion 2, with one limit stated here and in the pull request: the key proved is the
 * MARKET and the CHANNEL, not the storefront. main has no storefront layer — `orders.storefront_country` exists only
 * on an unmerged branch — so "per storefront" cannot be driven on this head. The row carries a nullable `storefront`
 * column so the shape satisfies criterion 1 and the storefront layer can fill it later; nothing fills it here.
 */
class MarketingConsentRecordTest extends MarketingConsentTestCase
{
    public function test_the_table_holds_every_field_the_criterion_names(): void
    {
        foreach (['customer_id', 'subject_hash', 'market', 'storefront', 'legal_entity_id', 'channel', 'purpose', 'decision', 'text_version', 'text_hash', 'source', 'recorded_at', 'created_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('customer_marketing_consents', $column), "customer_marketing_consents is missing {$column}");
        }

        // Append-only in the schema too: there is no updated_at for anything to be written back into.
        $this->assertFalse(Schema::hasColumn('customer_marketing_consents', 'updated_at'));
    }

    public function test_a_grant_records_the_customer_the_market_the_channel_the_purpose_the_text_version_and_the_time(): void
    {
        $customer = $this->makeCustomer(email: 'record@example.test');

        $row = app(MarketingConsentRecorder::class)->grantForCustomer(
            customerId: (int) $customer->id,
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
            source: MarketingConsent::SOURCE_REGISTRATION,
            textVersion: 'marketing-consent-v3',
            textHash: str_repeat('a', 64),
        );

        $this->assertSame((int) $customer->id, $row->customer_id);
        $this->assertSame(MarketingSubject::forCustomer(customerId: (int) $customer->id), $row->subject_hash);
        $this->assertSame('SA', $row->market);
        $this->assertSame(MarketingConsent::CHANNEL_EMAIL, $row->channel);
        $this->assertSame(MarketingConsent::PURPOSE_NEWSLETTER, $row->purpose);
        $this->assertSame('marketing-consent-v3', $row->text_version);
        $this->assertSame(str_repeat('a', 64), $row->text_hash);
        $this->assertSame(CustomerMarketingConsent::DECISION_GRANTED, $row->decision);
        $this->assertNotNull($row->recorded_at);

        // The storefront layer is not on this head; the column exists for it and is left alone.
        $this->assertNull($row->storefront);
        $this->assertNull($row->legal_entity_id);
    }

    public function test_the_subject_hash_is_not_the_customer_id_and_differs_between_customers(): void
    {
        $one = $this->makeCustomer(email: 'one@example.test');
        $other = $this->makeCustomer(email: 'other@example.test');

        $hash = MarketingSubject::forCustomer(customerId: (int) $one->id);

        // 64 lower-case hex characters, and nothing derivable from the id: not the id, not a hash of the id alone.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertNotSame((string) $one->id, $hash);
        $this->assertNotSame(hash('sha256', (string) $one->id), $hash);
        $this->assertNotSame(hash('sha256', 'customer:' . $one->id), $hash);
        $this->assertNotSame($hash, MarketingSubject::forCustomer(customerId: (int) $other->id));
    }

    public function test_withdrawing_writes_a_second_row_and_leaves_the_grant_exactly_as_it_was(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'withdraw@example.test');
        $recorder = app(MarketingConsentRecorder::class);

        $grant = $recorder->grantForCustomer(
            customerId: (int) $customer->id,
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
            source: MarketingConsent::SOURCE_REGISTRATION,
        );
        $grantAsStored = DB::table('customer_marketing_consents')->where('id', $grant->id)->first();

        $recorder->withdraw(
            subjectHash: MarketingSubject::forCustomer(customerId: (int) $customer->id),
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
            source: MarketingConsent::SOURCE_UNSUBSCRIBE_LINK,
            customerId: (int) $customer->id,
        );

        $this->assertSame(2, DB::table('customer_marketing_consents')->count());
        $this->assertEquals($grantAsStored, DB::table('customer_marketing_consents')->where('id', $grant->id)->first());

        $gate = app(MarketingConsentGate::class);
        $this->assertFalse($gate->allowsCustomer(
            customerId: (int) $customer->id,
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
        ));
    }

    public function test_a_recorded_decision_refuses_to_be_updated(): void
    {
        $row = app(MarketingConsentRecorder::class)->grantForEmail(
            email: 'immutable@example.test',
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
            source: MarketingConsent::SOURCE_NEWSLETTER_FORM,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be changed');

        $row->decision = CustomerMarketingConsent::DECISION_WITHDRAWN;
        $row->save();
    }

    public function test_a_recorded_decision_refuses_to_be_deleted(): void
    {
        $row = app(MarketingConsentRecorder::class)->grantForEmail(
            email: 'undeletable@example.test',
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
            source: MarketingConsent::SOURCE_NEWSLETTER_FORM,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be deleted');

        $row->delete();
    }

    /**
     * A2-13 criterion 2, as far as this head can prove it: withdrawing in ONE MARKET leaves the other markets alone.
     *
     * The criterion's word is "storefront". What is proved here is "market", because that is what main can resolve.
     */
    public function test_withdrawing_in_one_market_leaves_the_other_markets_consents_untouched(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'twomarkets@example.test');
        $recorder = app(MarketingConsentRecorder::class);
        $gate = app(MarketingConsentGate::class);

        foreach (['SA', 'AE', 'KW'] as $market) {
            $recorder->grantForCustomer(
                customerId: (int) $customer->id,
                market: $market,
                channel: MarketingConsent::CHANNEL_EMAIL,
                purpose: MarketingConsent::PURPOSE_NEWSLETTER,
                source: MarketingConsent::SOURCE_ACCOUNT_PAGE,
            );
        }

        $recorder->withdraw(
            subjectHash: MarketingSubject::forCustomer(customerId: (int) $customer->id),
            market: 'AE',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
            source: MarketingConsent::SOURCE_UNSUBSCRIBE_LINK,
            customerId: (int) $customer->id,
        );

        $this->assertTrue($gate->allowsCustomer(customerId: (int) $customer->id, market: 'SA', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_NEWSLETTER));
        $this->assertFalse($gate->allowsCustomer(customerId: (int) $customer->id, market: 'AE', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_NEWSLETTER));
        $this->assertTrue($gate->allowsCustomer(customerId: (int) $customer->id, market: 'KW', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_NEWSLETTER));
    }

    public function test_withdrawing_one_channel_leaves_the_other_channels_alone(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'twochannels@example.test');
        $recorder = app(MarketingConsentRecorder::class);
        $gate = app(MarketingConsentGate::class);

        foreach ([MarketingConsent::CHANNEL_EMAIL, MarketingConsent::CHANNEL_WHATSAPP] as $channel) {
            $recorder->grantForCustomer(
                customerId: (int) $customer->id,
                market: 'SA',
                channel: $channel,
                purpose: MarketingConsent::PURPOSE_CART_MARKETING,
                source: MarketingConsent::SOURCE_ACCOUNT_PAGE,
            );
        }

        $recorder->withdraw(
            subjectHash: MarketingSubject::forCustomer(customerId: (int) $customer->id),
            market: 'SA',
            channel: MarketingConsent::CHANNEL_WHATSAPP,
            purpose: MarketingConsent::PURPOSE_CART_MARKETING,
            source: MarketingConsent::SOURCE_UNSUBSCRIBE_LINK,
            customerId: (int) $customer->id,
        );

        $this->assertTrue($gate->allowsCustomer(customerId: (int) $customer->id, market: 'SA', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_CART_MARKETING));
        $this->assertFalse($gate->allowsCustomer(customerId: (int) $customer->id, market: 'SA', channel: MarketingConsent::CHANNEL_WHATSAPP, purpose: MarketingConsent::PURPOSE_CART_MARKETING));
    }

    public function test_a_person_may_agree_again_after_withdrawing_and_the_history_keeps_every_step(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'mindchanger@example.test');
        $recorder = app(MarketingConsentRecorder::class);
        $gate = app(MarketingConsentGate::class);
        $subject = MarketingSubject::forCustomer(customerId: (int) $customer->id);

        $recorder->grantForCustomer(customerId: (int) $customer->id, market: 'SA', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_NEWSLETTER, source: MarketingConsent::SOURCE_REGISTRATION);
        $recorder->withdraw(subjectHash: $subject, market: 'SA', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_NEWSLETTER, source: MarketingConsent::SOURCE_UNSUBSCRIBE_LINK, customerId: (int) $customer->id);
        $recorder->grantForCustomer(customerId: (int) $customer->id, market: 'SA', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_NEWSLETTER, source: MarketingConsent::SOURCE_ACCOUNT_PAGE);

        $this->assertSame(3, DB::table('customer_marketing_consents')->count());
        $this->assertSame(
            [CustomerMarketingConsent::DECISION_GRANTED, CustomerMarketingConsent::DECISION_WITHDRAWN, CustomerMarketingConsent::DECISION_GRANTED],
            DB::table('customer_marketing_consents')->orderBy('id')->pluck('decision')->all()
        );
        $this->assertTrue($gate->allowsCustomer(customerId: (int) $customer->id, market: 'SA', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_NEWSLETTER));
    }

    public function test_the_shipped_policy_is_opt_in_for_every_market(): void
    {
        $this->switchConsentOn();
        $gate = app(MarketingConsentGate::class);

        foreach (['SA', 'AE', 'KW', 'QA', 'BH', 'OM'] as $market) {
            $this->assertSame(MarketingConsent::POLICY_OPT_IN, $gate->policyFor(market: $market), "{$market} does not ship opt-in");
        }
    }

    public function test_a_market_can_be_turned_to_opt_out_by_configuration_alone(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'optout@example.test');
        $gate = app(MarketingConsentGate::class);

        $this->assertFalse($gate->allowsCustomer(customerId: (int) $customer->id, market: 'AE', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_NEWSLETTER));

        $this->setMarketPolicy(market: 'AE', policy: MarketingConsent::POLICY_OPT_OUT);

        $this->assertTrue($gate->allowsCustomer(customerId: (int) $customer->id, market: 'AE', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_NEWSLETTER));
        $this->assertFalse($gate->allowsCustomer(customerId: (int) $customer->id, market: 'SA', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_NEWSLETTER));
    }

    public function test_a_withdrawal_still_stops_a_send_in_an_opt_out_market(): void
    {
        $this->switchConsentOn();
        $this->setMarketPolicy(market: 'AE', policy: MarketingConsent::POLICY_OPT_OUT);
        $customer = $this->makeCustomer(email: 'optoutwithdraw@example.test');
        $gate = app(MarketingConsentGate::class);

        app(MarketingConsentRecorder::class)->withdraw(
            subjectHash: MarketingSubject::forCustomer(customerId: (int) $customer->id),
            market: 'AE',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
            source: MarketingConsent::SOURCE_UNSUBSCRIBE_LINK,
            customerId: (int) $customer->id,
        );

        $this->assertFalse($gate->allowsCustomer(customerId: (int) $customer->id, market: 'AE', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_NEWSLETTER));
    }

    public function test_the_migration_refuses_to_drop_a_table_that_holds_decisions(): void
    {
        app(MarketingConsentRecorder::class)->grantForEmail(
            email: 'evidence@example.test',
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
            source: MarketingConsent::SOURCE_NEWSLETTER_FORM,
        );

        try {
            $this->consentMigration()->down();
            $this->fail('down() dropped a table holding consent decisions.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing to drop', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('customer_marketing_consents'));
        $this->assertSame(1, DB::table('customer_marketing_consents')->count());
    }

    public function test_the_migration_drops_an_empty_table_and_can_be_run_again(): void
    {
        $this->consentMigration()->down();
        $this->assertFalse(Schema::hasTable('customer_marketing_consents'));

        $this->runConsentMigration();
        $this->assertTrue(Schema::hasTable('customer_marketing_consents'));

        // Guarded with hasTable, so running it twice is not an error.
        $this->runConsentMigration();
        $this->assertTrue(Schema::hasTable('customer_marketing_consents'));
    }
}
