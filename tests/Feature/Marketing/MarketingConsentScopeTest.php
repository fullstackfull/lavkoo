<?php

namespace Tests\Feature\Marketing;

use App\Models\CustomerMarketingConsent;
use App\Models\LegalEntity;
use App\Services\Marketing\MarketingConsent;
use App\Services\Marketing\MarketingConsentRecorder;
use App\Services\Marketing\MarketingUnsubscribeLink;
use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;

/**
 * WHICH STOREFRONT A MARKETING DECISION WAS GIVEN ON, AND WHICH COMPANY IT WAS GIVEN TO (A2-13, the marketing half of M-556).
 *
 * `customer_marketing_consents.storefront` and `.legal_entity_id` have existed since the table was created, and nothing on
 * any head filled either: the recorder took both as parameters, no caller passed one, and `claimForEmail` hard-coded two
 * nulls. So every consent row carried two nulls and the market was the only scope that worked — which is why A2-13's first
 * two criteria said "NOT PROVED: storefront and legal entity" while the rest of them was green.
 *
 * Every test here drives a REAL ROUTE: the newsletter box (`POST subscription`), which is the one form on this platform
 * where a person states a marketing choice, and the unsubscribe link, which is the one place a person withdraws one.
 * Calling the recorder by hand would prove the recorder and not the application, which is the mistake this project has
 * recorded twice; the storefront is resolved from the request exactly as it is on a storefront page, through the
 * `X-Storefront` header the apps send.
 *
 * WHAT IS NOT CLAIMED HERE. The consent KEY is still the market: {@see \App\Models\CustomerMarketingConsent::latestDecision()}
 * reads subject, market, channel and purpose, and the market is the market of the subject's own address
 * ({@see \App\Services\Marketing\MarketingMarket}). Two storefronts of one market still share a decision. The two columns
 * are the record of where and under whom an answer was given, not a second key — see MarketingConsentScope for why
 * re-keying would split the write (a web request, which has a storefront) from the read (a console command and an admin
 * screen, which have none).
 *
 * TEETH, measured on SQLite and on MariaDB 10.11.14. Nine of the eleven go red when the recorder stops resolving the
 * scope, which is the state of every head before this change; eight when the storefront is never resolved; four when the
 * company is read for the MARKET instead of the storefront; one each when a withdrawal stops inheriting the grant's pair
 * and when the registry stops asking whether a company is still active. ONE TOOTH CAME BACK INERT and is recorded rather
 * than tidied away: putting `claimForEmail`'s two hard-coded nulls back breaks nothing, because the recorder now treats a
 * null argument and a missing one alike. The defect moved rather than survived — those two lines were deleted because
 * they are dead and say the opposite of what happens, not because a test caught them.
 */
class MarketingConsentScopeTest extends MarketingConsentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->switchConsentOn();
        (require database_path('migrations/2026_11_27_100000_create_legal_entities_table.php'))->up();
        $this->forgetWhatWasRememberedAboutTheStorefronts();
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(StorefrontContext::class);
        $this->forgetWhatWasRememberedAboutTheStorefronts();

        parent::tearDown();
    }

    /**
     * THE FIRST TEST OF THE SHIPPED DEFAULT: with storefronts off, a consent row is the row this platform writes today.
     *
     * Not a new switch of its own — the two that govern it, `gcc.storefronts.enabled` and
     * `gcc.storefronts.legal_entities.enabled`, both ship off, and the second does nothing while the first is off. A
     * company is registered for Saudi Arabia here on purpose, so that what keeps the columns empty is measurably the
     * switch and not an empty registry.
     */
    public function test_while_storefronts_are_off_a_consent_names_no_storefront_and_no_company(): void
    {
        $this->registerCompanyIn('SA');

        $this->subscribe(email: 'dormant@example.test');

        $consent = CustomerMarketingConsent::query()->sole();
        $this->assertSame('SA', $consent->market, 'The market is unchanged: it never came from the storefront.');
        $this->assertNull($consent->storefront, 'With storefronts off nothing names a storefront, so the row must not invent one.');
        $this->assertNull($consent->legal_entity_id, 'With storefronts off the company registry answers nothing.');
    }

    /** The storefront the answer was given on, taken from the request the way every storefront page takes it. */
    public function test_a_consent_names_the_storefront_the_answer_was_given_on(): void
    {
        $this->switchStorefrontsOn();

        $this->subscribe(email: 'on-kuwait@example.test', storefront: 'KW');

        $this->assertSame('KW', CustomerMarketingConsent::query()->sole()->storefront);
    }

    /**
     * TWO STOREFRONTS NAME TWO DIFFERENT COMPANIES, which is the whole point of the column.
     *
     * An identity check and not a presence check: a recorder that read one company for the whole platform — which is
     * exactly what the legal-acceptance receipt did until M-556's legal half was fixed — would fill both rows and pass
     * any assertion that only asked for a number.
     */
    public function test_two_storefronts_record_the_two_companies_that_sell_on_them(): void
    {
        $this->switchStorefrontsOn();
        $saudiCompany = $this->registerCompanyIn('SA');
        $kuwaitiCompany = $this->registerCompanyIn('KW');

        $this->subscribe(email: 'saudi-shopper@example.test', storefront: 'SA');
        $this->subscribe(email: 'kuwaiti-shopper@example.test', storefront: 'KW');

        $this->assertSame(
            [(int) $saudiCompany->id, (int) $kuwaitiCompany->id],
            CustomerMarketingConsent::query()->orderBy('id')->get()->map(static fn (CustomerMarketingConsent $row): int => (int) $row->legal_entity_id)->all(),
        );
        $this->assertNotSame((int) $saudiCompany->id, (int) $kuwaitiCompany->id, 'Two companies that share an id would make the assertion above vacuous.');
    }

    /**
     * THE DESIGN DECISION, PINNED: the company is the STOREFRONT'S, not the market's.
     *
     * A customer whose own address names Kuwait can stand on the Saudi storefront and tick the box there. The decision
     * is filed under KW, because that is the market whose senders read it; what the person SAW is the Saudi
     * storefront's text, under the company that sells there, so that is the company the row names. A row reading "given
     * on the Saudi storefront, to the Kuwaiti company" would not be a receipt of anything.
     *
     * This is the one input on which the alternative reading (always the market's company, because the market's sender
     * is who acts on the consent) differs, and it is written down in the track's handoff as a question for legal review
     * rather than settled in code.
     */
    public function test_the_company_follows_the_storefront_and_not_the_customers_own_market(): void
    {
        $this->switchStorefrontsOn();
        $saudiCompany = $this->registerCompanyIn('SA');
        $kuwaitiCompany = $this->registerCompanyIn('KW');
        $customer = $this->makeCustomer(email: 'kuwaiti-on-saudi@example.test', country: 'Kuwait');

        $this->actingAs($customer, 'customer');
        $this->subscribe(email: 'kuwaiti-on-saudi@example.test', storefront: 'SA');

        $consent = CustomerMarketingConsent::query()->sole();
        $this->assertSame('KW', $consent->market, 'The market is the market of their own address, and the senders read it.');
        $this->assertSame('SA', $consent->storefront);
        $this->assertSame((int) $saudiCompany->id, (int) $consent->legal_entity_id);
        $this->assertNotSame((int) $kuwaitiCompany->id, (int) $consent->legal_entity_id);
    }

    /**
     * A WITHDRAWAL KEEPS THE PAIR THE GRANT WAS GIVEN UNDER.
     *
     * The click that withdraws arrives from a mail client with no market on it: the link carries the market, and the
     * request resolves to whichever storefront the bare address serves — the Saudi one. Where the grant was given is a
     * recorded fact and the click is a guess at one, so the withdrawal carries the grant's own storefront and company
     * forward. Measured by granting on the Kuwaiti storefront and clicking with nothing to say so.
     */
    public function test_a_withdrawal_keeps_the_storefront_and_company_the_grant_was_given_under(): void
    {
        $this->switchStorefrontsOn();
        $this->registerCompanyIn('SA');
        $kuwaitiCompany = $this->registerCompanyIn('KW');
        $customer = $this->makeCustomer(email: 'kuwaiti-grant@example.test', country: 'Kuwait');

        $this->actingAs($customer, 'customer');
        $this->subscribe(email: 'kuwaiti-grant@example.test', storefront: 'KW');

        // The click arrives from a mail client, which sends no storefront header at all: WITHOUT this the test client
        // would keep sending the one the grant was made with, and the inheritance below would be measured by nothing.
        $this->withoutHeader(StorefrontResolver::HEADER);
        $this->get(MarketingUnsubscribeLink::forCustomer(customerId: (int) $customer->id, market: 'KW', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_NEWSLETTER))->assertOk();

        $this->assertSame('SA', StorefrontCatalog::country(), 'The bare address has to resolve to the DEFAULT storefront, or there is nothing for the grant to be preferred over.');

        $decisions = CustomerMarketingConsent::query()->orderBy('id')->get();
        $this->assertSame(['granted', 'withdrawn'], $decisions->pluck('decision')->all());
        $withdrawal = $decisions->last();
        $this->assertSame('KW', $withdrawal->storefront, 'The withdrawal took the storefront the click resolved to (the Saudi one) instead of the storefront the person agreed on.');
        $this->assertSame((int) $kuwaitiCompany->id, (int) $withdrawal->legal_entity_id);
    }

    /**
     * A withdrawal with NO grant before it — which is every withdrawal in an opt-out market — names where it was given,
     * because there is nothing to inherit. Without this the row would carry the two nulls the whole table used to.
     */
    public function test_a_withdrawal_with_no_grant_before_it_names_where_it_was_given(): void
    {
        $this->switchStorefrontsOn();
        $this->setMarketPolicy('KW', MarketingConsent::POLICY_OPT_OUT);
        $kuwaitiCompany = $this->registerCompanyIn('KW');

        $this->withHeader(StorefrontResolver::HEADER, 'KW')
            ->get(MarketingUnsubscribeLink::forEmail(email: 'never-asked@example.test', market: 'KW', channel: MarketingConsent::CHANNEL_EMAIL, purpose: MarketingConsent::PURPOSE_NEWSLETTER))
            ->assertOk();

        $withdrawal = CustomerMarketingConsent::query()->sole();
        $this->assertSame('withdrawn', $withdrawal->decision);
        $this->assertSame('KW', $withdrawal->storefront);
        $this->assertSame((int) $kuwaitiCompany->id, (int) $withdrawal->legal_entity_id);
    }

    /**
     * The two switches are independent, and the second one alone is what the company depends on.
     *
     * Kept separate because this is the state an installation is in between the two releases: storefronts serving six
     * markets, no company registry yet. The row has to say where the answer was given even then.
     */
    public function test_with_legal_entities_off_a_consent_names_the_storefront_and_no_company(): void
    {
        $this->switchStorefrontsOn();
        config(['gcc.storefronts.legal_entities.enabled' => false]);
        $this->forgetWhatWasRememberedAboutTheStorefronts();
        $this->registerCompanyIn('KW');

        $this->subscribe(email: 'no-registry@example.test', storefront: 'KW');

        $consent = CustomerMarketingConsent::query()->sole();
        $this->assertSame('KW', $consent->storefront);
        $this->assertNull($consent->legal_entity_id, 'The company comes from the registry, and the registry is switched off.');
    }

    /** A storefront nobody has registered a company for records none, rather than another storefront's. */
    public function test_a_storefront_with_no_registered_company_records_none(): void
    {
        $this->switchStorefrontsOn();
        $this->registerCompanyIn('SA');

        $this->subscribe(email: 'unregistered@example.test', storefront: 'KW');

        $consent = CustomerMarketingConsent::query()->sole();
        $this->assertSame('KW', $consent->storefront);
        $this->assertNull($consent->legal_entity_id, 'A market with no company of its own must never be filed under another market\'s.');
    }

    /** A company whose row has been switched off is not the company that sells there. */
    public function test_a_company_that_is_no_longer_active_is_not_recorded(): void
    {
        $this->switchStorefrontsOn();
        $this->registerCompanyIn('KW', active: false);

        $this->subscribe(email: 'retired-company@example.test', storefront: 'KW');

        $this->assertNull(CustomerMarketingConsent::query()->sole()->legal_entity_id);
    }

    /**
     * An anonymous visitor's unverified CLAIM is scoped too. It is the row `claimForEmail` hard-coded two nulls onto,
     * and it is still a record of something that happened on a storefront even though it is not permission to send.
     */
    public function test_the_unverified_claim_of_an_anonymous_visitor_is_scoped_as_well(): void
    {
        $this->switchStorefrontsOn();
        $kuwaitiCompany = $this->registerCompanyIn('KW');

        $this->assertGuest();
        $this->subscribe(email: 'passer-by@example.test', storefront: 'KW');

        $claim = CustomerMarketingConsent::query()->sole();
        $this->assertSame(CustomerMarketingConsent::DECISION_PENDING, $claim->decision);
        $this->assertSame('KW', $claim->storefront);
        $this->assertSame((int) $kuwaitiCompany->id, (int) $claim->legal_entity_id);
    }

    /**
     * The admin panel and the console have no storefront, so a decision recorded there falls back to the market the
     * decision governs — the shape {@see \App\Services\AuditLogger::marketOf()} already records its own in. Driven
     * through the recorder because the two senders that run there do not WRITE a decision; what is measured is that the
     * fallback exists at all, since without it a panel-recorded decision would name no company on a platform that has
     * one for every market.
     */
    public function test_where_nothing_names_a_storefront_the_company_of_the_market_answers(): void
    {
        $this->switchStorefrontsOn();
        $this->registerCompanyIn('SA');
        $kuwaitiCompany = $this->registerCompanyIn('KW');

        app(MarketingConsentRecorder::class)->grantForEmail(
            email: 'from-the-panel@example.test',
            market: 'KW',
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_NEWSLETTER,
            source: MarketingConsent::SOURCE_ACCOUNT_PAGE,
        );

        $consent = CustomerMarketingConsent::query()->sole();
        $this->assertNull($consent->storefront, 'A console command and a panel are on no storefront, and the row must say so.');
        $this->assertSame((int) $kuwaitiCompany->id, (int) $consent->legal_entity_id);
    }

    /**
     * The newsletter box, as a visitor of that storefront uses it.
     *
     * The header is set for THIS request only. `withHeader` keeps it for every request the test makes afterwards, which
     * is how the first version of the withdrawal test below came back green with the inheritance removed: the click that
     * is supposed to arrive with no storefront on it was still carrying the grant's.
     */
    private function subscribe(string $email, ?string $storefront = null): void
    {
        if ($storefront !== null) {
            $this->withHeader(StorefrontResolver::HEADER, $storefront);
        }

        $this->post(route('subscription'), ['subscription_email' => $email])->assertRedirect();
        $this->withoutHeader(StorefrontResolver::HEADER);
    }

    private function switchStorefrontsOn(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.legal_entities.enabled' => true,
            'gcc.storefronts.countries.KW.active' => true,
        ]);
        $this->forgetWhatWasRememberedAboutTheStorefronts();
    }

    private function registerCompanyIn(string $country, bool $active = true): LegalEntity
    {
        $company = LegalEntity::create([
            'country' => $country,
            'legal_name' => "Lavkoo {$country} Trading Co.",
            'tax_registration_number' => "TAX-{$country}-001",
            'status' => $active,
        ]);
        $this->forgetWhatWasRememberedAboutTheStorefronts();

        return $company;
    }

    /** Both readers remember an answer for the life of the process, and every test here changes what the answer should be. */
    private function forgetWhatWasRememberedAboutTheStorefronts(): void
    {
        StorefrontCatalog::flush();
        app(LegalEntityRegistry::class)->flush();
    }
}
