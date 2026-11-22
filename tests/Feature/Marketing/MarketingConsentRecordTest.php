<?php

namespace Tests\Feature\Marketing;

use App\Models\CustomerMarketingConsent;
use App\Services\Marketing\MarketingConsent;
use App\Services\Marketing\MarketingConsentGate;
use App\Services\Marketing\MarketingConsentRecorder;
use App\Services\Marketing\MarketingSubject;
use Illuminate\Support\Carbon;
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

    /**
     * THE SHAPE, not only the names. Column presence was all that was asserted, so a mutation widening `market` from
     * char(2) to a 32-character string left all 90 tests green, and neither index was covered at all.
     *
     * The index names are checked on both engines; the widths only where a width survives the round trip, because
     * SQLite answers "varchar" for every string column whatever the migration asked for. MySQL 8 and MariaDB 11.4.2
     * run this group in CI, and the strict-mode job now does too.
     */
    public function test_the_columns_are_the_width_the_record_needs_and_both_indexes_are_there(): void
    {
        $indexes = array_map(
            static fn (array $index): string => $index['name'] . ':' . implode(',', $index['columns']),
            Schema::getIndexes('customer_marketing_consents')
        );
        sort($indexes);

        $this->assertSame([
            'marketing_consents_customer_idx:customer_id,market,channel,purpose',
            'marketing_consents_subject_idx:subject_hash,market,channel,purpose',
            'primary:id',
        ], $indexes);

        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        $columns = collect(Schema::getColumns('customer_marketing_consents'))->keyBy('name');
        $this->assertSame('char(2)', $columns['market']['type'], 'A wider market column stops a market being one spelling.');
        $this->assertSame('char(64)', $columns['subject_hash']['type']);
        $this->assertSame('varchar(32)', $columns['channel']['type']);
        $this->assertSame('varchar(32)', $columns['purpose']['type']);
        $this->assertSame('varchar(16)', $columns['decision']['type']);
    }

    /**
     * `granted()` means granted, not "not withdrawn".
     *
     * Undiscriminable until there was a third decision value: both reviews inverted the predicate to
     * `!== DECISION_WITHDRAWN` and all 90 tests stayed green, because only two values were ever written. An
     * unverified claim is now the third, and it must not read as permission — nor as a suppression.
     */
    public function test_an_unverified_claim_is_neither_a_grant_nor_a_refusal(): void
    {
        $this->switchConsentOn();
        $question = ['email' => 'claimed@example.test', 'market' => 'SA', 'channel' => MarketingConsent::CHANNEL_EMAIL, 'purpose' => MarketingConsent::PURPOSE_NEWSLETTER];
        $claim = app(MarketingConsentRecorder::class)->claimForEmail(...[...$question, 'source' => MarketingConsent::SOURCE_NEWSLETTER_FORM]);

        $this->assertFalse($claim->granted());
        $this->assertFalse(app(MarketingConsentGate::class)->allowsEmail(...$question));

        // And the market's policy still answers, exactly as it did before anybody typed the address in.
        $this->setMarketPolicy('SA', MarketingConsent::POLICY_OPT_OUT);
        $this->assertTrue(app(MarketingConsentGate::class)->allowsEmail(...$question));
    }

    /**
     * An account and an address are different subjects even when they look alike.
     *
     * A mutation hashing "email:<id>" for a customer stayed green: the existing test only proved the hash is keyed and
     * differs between customers, which that spelling satisfies too.
     */
    public function test_a_customer_and_an_address_are_never_the_same_subject(): void
    {
        $this->assertNotSame(
            MarketingSubject::forCustomer(customerId: 7),
            MarketingSubject::forEmail(email: '7'),
            'The namespace is what keeps an account and an address apart.'
        );
    }

    public function test_a_grant_records_the_customer_the_market_the_channel_the_purpose_the_text_version_and_the_time(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01 10:20:30'));
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
        // Not assertNotNull: a mutation writing a time five years wrong stayed green. "and time" in the criterion
        // means the time the person answered.
        $this->assertSame('2026-07-01 10:20:30', $row->recorded_at->toDateTimeString(), 'The recorded time is not when the answer was given.');

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

    /**
     * THE RECORDER'S OWN HISTORY, built by calling the recorder — which is what this test is, and all it is.
     *
     * An independent review was right that as a claim about the APPLICATION it proves nothing: it constructs the state
     * it reads. It stays, renamed for what it actually covers, because the append-only history is the recorder's job.
     * Whether a person can get back in through a screen is driven through the real routes by
     * NewsletterConsentTest::test_a_customer_may_agree_again_after_unsubscribing_through_the_link, which did not exist
     * when this was written and which was RED until the controller was fixed.
     */
    public function test_the_recorder_keeps_every_step_when_a_decision_is_reversed_and_made_again(): void
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

    /**
     * A market spelled in lower case is the SAME market, on BOTH engines.
     *
     * This is the one place the two engines could have disagreed: the column's collation is case-insensitive on
     * MySQL and MariaDB and BINARY on SQLite, so normalising the write and not the read would have made this call
     * find the consent on one engine and miss it on the other. Everything now goes through
     * MarketingConsent::market(), and this test is what would catch it going back.
     */
    public function test_a_market_spelled_in_lower_case_is_the_same_market_on_both_engines(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'case@example.test');
        $gate = app(MarketingConsentGate::class);

        app(MarketingConsentRecorder::class)->grantForCustomer(
            customerId: (int) $customer->id,
            market: 'kw',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
            source: MarketingConsent::SOURCE_REGISTRATION,
        );

        // Stored upper case whatever was passed in.
        $this->assertSame('KW', DB::table('customer_marketing_consents')->value('market'));

        // And found whichever way it is asked for.
        foreach (['KW', 'kw', 'Kw', ' kw '] as $asked) {
            $this->assertTrue(
                $gate->allowsCustomer(customerId: (int) $customer->id, market: $asked, channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_NEWSLETTER),
                "A consent recorded for 'kw' was not found when asked for '{$asked}'."
            );
        }

        // A different market is still a different market.
        $this->assertFalse($gate->allowsCustomer(customerId: (int) $customer->id, market: 'qa', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_NEWSLETTER));
    }

    /** And a withdrawal spelled in lower case stops a send recorded in upper case. */
    public function test_a_withdrawal_spelled_in_lower_case_stops_an_upper_case_grant(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'case-withdraw@example.test');
        $recorder = app(MarketingConsentRecorder::class);

        $recorder->grantForCustomer(
            customerId: (int) $customer->id,
            market: 'KW',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
            source: MarketingConsent::SOURCE_REGISTRATION,
        );

        $recorder->withdraw(
            subjectHash: MarketingSubject::forCustomer(customerId: (int) $customer->id),
            market: 'kw',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
            source: MarketingConsent::SOURCE_UNSUBSCRIBE_LINK,
            customerId: (int) $customer->id,
        );

        $this->assertSame(2, DB::table('customer_marketing_consents')->count());
        $this->assertFalse(app(MarketingConsentGate::class)->allowsCustomer(
            customerId: (int) $customer->id,
            market: 'KW',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
        ));
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

    /**
     * A withdrawal carries the text the person was shown when they agreed.
     *
     * Dropping it from the withdrawal row stayed green on all 90 tests. It matters because the withdrawal is the row
     * in force: a regulator asking what was agreed to, and then unagreed to, reads that row.
     */
    public function test_a_withdrawal_keeps_the_text_version_of_the_grant_it_reverses(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'textversion@example.test');
        $recorder = app(MarketingConsentRecorder::class);

        $recorder->grantForCustomer(
            customerId: (int) $customer->id,
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
            source: MarketingConsent::SOURCE_REGISTRATION,
            textVersion: 'marketing-2026-07',
            textHash: str_repeat('d', 64),
        );

        $withdrawal = $recorder->withdraw(
            subjectHash: MarketingSubject::forCustomer(customerId: (int) $customer->id),
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
            source: MarketingConsent::SOURCE_UNSUBSCRIBE_LINK,
        );

        $this->assertSame('marketing-2026-07', $withdrawal->text_version);
        $this->assertSame(str_repeat('d', 64), $withdrawal->text_hash);
        $this->assertSame((int) $customer->id, $withdrawal->customer_id);
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
