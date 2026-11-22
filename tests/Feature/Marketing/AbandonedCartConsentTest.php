<?php

namespace Tests\Feature\Marketing;

use App\Mail\AbandonedCartReminderMail;
use App\Models\ShippingAddress;
use App\Services\Marketing\MarketingConsent;
use App\Services\Marketing\MarketingConsentRecorder;
use App\Services\Marketing\MarketingSubject;
use App\Services\Marketing\MarketingUnsubscribeLink;
use App\Services\Retention\AbandonedCartService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The abandoned-cart reminder with the gate ON, driven through the real `cart:remind-abandoned` command.
 *
 * I1-08 criterion 1 (a send is skipped without consent for that store and channel) and criterion 2 (withdrawing stops
 * the NEXT send of the same schedule), and the sender half of A2-13 criterion 3.
 *
 * The limit, again: what is keyed and proved is the MARKET and the CHANNEL. "Store" in the criterion's wording means
 * storefront, and main has no storefront layer to resolve one from.
 */
class AbandonedCartConsentTest extends MarketingConsentTestCase
{
    public function test_a_customer_without_consent_is_not_reminded(): void
    {
        $this->switchConsentOn();
        $this->switchAbandonedCartRemindersOn();

        $customer = $this->makeCustomer(email: 'no-consent@example.test');
        $this->makeAbandonedCart(customerId: (int) $customer->id, groupId: 'group-no-consent');

        Mail::fake();
        $this->artisan('cart:remind-abandoned', ['--stage' => 1])->assertSuccessful();

        Mail::assertNothingSent();
        // And nothing was claimed in the ledger either, so the cart is still reachable if consent arrives later.
        $this->assertSame(0, DB::table('abandoned_cart_reminders')->count());
    }

    public function test_a_customer_who_agreed_is_reminded(): void
    {
        $this->switchConsentOn();
        $this->switchAbandonedCartRemindersOn();

        $customer = $this->makeCustomer(email: 'agreed@example.test');
        $this->makeAbandonedCart(customerId: (int) $customer->id, groupId: 'group-agreed');
        $this->grantCartReminderConsent(customerId: (int) $customer->id);

        Mail::fake();
        $this->artisan('cart:remind-abandoned', ['--stage' => 1])->assertSuccessful();

        Mail::assertSent(AbandonedCartReminderMail::class, 1);
        $this->assertSame(1, DB::table('abandoned_cart_reminders')->where('email', 'agreed@example.test')->count());
    }

    /** One run, two customers, one of whom agreed: the gate separates them inside the same send. */
    public function test_one_run_reminds_the_customer_who_agreed_and_skips_the_one_who_did_not(): void
    {
        $this->switchConsentOn();
        $this->switchAbandonedCartRemindersOn();

        $agreed = $this->makeCustomer(email: 'mixed-agreed@example.test');
        $silent = $this->makeCustomer(email: 'mixed-silent@example.test');
        $this->makeAbandonedCart(customerId: (int) $agreed->id, groupId: 'group-mixed-agreed');
        $this->makeAbandonedCart(customerId: (int) $silent->id, groupId: 'group-mixed-silent');
        $this->grantCartReminderConsent(customerId: (int) $agreed->id);

        $selected = app(AbandonedCartService::class)->findAbandoned(stage: 1);

        $this->assertSame(['mixed-agreed@example.test'], $selected->pluck('email')->all());
    }

    /**
     * I1-08 criterion 2, the whole of it: the FIRST send goes, consent is withdrawn, and the SECOND send of the same
     * schedule — the same command, the next stage — does not.
     */
    public function test_withdrawing_consent_stops_the_next_send_of_the_same_schedule(): void
    {
        $this->switchConsentOn();
        $this->switchAbandonedCartRemindersOn();

        $customer = $this->makeCustomer(email: 'stops-next@example.test');
        $this->makeAbandonedCart(customerId: (int) $customer->id, groupId: 'group-stops-next');
        $this->grantCartReminderConsent(customerId: (int) $customer->id);

        Mail::fake();
        $this->artisan('cart:remind-abandoned', ['--stage' => 1])->assertSuccessful();
        Mail::assertSent(AbandonedCartReminderMail::class, 1);

        app(MarketingConsentRecorder::class)->withdraw(
            subjectHash: MarketingSubject::forCustomer(customerId: (int) $customer->id),
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_ABANDONED_CART,
            source: MarketingConsent::SOURCE_UNSUBSCRIBE_LINK,
            customerId: (int) $customer->id,
        );

        Mail::fake();
        $this->artisan('cart:remind-abandoned', ['--stage' => 2])->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(1, DB::table('abandoned_cart_reminders')->count(), 'The second stage wrote a ledger row for a withdrawn customer.');
    }

    /** Consent for one market is not consent in another: the customer's own address decides which one is asked about. */
    public function test_consent_given_for_another_market_does_not_let_the_reminder_through(): void
    {
        $this->switchConsentOn();
        $this->switchAbandonedCartRemindersOn();

        $customer = $this->makeCustomer(email: 'wrong-market@example.test', country: 'Saudi Arabia');
        $this->makeAbandonedCart(customerId: (int) $customer->id, groupId: 'group-wrong-market');
        $this->grantCartReminderConsent(customerId: (int) $customer->id, market: 'AE');

        $this->assertSame([], app(AbandonedCartService::class)->findAbandoned(stage: 1)->pluck('email')->all());

        $this->grantCartReminderConsent(customerId: (int) $customer->id, market: 'SA');

        $this->assertSame(['wrong-market@example.test'], app(AbandonedCartService::class)->findAbandoned(stage: 1)->pluck('email')->all());
    }

    /**
     * A customer whose address is in ANOTHER market is filed under THAT market, not under the home market.
     *
     * Without this test, a resolver that answered "Saudi Arabia" for everybody would pass the whole group, because
     * every other customer in it is Saudi. A mutation that did exactly that survived until this was written.
     */
    public function test_a_customer_in_kuwait_is_asked_about_kuwait_and_not_about_the_home_market(): void
    {
        $this->switchConsentOn();
        $this->switchAbandonedCartRemindersOn();

        $customer = $this->makeCustomer(email: 'kuwaiti@example.test', country: 'Kuwait');
        $this->makeAbandonedCart(customerId: (int) $customer->id, groupId: 'group-kuwait');

        // The home market's consent is NOT this customer's consent.
        $this->grantCartReminderConsent(customerId: (int) $customer->id, market: 'SA');
        $this->assertSame([], app(AbandonedCartService::class)->findAbandoned(stage: 1)->pluck('email')->all());

        // Their own market's consent is.
        $this->grantCartReminderConsent(customerId: (int) $customer->id, market: 'KW');
        $this->assertSame(['kuwaiti@example.test'], app(AbandonedCartService::class)->findAbandoned(stage: 1)->pluck('email')->all());
    }

    /** And the unsubscribe link in that customer's message names their market, not the home market. */
    public function test_the_unsubscribe_link_of_a_kuwaiti_customer_names_kuwait(): void
    {
        $this->switchConsentOn();
        $this->switchAbandonedCartRemindersOn();

        $customer = $this->makeCustomer(email: 'kuwaiti-link@example.test', country: 'الكويت');
        $this->makeAbandonedCart(customerId: (int) $customer->id, groupId: 'group-kuwait-link');
        $this->grantCartReminderConsent(customerId: (int) $customer->id, market: 'KW');

        Mail::fake();
        $this->artisan('cart:remind-abandoned', ['--stage' => 1])->assertSuccessful();

        Mail::assertSent(AbandonedCartReminderMail::class, function (AbandonedCartReminderMail $mail): bool {
            $read = MarketingUnsubscribeLink::read(token: $this->tokenOf(url: (string) $mail->unsubscribeUrl));
            $this->assertSame('KW', $read['market']);

            return true;
        });
    }

    /** Consent for another channel is not consent for e-mail. */
    public function test_consent_for_whatsapp_does_not_let_an_email_reminder_through(): void
    {
        $this->switchConsentOn();
        $this->switchAbandonedCartRemindersOn();

        $customer = $this->makeCustomer(email: 'wrong-channel@example.test');
        $this->makeAbandonedCart(customerId: (int) $customer->id, groupId: 'group-wrong-channel');
        $this->grantCartReminderConsent(customerId: (int) $customer->id, channel: MarketingConsent::CHANNEL_WHATSAPP);

        $this->assertSame([], app(AbandonedCartService::class)->findAbandoned(stage: 1)->pluck('email')->all());
    }

    /** Consent for the newsletter is not consent to be chased about a cart. */
    public function test_consent_for_the_newsletter_does_not_let_a_cart_reminder_through(): void
    {
        $this->switchConsentOn();
        $this->switchAbandonedCartRemindersOn();

        $customer = $this->makeCustomer(email: 'wrong-purpose@example.test');
        $this->makeAbandonedCart(customerId: (int) $customer->id, groupId: 'group-wrong-purpose');
        $this->grantCartReminderConsent(customerId: (int) $customer->id, purpose: MarketingConsent::PURPOSE_NEWSLETTER);

        $this->assertSame([], app(AbandonedCartService::class)->findAbandoned(stage: 1)->pluck('email')->all());
    }

    /** A2-13 criterion 3's second half: the reminder that IS sent carries an unsubscribe link, and it is signed. */
    public function test_the_reminder_that_is_sent_carries_a_signed_unsubscribe_link(): void
    {
        $this->switchConsentOn();
        $this->switchAbandonedCartRemindersOn();

        $customer = $this->makeCustomer(email: 'with-link@example.test');
        $this->makeAbandonedCart(customerId: (int) $customer->id, groupId: 'group-with-link');
        $this->grantCartReminderConsent(customerId: (int) $customer->id);

        Mail::fake();
        $this->artisan('cart:remind-abandoned', ['--stage' => 1])->assertSuccessful();

        Mail::assertSent(AbandonedCartReminderMail::class, function (AbandonedCartReminderMail $mail) use ($customer): bool {
            $this->assertNotNull($mail->unsubscribeUrl);
            $this->assertStringContainsString('/marketing/unsubscribe/', $mail->unsubscribeUrl);
            $this->assertStringContainsString('signature=', $mail->unsubscribeUrl);

            // And it names this customer, this channel and this purpose — not somebody else's.
            $token = $this->tokenOf(url: $mail->unsubscribeUrl);
            $read = MarketingUnsubscribeLink::read(token: $token);
            $this->assertSame(MarketingSubject::forCustomer(customerId: (int) $customer->id), $read['subject_hash']);
            $this->assertSame(MarketingConsent::CHANNEL_EMAIL, $read['channel']);
            $this->assertSame(MarketingConsent::PURPOSE_ABANDONED_CART, $read['purpose']);

            return true;
        });
    }

    /** The rendered message, not just the mailable: the link reaches the HTML a person sees. */
    public function test_the_rendered_reminder_html_contains_the_unsubscribe_link(): void
    {
        $this->switchConsentOn();
        $this->switchAbandonedCartRemindersOn();
        $this->setDefaultCurrency();

        $customer = $this->makeCustomer(email: 'rendered@example.test');
        $this->makeAbandonedCart(customerId: (int) $customer->id, groupId: 'group-rendered');
        $this->grantCartReminderConsent(customerId: (int) $customer->id);

        Mail::fake();
        $this->artisan('cart:remind-abandoned', ['--stage' => 1])->assertSuccessful();

        Mail::assertSent(AbandonedCartReminderMail::class, function (AbandonedCartReminderMail $mail): bool {
            $html = $mail->render();

            $this->assertStringContainsString('/marketing/unsubscribe/', $html);
            $this->assertStringContainsString(e($mail->unsubscribeUrl), $html);

            return true;
        });
    }

    /** The guest's cart, which is the one the reminder's own switches keep off by default, is keyed by the address. */
    public function test_a_guest_cart_is_asked_about_by_the_address_the_guest_typed(): void
    {
        $this->switchConsentOn();
        $this->switchAbandonedCartRemindersOn(includeGuests: true);

        $guestId = DB::table('guest_users')->insertGetId(['ip_address' => '127.0.0.1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shipping_addresses')->insert([
            'customer_id' => (string) $guestId,
            'is_guest' => 1,
            'contact_person_name' => 'A Guest',
            'email' => 'guest@example.test',
            'country' => 'Saudi Arabia',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->makeAbandonedCart(customerId: $guestId, groupId: 'group-guest', isGuest: true);

        $this->assertSame([], app(AbandonedCartService::class)->findAbandoned(stage: 1)->pluck('email')->all());

        app(MarketingConsentRecorder::class)->grantForEmail(
            email: 'guest@example.test',
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_ABANDONED_CART,
            source: MarketingConsent::SOURCE_NEWSLETTER_FORM,
        );

        $this->assertSame(['guest@example.test'], app(AbandonedCartService::class)->findAbandoned(stage: 1)->pluck('email')->all());
    }

    /**
     * The defect this test exists for: a guest's reminder row does NOT keep their guest id, so anything that resolved
     * the market a second time from that row fell back to the home market. A Kuwaiti guest was then asked about by
     * KW and handed a link that said SA — so opening it recorded a withdrawal that stopped nothing.
     *
     * A Saudi guest cannot show this, because for them the home market IS the right answer.
     */
    public function test_a_kuwaiti_guest_is_handed_a_link_for_kuwait_not_for_the_home_market(): void
    {
        $this->switchConsentOn();
        $this->switchAbandonedCartRemindersOn(includeGuests: true);

        $guestId = DB::table('guest_users')->insertGetId(['ip_address' => '127.0.0.1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shipping_addresses')->insert([
            'customer_id' => (string) $guestId,
            'is_guest' => 1,
            'contact_person_name' => 'A Kuwaiti Guest',
            'email' => 'kuwaiti-guest@example.test',
            'country' => 'Kuwait',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->makeAbandonedCart(customerId: $guestId, groupId: 'group-kuwaiti-guest', isGuest: true);

        app(MarketingConsentRecorder::class)->grantForEmail(
            email: 'kuwaiti-guest@example.test',
            market: 'KW',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_ABANDONED_CART,
            source: MarketingConsent::SOURCE_NEWSLETTER_FORM,
        );

        Mail::fake();
        $this->artisan('cart:remind-abandoned', ['--stage' => 1])->assertSuccessful();

        Mail::assertSent(AbandonedCartReminderMail::class, function (AbandonedCartReminderMail $mail): bool {
            $read = MarketingUnsubscribeLink::read(token: $this->tokenOf(url: (string) $mail->unsubscribeUrl));
            $this->assertSame('KW', $read['market'], 'The guest was handed a link for the wrong market.');
            $this->assertSame(MarketingSubject::forEmail(email: 'kuwaiti-guest@example.test'), $read['subject_hash']);

            return true;
        });

        // And the link actually stops the next send, which is the whole point of naming the right market.
        $this->get((string) $this->sentUnsubscribeUrl())->assertOk();
        $this->assertSame([], app(AbandonedCartService::class)->findAbandoned(stage: 2)->pluck('email')->all());
    }

    /**
     * ONE PERSON, TWO SUBJECTS — the defect both reviews measured, in the direction they measured it.
     *
     * A registered customer unsubscribes through the link in their own reminder, then shops again without signing in
     * (a guest cart with the same address, which is an ordinary thing to do). The withdrawal was filed under
     * "customer:<id>" and the guest-side send asks about "email:<address>", so the next run of the SAME schedule sent
     * them the same message: measured on both engines as one mail to the address that had just unsubscribed.
     *
     * Driven under opt_out on purpose. Under the shipped opt_in policy the send is blocked anyway for want of a grant,
     * so the test would have passed for the wrong reason — which is how the first version of it went green, and the
     * same blind spot as the fixture in which every subject was Saudi.
     */
    public function test_unsubscribing_as_a_customer_also_stops_the_guest_checkout_reminder(): void
    {
        $this->switchConsentOn();
        $this->setMarketPolicy('SA', MarketingConsent::POLICY_OPT_OUT);
        $this->switchAbandonedCartRemindersOn(includeGuests: true);

        $customer = $this->makeCustomer(email: 'one-person@example.test');
        $this->get(MarketingUnsubscribeLink::forCustomer(
            customerId: (int) $customer->id,
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_ABANDONED_CART,
        ))->assertOk();

        $this->makeGuestCart(email: 'one-person@example.test', groupId: 'group-one-person');

        Mail::fake();
        $this->artisan('cart:remind-abandoned --stage=1')->assertSuccessful();

        Mail::assertNotSent(AbandonedCartReminderMail::class);
        $this->assertSame([], app(AbandonedCartService::class)->findAbandoned(stage: 1)->pluck('email')->all());
    }

    /** And the mirror: a guest unsubscribes, then signs in. The same person, so the same answer. */
    public function test_unsubscribing_as_a_guest_also_stops_the_account_reminder(): void
    {
        $this->switchConsentOn();
        $this->setMarketPolicy('SA', MarketingConsent::POLICY_OPT_OUT);
        $this->switchAbandonedCartRemindersOn();

        $this->get(MarketingUnsubscribeLink::forEmail(
            email: 'other-way@example.test',
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_ABANDONED_CART,
        ))->assertOk();

        $customer = $this->makeCustomer(email: 'other-way@example.test');
        $this->makeAbandonedCart(customerId: (int) $customer->id, groupId: 'group-other-way');

        Mail::fake();
        $this->artisan('cart:remind-abandoned --stage=1')->assertSuccessful();

        Mail::assertNotSent(AbandonedCartReminderMail::class);
    }

    /**
     * A customer who agreed by account is still reminded when the address they used has no row of its own.
     *
     * The guard on the fix above: "ask every subject" must not become "every subject has to say yes", which would
     * have stopped reminding most of the people who HAVE agreed.
     */
    public function test_a_customer_who_agreed_by_account_is_still_reminded_at_an_address_with_no_row(): void
    {
        $this->switchConsentOn();
        $this->switchAbandonedCartRemindersOn();

        $customer = $this->makeCustomer(email: 'account-only@example.test');
        $this->grantCartReminderConsent(customerId: (int) $customer->id);
        $this->makeAbandonedCart(customerId: (int) $customer->id, groupId: 'group-account-only');

        Mail::fake();
        $this->artisan('cart:remind-abandoned --stage=1')->assertSuccessful();

        Mail::assertSent(AbandonedCartReminderMail::class, 1);
    }

    /** A guest cart carrying an address somebody typed at the checkout form. */
    private function makeGuestCart(string $email, string $groupId): int
    {
        $guestId = (int) DB::table('guest_users')->insertGetId(['ip_address' => '127.0.0.1', 'created_at' => now(), 'updated_at' => now()]);

        $address = new ShippingAddress;
        $address->customer_id = (string) $guestId;
        $address->is_guest = 1;
        $address->contact_person_name = 'Guest';
        $address->country = 'Saudi Arabia';
        $address->city = 'Riyadh';
        $address->email = $email;
        $address->save();

        $this->makeAbandonedCart(customerId: $guestId, groupId: $groupId, isGuest: true);

        return $guestId;
    }

    private function sentUnsubscribeUrl(): ?string
    {
        $url = null;
        Mail::assertSent(AbandonedCartReminderMail::class, function (AbandonedCartReminderMail $mail) use (&$url): bool {
            $url = $mail->unsubscribeUrl;

            return true;
        });

        return $url;
    }

    private function grantCartReminderConsent(
        int $customerId,
        string $market = 'SA',
        string $channel = MarketingConsent::CHANNEL_EMAIL,
        string $purpose = MarketingConsent::PURPOSE_ABANDONED_CART,
    ): void {
        app(MarketingConsentRecorder::class)->grantForCustomer(
            customerId: $customerId,
            market: $market,
            channel: $channel,
            purpose: $purpose,
            source: MarketingConsent::SOURCE_ACCOUNT_PAGE,
        );
    }

    private function tokenOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return basename((string) $path);
    }
}
