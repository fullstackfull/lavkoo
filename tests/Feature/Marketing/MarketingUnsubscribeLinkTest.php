<?php

namespace Tests\Feature\Marketing;

use App\Models\CustomerMarketingConsent;
use App\Services\Marketing\MarketingConsent;
use App\Services\Marketing\MarketingConsentGate;
use App\Services\Marketing\MarketingConsentRecorder;
use App\Services\Marketing\MarketingSubject;
use App\Services\Marketing\MarketingUnsubscribeLink;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

/**
 * The signed unsubscribe link, driven through the real route with real HTTP requests. I1-08 criterion 3.
 *
 * Every request here is made WITHOUT A LOGIN, because that is the only way the link is ever used: the person is in
 * their mail client.
 */
class MarketingUnsubscribeLinkTest extends MarketingConsentTestCase
{
    public function test_opening_the_link_records_a_withdrawal_without_anybody_being_logged_in(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'unsub@example.test');
        $this->grantNewsletterConsent(customerId: (int) $customer->id);

        $this->assertGuest();
        $this->get($this->linkFor(customerId: (int) $customer->id))->assertOk();
        $this->assertGuest();

        $withdrawals = DB::table('customer_marketing_consents')->where('decision', CustomerMarketingConsent::DECISION_WITHDRAWN)->get();
        $this->assertCount(1, $withdrawals);
        $this->assertSame(MarketingSubject::forCustomer(customerId: (int) $customer->id), $withdrawals->first()->subject_hash);
        $this->assertSame(MarketingConsent::SOURCE_UNSUBSCRIBE_LINK, $withdrawals->first()->source);

        $this->assertFalse(app(MarketingConsentGate::class)->allowsCustomer(
            customerId: (int) $customer->id,
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
        ));
    }

    /** The grant row is not edited and not removed: the withdrawal is a NEW row beside it. */
    public function test_opening_the_link_never_changes_or_deletes_the_grant(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'untouched@example.test');
        $grant = $this->grantNewsletterConsent(customerId: (int) $customer->id);
        $grantAsStored = DB::table('customer_marketing_consents')->where('id', $grant->id)->first();

        $this->get($this->linkFor(customerId: (int) $customer->id))->assertOk();

        $this->assertEquals($grantAsStored, DB::table('customer_marketing_consents')->where('id', $grant->id)->first());
        $this->assertSame(2, DB::table('customer_marketing_consents')->count());
    }

    /** Opening it twice writes ONE withdrawal and raises nothing: mail clients prefetch, and people click again. */
    public function test_opening_the_link_twice_writes_one_withdrawal_and_does_not_error(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'twice@example.test');
        $this->grantNewsletterConsent(customerId: (int) $customer->id);
        $link = $this->linkFor(customerId: (int) $customer->id);

        $this->get($link)->assertOk();
        $this->get($link)->assertOk();
        $this->get($link)->assertOk();

        $this->assertSame(1, DB::table('customer_marketing_consents')->where('decision', CustomerMarketingConsent::DECISION_WITHDRAWN)->count());
        $this->assertSame(2, DB::table('customer_marketing_consents')->count());
    }

    /** It works for somebody who never agreed in the first place (an opt-out market), and is still idempotent. */
    public function test_the_link_works_for_a_subject_with_no_grant_at_all(): void
    {
        $this->switchConsentOn();
        $this->setMarketPolicy(market: 'SA', policy: MarketingConsent::POLICY_OPT_OUT);
        $customer = $this->makeCustomer(email: 'nogrant@example.test');
        $link = $this->linkFor(customerId: (int) $customer->id);

        $this->get($link)->assertOk();
        $this->get($link)->assertOk();

        $this->assertSame(1, DB::table('customer_marketing_consents')->count());
        $this->assertFalse(app(MarketingConsentGate::class)->allowsCustomer(
            customerId: (int) $customer->id,
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
        ));
    }

    public function test_the_route_refuses_a_link_with_no_signature(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'nosig@example.test');
        $this->grantNewsletterConsent(customerId: (int) $customer->id);

        $signed = $this->linkFor(customerId: (int) $customer->id);
        $unsigned = strtok($signed, '?');

        $this->get($unsigned)->assertForbidden();
        $this->assertSame(0, DB::table('customer_marketing_consents')->where('decision', CustomerMarketingConsent::DECISION_WITHDRAWN)->count());
    }

    public function test_the_route_refuses_a_link_whose_signature_was_edited(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'badsig@example.test');
        $this->grantNewsletterConsent(customerId: (int) $customer->id);

        $tampered = preg_replace('/signature=[0-9a-f]+/', 'signature=' . str_repeat('0', 64), $this->linkFor(customerId: (int) $customer->id));

        $this->get((string) $tampered)->assertForbidden();
        $this->assertSame(0, DB::table('customer_marketing_consents')->where('decision', CustomerMarketingConsent::DECISION_WITHDRAWN)->count());
    }

    /**
     * THE ATTACK THE DESIGN IS FOR: somebody holding their OWN valid link tries to unsubscribe somebody else.
     *
     * They swap their token for the victim's token and keep their own signature. The signature covers the whole URL,
     * so it no longer matches and the request is refused — and the victim's consent is untouched.
     */
    public function test_one_persons_valid_link_cannot_unsubscribe_another_person(): void
    {
        $this->switchConsentOn();
        $attacker = $this->makeCustomer(email: 'attacker@example.test');
        $victim = $this->makeCustomer(email: 'victim@example.test');
        $this->grantNewsletterConsent(customerId: (int) $attacker->id);
        $victimGrant = $this->grantNewsletterConsent(customerId: (int) $victim->id);

        $attackerLink = $this->linkFor(customerId: (int) $attacker->id);
        $victimToken = $this->tokenOf(url: $this->linkFor(customerId: (int) $victim->id));
        $attackerToken = $this->tokenOf(url: $attackerLink);

        $forged = str_replace($attackerToken, $victimToken, $attackerLink);
        $this->assertNotSame($attackerLink, $forged, 'The two tokens are identical, so this test measures nothing.');

        $this->get($forged)->assertForbidden();

        $this->assertSame(0, DB::table('customer_marketing_consents')->where('decision', CustomerMarketingConsent::DECISION_WITHDRAWN)->count());
        $this->assertTrue(app(MarketingConsentGate::class)->allowsCustomer(
            customerId: (int) $victim->id,
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
        ));
        $this->assertEquals(
            DB::table('customer_marketing_consents')->where('id', $victimGrant->id)->first()->decision,
            CustomerMarketingConsent::DECISION_GRANTED
        );
    }

    /** And the other half: the link holder can only reach the channel and purpose their own link names. */
    public function test_a_link_for_one_purpose_does_not_unsubscribe_another_purpose(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'onepurpose@example.test');
        $this->grantNewsletterConsent(customerId: (int) $customer->id);
        app(MarketingConsentRecorder::class)->grantForCustomer(
            customerId: (int) $customer->id,
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_ABANDONED_CART,
            source: MarketingConsent::SOURCE_ACCOUNT_PAGE,
        );

        $this->get($this->linkFor(customerId: (int) $customer->id))->assertOk();

        $gate = app(MarketingConsentGate::class);
        $this->assertFalse($gate->allowsCustomer(customerId: (int) $customer->id, market: 'SA', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_NEWSLETTER));
        $this->assertTrue($gate->allowsCustomer(customerId: (int) $customer->id, market: 'SA', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_ABANDONED_CART));
    }

    /** The token holds no account id and no address: nothing in the URL names the person in the clear. */
    public function test_the_link_carries_no_account_id_and_no_address(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'opaque-subject@example.test');

        $token = $this->tokenOf(url: MarketingUnsubscribeLink::forEmail(
            email: 'opaque-subject@example.test',
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
        ));

        $this->assertStringNotContainsString('opaque-subject', $token);
        $this->assertStringNotContainsString('example.test', $token);
        $this->assertStringNotContainsString(MarketingSubject::forEmail(email: 'opaque-subject@example.test'), $token);
        $this->assertStringNotContainsString('newsletter', $token);

        // ENCRYPTED, not merely encoded, which is the property that matters: undoing the base64 of the token yields
        // an envelope, not the payload. A reader without the application key learns nothing — not the address, not
        // the subject hash, not the purpose. (The customer id is not checked for as a substring: a one-digit id
        // occurs in almost any base64 string by chance, so such a check would pass or fail for no reason.)
        $decoded = (string) base64_decode(strtr($token, '-_', '+/'), true);
        $this->assertStringNotContainsString('opaque-subject', $decoded);
        $this->assertStringNotContainsString(MarketingSubject::forEmail(email: 'opaque-subject@example.test'), $decoded);
        $this->assertStringNotContainsString('newsletter', $decoded);
        $this->assertStringNotContainsString('subject_hash', $decoded);
        $this->assertStringNotContainsString('"customer_id"', $decoded);
        $this->assertGreaterThan(0, (int) $customer->id);

        // URL-safe: nothing in it needs escaping, so no mail client can mangle it into a different token.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
    }

    /** A token that is not one this store wrote gets the same neutral page, and writes nothing. */
    public function test_a_token_this_store_did_not_write_is_answered_neutrally_and_writes_nothing(): void
    {
        $this->switchConsentOn();

        $this->assertNull(MarketingUnsubscribeLink::read(token: 'not-a-token-at-all'));

        // Signed by this application, so the route lets it through; the token itself still says nothing.
        $this->get(URL::signedRoute(MarketingUnsubscribeLink::ROUTE, ['token' => 'not-a-token-at-all']))->assertOk();

        $this->assertSame(0, DB::table('customer_marketing_consents')->count());
    }

    /**
     * WHAT THE TOKEN ACTUALLY CARRIES, decrypted and listed.
     *
     * The test next door asserts that nothing can be READ off the token, which is true and is what protects a URL
     * sitting in somebody's inbox — but an independent review pointed out that it is confidentiality by encryption,
     * not by absence: the payload does carry the account id, and a mutation that ALSO put a plaintext e-mail address
     * into it left all 90 tests green, because the assertions run against the ciphertext. So the payload itself is
     * pinned here, key by key, and anything new in it has to be argued for in a diff.
     */
    public function test_the_token_carries_exactly_the_five_things_the_route_needs(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'payload@example.test');

        $token = $this->tokenOf(url: $this->linkFor(customerId: (int) $customer->id));
        $padded = strtr($token, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $payload = json_decode(Crypt::decryptString($padded), true);

        $this->assertSame(['subject_hash', 'customer_id', 'market', 'channel', 'purpose'], array_keys($payload));
        $this->assertSame(MarketingSubject::forCustomer(customerId: (int) $customer->id), $payload['subject_hash']);
        $this->assertSame((int) $customer->id, $payload['customer_id']);
        $this->assertSame('SA', $payload['market']);
        $this->assertStringNotContainsString('payload@example.test', (string) json_encode($payload));
    }

    /**
     * The withdrawal a link records is filed under the account the link names.
     *
     * Two mutations — the token dropping customer_id, and the controller passing null instead of it — were both GREEN.
     * The grant's own row masked it: withdraw() falls back to the grant's customer_id, and in an opt_out market, where
     * there is no grant, nothing falls back. So this one has NO grant to fall back to.
     */
    public function test_the_withdrawal_a_link_records_names_the_account_even_with_no_grant_on_file(): void
    {
        $this->switchConsentOn();
        $this->setMarketPolicy('SA', MarketingConsent::POLICY_OPT_OUT);
        $customer = $this->makeCustomer(email: 'optout-account@example.test');

        $this->get($this->linkFor(customerId: (int) $customer->id))->assertOk();

        $withdrawal = CustomerMarketingConsent::query()->sole();
        $this->assertSame(CustomerMarketingConsent::DECISION_WITHDRAWN, $withdrawal->decision);
        $this->assertSame((int) $customer->id, $withdrawal->customer_id, 'A withdrawal that names nobody cannot be shown to be about this customer.');
    }

    /**
     * THE SIGNATURE DOES NOT EXPIRE, and that is a decision the class argues for at length with no test behind it.
     *
     * An independent review added an expiry to URL::signedRoute and watched all 90 tests stay green. Adding one is the
     * most likely "hardening" edit a later developer makes, and it is the edit this feature must not take: people
     * unsubscribe from old mail, and a link that has timed out is a legal problem, not a security one — it can only
     * ever remove consent.
     */
    public function test_a_link_still_works_years_after_the_message_was_sent(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'old-mail@example.test');
        $this->grantNewsletterConsent(customerId: (int) $customer->id);
        $link = $this->linkFor(customerId: (int) $customer->id);

        $this->travel(5)->years();

        $this->get($link)->assertOk();
        $this->assertSame(1, DB::table('customer_marketing_consents')->where('decision', CustomerMarketingConsent::DECISION_WITHDRAWN)->count());
    }

    /**
     * THE ROUTE MUST NOT RAISE BEFORE ITS MIGRATION HAS RUN, or after a rollback.
     *
     * The whole dormancy argument is that the gate needs the switch AND the table; an independent review found this one
     * route asking for neither and measured a QueryException out of it on both engines. The reachable sequence is a
     * rollback: links go out, nobody has consented yet so down() permits the drop, and every link in an inbox then
     * answers 500 instead of the neutral page the controller promises.
     */
    public function test_the_route_answers_neutrally_when_the_table_is_not_there(): void
    {
        $this->switchConsentOn();
        $link = $this->linkFor(customerId: 1);
        Schema::drop('customer_marketing_consents');

        $this->withoutExceptionHandling();
        $this->get($link)->assertOk();

        $this->runConsentMigration();
        $this->assertSame(0, DB::table('customer_marketing_consents')->count());
    }

    /**
     * A link already in an inbox keeps working after an operator switches the feature off.
     *
     * The DELIBERATE EXCEPTION to "the switch off means nothing happens", and the reason this route asks the gate's
     * `recordable()` rather than `enabled()`. The alternative is a person who asked to be left alone and no record of
     * it; a withdrawal can only ever reduce what the store sends.
     */
    public function test_a_link_still_removes_consent_after_the_feature_is_switched_off(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'switched-off@example.test');
        $this->grantNewsletterConsent(customerId: (int) $customer->id);
        $link = $this->linkFor(customerId: (int) $customer->id);

        Config::set('marketing.consent.enabled', false);
        $this->get($link)->assertOk();

        $this->assertSame(1, DB::table('customer_marketing_consents')->where('decision', CustomerMarketingConsent::DECISION_WITHDRAWN)->count());
    }

    /** The page's own URL is a token, so it must not travel to the next site the visitor opens. */
    public function test_the_page_hands_its_own_url_to_nothing(): void
    {
        $this->switchConsentOn();
        $body = (string) $this->get($this->linkFor(customerId: 1))->getContent();

        $this->assertStringContainsString('<meta name="referrer" content="no-referrer">', $body);
        $this->assertStringContainsString('noindex, nofollow', $body);
    }

    /** A token whose channel or purpose is not one of the known names is refused rather than stored. */
    public function test_a_token_naming_an_unknown_channel_is_not_acted_on(): void
    {
        $encoded = $this->tokenOf(url: MarketingUnsubscribeLink::forEmail(
            email: 'known@example.test',
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
        ));
        $this->assertNotNull(MarketingUnsubscribeLink::read(token: $encoded));

        $forged = rtrim(strtr(Crypt::encryptString((string) json_encode([
            'subject_hash' => str_repeat('b', 64),
            'market' => 'SA',
            'channel' => 'carrier-pigeon',
            'purpose' => MarketingConsent::PURPOSE_NEWSLETTER,
        ])), '+/', '-_'), '=');

        $this->assertNull(MarketingUnsubscribeLink::read(token: $forged));
    }

    /**
     * The PURPOSE half of that guard, which nothing covered.
     *
     * Both reviews split the single condition the channel and purpose checks used to share, removed only the purpose
     * half, and all 90 tests stayed green — so the PR's own mutation figure for it (one condition, red) overstated
     * what was covered. They are three separate guards now, one per line, each with its own test.
     */
    public function test_a_token_naming_an_unknown_purpose_is_not_acted_on(): void
    {
        $this->assertNull(MarketingUnsubscribeLink::read(token: $this->forgedToken(market: 'SA', channel: MarketingConsent::CHANNEL_EMAIL, purpose: 'win-a-car')));
    }

    /**
     * A token naming a market the store does not sell in is refused.
     *
     * `market` is char(2). A longer one is silently truncated by a non-strict MySQL and raises 1406 under the
     * strict-mode job, and a truncated market is never found again by the read — so acting on one would write a
     * withdrawal that no sender will ever see.
     */
    public function test_a_token_naming_a_market_the_store_does_not_sell_in_is_not_acted_on(): void
    {
        $this->assertNull(MarketingUnsubscribeLink::read(token: $this->forgedToken(market: 'Saudi Arabia', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_NEWSLETTER)));
        $this->assertNull(MarketingUnsubscribeLink::read(token: $this->forgedToken(market: 'FR', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_NEWSLETTER)));
    }

    /**
     * A market spelled in lower case in the token still finds the consent it is about.
     *
     * The ONE normalisation rule is the point of commit 8fa75070, and the token leg of it had no test: a mutation
     * removing it from read() stayed green on all 90, on both engines, because no test ever passed a lower-case
     * market. MySQL's collation would have hidden it and SQLite's BINARY comparison would not.
     */
    public function test_a_market_spelled_in_lower_case_in_the_token_still_finds_the_consent(): void
    {
        $this->switchConsentOn();
        $customer = $this->makeCustomer(email: 'lower-market@example.test');
        $this->grantNewsletterConsent(customerId: (int) $customer->id);

        $token = $this->forgedToken(
            market: 'sa',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
            subjectHash: MarketingSubject::forCustomer(customerId: (int) $customer->id),
            customerId: (int) $customer->id,
        );

        // read() is a public API and its contract is a CANONICAL market, whoever calls it next. Asserted directly,
        // because the recorder and the gate both normalise again, so going through them alone cannot tell the
        // difference — which is why the review's mutation of this line stayed green.
        $this->assertSame('SA', MarketingUnsubscribeLink::read(token: $token)['market']);

        $this->get(URL::signedRoute(MarketingUnsubscribeLink::ROUTE, ['token' => $token]))->assertOk();

        $this->assertFalse(app(MarketingConsentGate::class)->allowsCustomer(
            customerId: (int) $customer->id,
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
        ));
    }

    /** A token this application did not write, built with its key, so the route lets it through to `read`. */
    private function forgedToken(string $market, string $channel, string $purpose, ?string $subjectHash = null, ?int $customerId = null): string
    {
        return rtrim(strtr(Crypt::encryptString((string) json_encode([
            'subject_hash' => $subjectHash ?? str_repeat('c', 64),
            'customer_id' => $customerId,
            'market' => $market,
            'channel' => $channel,
            'purpose' => $purpose,
        ])), '+/', '-_'), '=');
    }

    private function grantNewsletterConsent(int $customerId): CustomerMarketingConsent
    {
        return app(MarketingConsentRecorder::class)->grantForCustomer(
            customerId: $customerId,
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
            source: MarketingConsent::SOURCE_REGISTRATION,
        );
    }

    private function linkFor(int $customerId): string
    {
        return MarketingUnsubscribeLink::forCustomer(
            customerId: $customerId,
            market: 'SA',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
        );
    }

    private function tokenOf(string $url): string
    {
        return basename((string) parse_url($url, PHP_URL_PATH));
    }
}
