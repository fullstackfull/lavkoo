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
 * What is measured, not asserted by inspection: the recipients a reminder run selects, the SQL it runs, the bytes of
 * the message a person reads, and whether a consent row is written by the newsletter form.
 *
 * WHAT IS NOT HERE, because an independent review caught this file claiming it: there is no second run of the same
 * command against main inside these tests, and there cannot be — a PHPUnit process runs one checkout. Each test below
 * says exactly which property it pins. The two-head comparison IS made, by hand, in a second worktree at
 * c5c04b7b, and the figures belong in the pull request where they can be dated, not in a docblock that will go stale.
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
     * The SQL of a reminder run: no statement names the consent table, and none reads the address book.
     *
     * NOT a statement-for-statement comparison against main — nothing in this process can run main's code. What it
     * pins is the two tables this branch could have added a read of, and that the run queried at all.
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

    /**
     * THE BYTES OF THE MESSAGE, not a null on a property.
     *
     * This test used to be called "the reminder email is the email main sends" and assert one thing:
     * `assertNull($mail->unsubscribeUrl)`. Both reviews rendered the mailable in two worktrees and found the dormant
     * body was NOT main's: the @if block left 8 bytes of indentation behind on the footer's closing tag. Harmless in a
     * mail client; not harmless as evidence, since a test named for byte-identity would have passed through any amount
     * of change to the dormant message.
     *
     * So the message is RENDERED, and the footer — the one region this branch touches — is pinned byte for byte, in
     * the spelling main has at c5c04b7b. The template now opens and closes the conditional inside the existing line,
     * so with the switch off it adds nothing at all: measured at 2897 bytes on both heads, identical but for the
     * recovery link's own signature.
     */
    public function test_with_the_switch_off_the_rendered_reminder_is_byte_for_byte_the_footer_main_renders(): void
    {
        $this->setDefaultCurrency();
        $this->switchAbandonedCartRemindersOn();
        $customer = $this->makeCustomer(email: 'dormant-mail@example.test');
        $this->makeAbandonedCart(customerId: (int) $customer->id, groupId: 'group-dormant-mail');

        Mail::fake();
        $this->artisan('cart:remind-abandoned', ['--stage' => 1])->assertSuccessful();

        Mail::assertSent(AbandonedCartReminderMail::class, function (AbandonedCartReminderMail $mail): bool {
            $this->assertNull($mail->unsubscribeUrl, 'The reminder carries an unsubscribe link with the switch off.');

            $html = $mail->render();
            $this->assertStringNotContainsString('unsubscribe', $html);

            // main at c5c04b7b, exactly: two lines of content and the closing tag at its own indentation.
            $this->assertStringContainsString(
                "        {$this->companyLine()}<br>\n"
                . "        You are receiving this because you left items in your cart on our store\n"
                . "    </div>",
                $html,
                'The dormant message is not the message main renders, down to its whitespace.'
            );

            return true;
        });
        Mail::assertSent(AbandonedCartReminderMail::class, 1);
    }

    /** The footer's first line as the template builds it, so the byte comparison is about this branch's change only. */
    private function companyLine(): string
    {
        return (string) getWebConfig(name: 'company_name');
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
