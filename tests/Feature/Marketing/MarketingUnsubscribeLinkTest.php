<?php

namespace Tests\Feature\Marketing;

use App\Models\CustomerMarketingConsent;
use App\Services\Marketing\MarketingConsent;
use App\Services\Marketing\MarketingConsentGate;
use App\Services\Marketing\MarketingConsentRecorder;
use App\Services\Marketing\MarketingSubject;
use App\Services\Marketing\MarketingUnsubscribeLink;
use Illuminate\Support\Facades\DB;

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
        $this->get(\Illuminate\Support\Facades\URL::signedRoute(MarketingUnsubscribeLink::ROUTE, ['token' => 'not-a-token-at-all']))->assertOk();

        $this->assertSame(0, DB::table('customer_marketing_consents')->count());
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

        $forged = rtrim(strtr(\Illuminate\Support\Facades\Crypt::encryptString((string) json_encode([
            'subject_hash' => str_repeat('b', 64),
            'market' => 'SA',
            'channel' => 'carrier-pigeon',
            'purpose' => MarketingConsent::PURPOSE_NEWSLETTER,
        ])), '+/', '-_'), '=');

        $this->assertNull(MarketingUnsubscribeLink::read(token: $forged));
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
