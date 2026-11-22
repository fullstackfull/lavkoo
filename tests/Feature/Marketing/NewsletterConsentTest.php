<?php

namespace Tests\Feature\Marketing;

use App\Models\CustomerMarketingConsent;
use App\Services\Marketing\MarketingConsent;
use App\Services\Marketing\MarketingConsentGate;
use App\Services\Marketing\MarketingSubject;
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
    public function test_subscribing_records_a_newsletter_consent_and_still_writes_the_subscription_row(): void
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
        $this->assertSame(CustomerMarketingConsent::DECISION_GRANTED, $consent->decision);
    }

    public function test_the_recorded_choice_is_what_the_gate_then_answers(): void
    {
        $this->switchConsentOn();
        $gate = app(MarketingConsentGate::class);

        $this->assertFalse($gate->allowsEmail(email: 'news-gate@example.test', market: 'SA', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_NEWSLETTER));

        $this->post(route('subscription'), ['subscription_email' => 'news-gate@example.test'])->assertRedirect();

        $this->assertTrue($gate->allowsEmail(email: 'news-gate@example.test', market: 'SA', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_NEWSLETTER));
    }

    /** Subscribing twice is refused by the existing duplicate check, so it cannot stack consent rows either. */
    public function test_subscribing_twice_records_one_consent(): void
    {
        $this->switchConsentOn();

        $this->post(route('subscription'), ['subscription_email' => 'news-twice@example.test'])->assertRedirect();
        $this->post(route('subscription'), ['subscription_email' => 'news-twice@example.test'])->assertRedirect();

        $this->assertSame(1, DB::table('subscriptions')->count());
        $this->assertSame(1, DB::table('customer_marketing_consents')->count());
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

        $link = \App\Services\Marketing\MarketingUnsubscribeLink::forEmail(
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
            ['app/Http/Controllers/Web/WebController.php'],
            $readers,
            'Something new reads the newsletter list. If it SENDS to it, it must ask MarketingConsentGate first.'
        );
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
