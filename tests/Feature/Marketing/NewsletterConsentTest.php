<?php

namespace Tests\Feature\Marketing;

use App\Models\CustomerMarketingConsent;
use App\Services\Marketing\MarketingConsent;
use App\Services\Marketing\MarketingConsentGate;
use App\Services\Marketing\MarketingSubject;
use App\Services\Marketing\MarketingUnsubscribeLink;
use Illuminate\Support\Facades\DB;

/**
 * The newsletter box, driven through its real route (`POST subscription`), with the gate ON.
 *
 * This is the ONE place on main where a customer states a marketing choice of their own, which is why it is the only
 * part of A2-13 criterion 4 ("registration and the account page show and store the choices in the website and the
 * app") that this branch can touch at all. It stores a choice made on the website. It does NOT put a choice on the
 * registration form, it does NOT put one on the account page, and it does NOT touch either Flutter app — those are
 * form and layout changes in three repositories, and this branch's scope forbids altering a layout.
 *
 * THERE IS ALSO NO NEWSLETTER SEND ON main TO GATE. `subscriptions` is read in exactly one place in the whole
 * application (WebController::subscription, to check for a duplicate address) and written in that same place; no
 * command, job, listener or controller sends anything to the people in it. So "a newsletter send skips a customer
 * without consent" has no sender to prove it against on this head: what is proved is that the CHOICE is recorded, and
 * that the gate refuses the newsletter purpose for somebody who has not made it.
 */
class NewsletterConsentTest extends MarketingConsentTestCase
{
    public function test_subscribing_records_an_unverified_claim_and_still_writes_the_subscription_row(): void
    {
        $this->switchConsentOn();

        $this->post(route('subscription'), ['subscription_email' => 'news-on@example.test'])->assertRedirect();

        // The old row, untouched in shape: whatever reads `subscriptions` keeps reading the same thing.
        $this->assertSame(1, DB::table('subscriptions')->where('email', 'news-on@example.test')->count());

        $consent = CustomerMarketingConsent::query()->sole();
        $this->assertSame(MarketingSubject::forEmail(email: 'news-on@example.test'), $consent->subject_hash);
        $this->assertNull($consent->customer_id, 'An anonymous subscriber has no account to file the row under.');
        $this->assertSame('SA', $consent->market);
        $this->assertSame(MarketingConsent::CHANNEL_EMAIL, $consent->channel);
        $this->assertSame(MarketingConsent::PURPOSE_NEWSLETTER, $consent->purpose);
        $this->assertSame(MarketingConsent::SOURCE_NEWSLETTER_FORM, $consent->source);
        $this->assertSame(
            CustomerMarketingConsent::DECISION_PENDING,
            $consent->decision,
            'The form asks nothing of whoever typed the address, so what is recorded is a claim, not a grant.'
        );
    }

    /**
     * THE DEFECT AN INDEPENDENT REVIEW MEASURED, and the reason the row above is a claim.
     *
     * The newsletter box is a public form with `required|email` and nothing else: no login, no confirmation mail, no
     * CAPTCHA. A visitor could therefore write a GRANT — the store's own legal evidence that it was allowed to send —
     * against an address belonging to somebody else, and the review watched the gate's answer turn from refuse to
     * allow after one POST. It now stays refuse.
     */
    public function test_a_stranger_cannot_make_the_store_allowed_to_write_to_somebody_elses_address(): void
    {
        $this->switchConsentOn();
        $gate = app(MarketingConsentGate::class);
        $question = ['email' => 'victim@example.test', 'market' => 'SA', 'channel' => MarketingConsent::CHANNEL_EMAIL, 'purpose' => MarketingConsent::PURPOSE_NEWSLETTER];

        $this->assertFalse($gate->allowsEmail(...$question));

        $this->assertGuest();
        $this->post(route('subscription'), ['subscription_email' => 'victim@example.test'])->assertRedirect();

        $this->assertFalse(
            $gate->allowsEmail(...$question),
            'An address typed by a passer-by is not that person agreeing to be written to.'
        );
        $this->assertSame(CustomerMarketingConsent::DECISION_PENDING, CustomerMarketingConsent::query()->sole()->decision);
    }

    /** A choice made by somebody who IS signed in is a grant, because the session shows whose address it is. */
    public function test_the_recorded_choice_of_a_signed_in_customer_is_what_the_gate_then_answers(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'news-gate@example.test');
        $gate = app(MarketingConsentGate::class);
        $question = ['customerId' => (int) $customer->id, 'market' => 'SA', 'channel' => MarketingConsent::CHANNEL_EMAIL, 'purpose' => MarketingConsent::PURPOSE_NEWSLETTER];

        $this->assertFalse($gate->allowsCustomer(...$question));

        $this->actingAs($customer, 'customer')
            ->post(route('subscription'), ['subscription_email' => 'news-gate@example.test'])
            ->assertRedirect();

        $this->assertSame(CustomerMarketingConsent::DECISION_GRANTED, CustomerMarketingConsent::query()->sole()->decision);
        $this->assertTrue($gate->allowsCustomer(...$question));
    }

    /**
     * A PERSON MAY CHANGE THEIR MIND BACK, through the only form this application has.
     *
     * An independent review drove three real POSTs and one real unsubscribe and measured the second and third writing
     * nothing: the duplicate check on `subscriptions` meant the consent recorder was never reached again, so an
     * unsubscribe was a one-way door with no screen anywhere that could undo it. The recorder's own append-only
     * history was tested for this, but by calling the recorder BY HAND — which is the pattern this project forbids,
     * because it builds the state it claims to detect. This one drives the routes.
     */
    public function test_a_customer_may_agree_again_after_unsubscribing_through_the_link(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'news-again@example.test');
        $gate = app(MarketingConsentGate::class);
        $question = ['customerId' => (int) $customer->id, 'market' => 'SA', 'channel' => MarketingConsent::CHANNEL_EMAIL, 'purpose' => MarketingConsent::PURPOSE_NEWSLETTER];

        $this->actingAs($customer, 'customer')->post(route('subscription'), ['subscription_email' => 'news-again@example.test'])->assertRedirect();
        $this->assertTrue($gate->allowsCustomer(...$question));

        $this->get(MarketingUnsubscribeLink::forCustomer(
            customerId: (int) $customer->id,
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
        ))->assertOk();
        $this->assertFalse($gate->allowsCustomer(...$question));

        // The same form again. `subscriptions` still holds their address, which is what used to end the story here.
        $this->actingAs($customer, 'customer')->post(route('subscription'), ['subscription_email' => 'news-again@example.test'])->assertRedirect();

        $this->assertTrue($gate->allowsCustomer(...$question), 'There is no way back from an unsubscribe.');
        $this->assertSame(
            ['granted', 'withdrawn', 'granted'],
            CustomerMarketingConsent::query()->orderBy('id')->pluck('decision')->all(),
            'Every step has to be kept: the history is the evidence.'
        );
        $this->assertSame(1, DB::table('subscriptions')->where('email', 'news-again@example.test')->count());
    }

    /**
     * THE MARKET OF THE SENDER THAT WRITES, which nothing checked.
     *
     * A mutation filing every signed-in customer's newsletter choice under the home market instead of their own stayed
     * GREEN across all 90 tests, because no test here ever subscribed a customer whose address is outside Saudi
     * Arabia. A2-13's rulings rest on "per market", and this is the one sender on main that WRITES a market.
     */
    public function test_a_kuwaiti_customers_newsletter_choice_is_filed_under_kuwait(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'news-kw@example.test', country: 'Kuwait');

        $this->actingAs($customer, 'customer')
            ->post(route('subscription'), ['subscription_email' => 'news-kw@example.test'])
            ->assertRedirect();

        $this->assertSame('KW', CustomerMarketingConsent::query()->sole()->market);
    }

    /**
     * ONE ADDRESS, HOWEVER IT IS SPELLED, IS ONE SUBJECT.
     *
     * Two independent reviews removed the lower-casing from MarketingSubject::forEmail and watched all 90 tests stay
     * green. It matters because `subscriptions.email` is already case-insensitive on MySQL while the subject hash is
     * not: the same person could otherwise have two consent histories, an unsubscribe recorded under one of them and a
     * send allowed under the other.
     */
    public function test_two_spellings_of_one_address_are_one_subject(): void
    {
        $this->switchConsentOn();

        $this->post(route('subscription'), ['subscription_email' => 'News-Case@Example.test'])->assertRedirect();

        // The link is built for the lower-case spelling, and it has to reach the row the mixed-case form wrote.
        $this->get(MarketingUnsubscribeLink::forEmail(
            email: ' news-case@example.test ',
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
        ))->assertOk();

        $this->assertSame(['pending', 'withdrawn'], CustomerMarketingConsent::query()->orderBy('id')->pluck('decision')->all());

        // THE LOAD-BEARING ASSERTION. The decisions above are written whatever the spelling does — withdraw() writes
        // a withdrawal for a subject it has never seen — so asserting them proves nothing on its own, which is how my
        // first attempt at this test passed with the normalisation removed. What has to be true is that the two rows
        // are about ONE subject.
        $this->assertCount(
            1,
            CustomerMarketingConsent::query()->pluck('subject_hash')->unique(),
            'Two spellings of one address became two subjects, so a withdrawal under one does not stop a send under the other.'
        );
    }

    /** Subscribing twice is refused by the existing duplicate check, so it cannot stack consent rows either. */
    public function test_subscribing_twice_records_one_consent(): void
    {
        $this->switchConsentOn();

        $this->post(route('subscription'), ['subscription_email' => 'news-twice@example.test'])->assertRedirect();
        $this->post(route('subscription'), ['subscription_email' => 'news-twice@example.test'])->assertRedirect();

        $this->assertSame(1, DB::table('subscriptions')->count());
        $this->assertSame(
            1,
            DB::table('customer_marketing_consents')->count(),
            'The recorder is now reached for an address that is already subscribed, so it has to refuse to stack a duplicate.'
        );
    }

    /** A signed-in customer's choice is filed under their account, so it follows them whatever address they type. */
    public function test_a_signed_in_customer_has_the_choice_filed_under_their_account(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'news-account@example.test');

        $this->actingAs($customer, 'customer')
            ->post(route('subscription'), ['subscription_email' => 'something-else@example.test'])
            ->assertRedirect();

        $consent = CustomerMarketingConsent::query()->sole();
        $this->assertSame((int) $customer->id, $consent->customer_id);
        $this->assertSame(MarketingSubject::forCustomer(customerId: (int) $customer->id), $consent->subject_hash);
    }

    /** The address a customer unsubscribed from stays unsubscribed: the gate reads the newest decision. */
    public function test_the_newsletter_consent_can_be_withdrawn_through_the_unsubscribe_link(): void
    {
        $this->switchConsentOn();
        $this->post(route('subscription'), ['subscription_email' => 'news-withdraw@example.test'])->assertRedirect();

        $link = MarketingUnsubscribeLink::forEmail(
            email: 'news-withdraw@example.test',
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
        );

        $this->get($link)->assertOk();

        $this->assertFalse(app(MarketingConsentGate::class)->allowsEmail(
            email: 'news-withdraw@example.test',
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
        ));
        // The subscription row is NOT deleted: that table is not this branch's to change.
        $this->assertSame(1, DB::table('subscriptions')->where('email', 'news-withdraw@example.test')->count());
    }

    /**
     * The honest record of what is NOT here: nothing in the application sends to the newsletter list.
     *
     * If a newsletter sender is ever added, this test turns red and whoever adds it has to gate it.
     *
     * The ONE reader moved on 2026-11-25 and is worth the note. It used to be `WebController`, which read
     * `Subscription::where('email', ...)` for its duplicate check; that check is now per MARKET (I1-04), so the read moved into
     * `App\Services\Notification\NewsletterMarkets`, which is the market rule for the signup and for the admin list. It is not a
     * sender -- it reads the table and writes a market onto a row, and the assertion below pins that it carries no send.
     */
    public function test_no_sender_in_the_application_reads_the_newsletter_list(): void
    {
        $readers = [];
        foreach ($this->phpFilesOf(directories: ['app', 'Modules', 'routes']) as $file) {
            $contents = (string) file_get_contents($file);
            if (str_contains($contents, 'Subscription::') || str_contains($contents, "table('subscriptions')") || str_contains($contents, 'from subscriptions')) {
                $readers[] = str_replace(base_path() . '/', '', $file);
            }
        }

        $this->assertSame(
            ['app/Services/Notification/NewsletterMarkets.php'],
            $readers,
            'Something new reads the newsletter list. If it SENDS to it, it must ask MarketingConsentGate first.'
        );

        // And the one reader is a rule, not a sender: no mail, no notification, no provider call.
        $rule = (string) file_get_contents(base_path('app/Services/Notification/NewsletterMarkets.php'));
        foreach (['Mail::', 'Notification::', 'Http::', 'dispatch(', '->send('] as $send) {
            $this->assertStringNotContainsString($send, $rule, "the newsletter market rule has started sending ({$send})");
        }
    }

    /**
     * @param array<int, string> $directories
     * @return array<int, string>
     */
    private function phpFilesOf(array $directories): array
    {
        $files = [];
        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }
        sort($files);

        // A guard that reads nothing proves nothing.
        $this->assertGreaterThan(500, count($files), 'The file scan found almost no files, so it is not scanning the application.');

        return $files;
    }
}
