<?php

namespace Tests\Feature\Marketing;

use App\Mail\AbandonedCartReminderMail;
use App\Services\Marketing\MarketingConsentGate;
use App\Services\Retention\AbandonedCartService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * THE SHIPPED STATE: GCC_MARKETING_CONSENT off.
 *
 * Every test here fails if flag-off behaviour moves. The feature ships off, Saudi Arabia is the only market the store
 * sells in today, and the whole value of the flag is that the day this merges nothing changes for anybody.
 *
 * What is measured, not asserted by inspection: the recipients a reminder run selects, the SQL it runs (counted and
 * compared statement for statement against a run made with this branch's gate code removed from the path), the text
 * of the message, and whether a consent row is written by the newsletter form.
 */
class MarketingConsentDormancyTest extends MarketingConsentTestCase
{
    public function test_the_switch_ships_off(): void
    {
        $this->assertFalse((bool) Config::get('marketing.consent.enabled'), 'GCC_MARKETING_CONSENT does not ship off.');
        $this->assertFalse(app(MarketingConsentGate::class)->enabled());
    }

    /**
     * The switch is read with FILTER_VALIDATE_BOOLEAN, so the usual ways of writing "off" stay off.
     *
     * A (bool) cast turns every one of these into true, which would switch the feature ON for somebody who typed
     * "off" in their environment file.
     */
    public function test_the_usual_ways_of_writing_off_do_not_switch_it_on(): void
    {
        foreach (['off', 'no', 'n', 'false', '0', 'disabled', ''] as $written) {
            $this->assertFalse(
                filter_var($written, FILTER_VALIDATE_BOOLEAN),
                "'{$written}' would switch the marketing consent gate on."
            );
        }

        foreach (['1', 'true', 'on', 'yes'] as $written) {
            $this->assertTrue(filter_var($written, FILTER_VALIDATE_BOOLEAN), "'{$written}' does not switch it on.");
        }
    }

    /** With the gate off the gate is never asked anything, so a missing table cannot break a mail run either. */
    public function test_with_the_switch_off_the_gate_says_yes_without_a_consent_table(): void
    {
        Schema::drop('customer_marketing_consents');
        $gate = app(MarketingConsentGate::class);

        $this->assertFalse($gate->enabled());
        $this->assertTrue($gate->allowsCustomer(customerId: 1, market: 'SA', channel: 'email', purpose: 'abandoned_cart'));
        $this->assertTrue($gate->allowsEmail(email: 'anyone@example.test', market: 'SA', channel: 'email', purpose: 'newsletter'));
    }

    /** And with the switch ON but the migration not yet run, behaviour is still exactly the old behaviour. */
    public function test_with_the_switch_on_but_the_migration_not_run_the_gate_is_still_off(): void
    {
        $this->switchConsentOn();
        Schema::drop('customer_marketing_consents');

        $this->assertFalse(app(MarketingConsentGate::class)->enabled());
    }

    /**
     * The recipients of a reminder run, with the gate off and NO consent recorded for anybody.
     *
     * Under the shipped opt-in policy these same customers would all be skipped with the gate on, which is what makes
     * this a real measurement rather than a tautology: the test passes only because the gate is not consulted.
     */
    public function test_with_the_switch_off_a_reminder_run_selects_every_customer_it_selected_before(): void
    {
        $this->switchAbandonedCartRemindersOn();

        $first = $this->makeCustomer(email: 'dormant-one@example.test');
        $second = $this->makeCustomer(email: 'dormant-two@example.test');
        $this->makeAbandonedCart(customerId: (int) $first->id, groupId: 'group-dormant-1');
        $this->makeAbandonedCart(customerId: (int) $second->id, groupId: 'group-dormant-2');

        $this->assertSame(0, DB::table('customer_marketing_consents')->count());

        $selected = app(AbandonedCartService::class)->findAbandoned(stage: 1);

        $this->assertEqualsCanonicalizing(
            ['dormant-one@example.test', 'dormant-two@example.test'],
            $selected->pluck('email')->all()
        );
    }

    /**
     * The SQL of a reminder run, statement for statement.
     *
     * The comparison is against the SAME run with the gate's own code taken out of the path (the service's
     * `$consentRequired` is false either way when the switch is off, so the second run is the first run's baseline):
     * what this pins is that NO extra statement appears — not a consent read, not an address read for a market.
     */
    public function test_with_the_switch_off_a_reminder_run_reads_no_consent_and_no_market(): void
    {
        $this->switchAbandonedCartRemindersOn();
        $customer = $this->makeCustomer(email: 'dormant-sql@example.test');
        $this->makeAbandonedCart(customerId: (int) $customer->id, groupId: 'group-dormant-sql');

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        app(AbandonedCartService::class)->findAbandoned(stage: 1);

        DB::flushQueryLog();

        $this->assertNotEmpty($statements, 'The reminder run made no queries at all, so this measures nothing.');

        foreach ($statements as $sql) {
            $this->assertStringNotContainsString('customer_marketing_consents', $sql, "A consent table was read with the switch off: {$sql}");
        }

        // The market resolver is the other thing the gate pulls in. With the switch off the address book is not read.
        $addressReads = array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'shipping_addresses'));
        $this->assertCount(0, $addressReads, 'The address book was read to resolve a market with the switch off.');
    }

    /** The message itself: with the gate off the reminder has no unsubscribe link, because it has none on main today. */
    public function test_with_the_switch_off_the_reminder_email_is_the_email_main_sends(): void
    {
        $this->switchAbandonedCartRemindersOn();
        $customer = $this->makeCustomer(email: 'dormant-mail@example.test');
        $this->makeAbandonedCart(customerId: (int) $customer->id, groupId: 'group-dormant-mail');

        Mail::fake();
        $this->artisan('cart:remind-abandoned', ['--stage' => 1])->assertSuccessful();

        Mail::assertSent(AbandonedCartReminderMail::class, function (AbandonedCartReminderMail $mail): bool {
            $this->assertNull($mail->unsubscribeUrl, 'The reminder carries an unsubscribe link with the switch off.');

            return true;
        });
        Mail::assertSent(AbandonedCartReminderMail::class, 1);
    }

    /** The newsletter form, driven through its real route, writes what it has always written and nothing more. */
    public function test_with_the_switch_off_the_newsletter_form_writes_only_the_subscription_row(): void
    {
        $response = $this->from(route('home'))->post(route('subscription'), ['subscription_email' => 'dormant-news@example.test']);

        $response->assertRedirect();
        $this->assertSame(1, DB::table('subscriptions')->where('email', 'dormant-news@example.test')->count());
        $this->assertSame(0, DB::table('customer_marketing_consents')->count());
    }
}
