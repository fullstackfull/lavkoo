<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontAdminBoard;
use App\Services\Storefront\StorefrontLifecycle;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ApprovesStorefrontTax;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\Support\ComplianceClaimScanner;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * I2-07 criteria 0 and 4, the answers an API returns: no field and no string of the readiness of a storefront says it is legally compliant, certified or approved by an authority, and a refusal says the
 * storefront is not open for checkout. The scan is Tests\Support\ComplianceClaimScanner, the language files and the views are read by ComplianceClaimScanTest.
 *
 * Six storefronts are built through the real services and read through the real routes: the default one, one that has its texts, its legal review, its tax decision and its activation (it sells), one nobody
 * prepared (a draft), one with its texts and no legal review, one paused in maintenance and one paused browse-only. The list an app reads (GET /api/v1/storefronts), the refusal of a new sale in each
 * storefront that may not sell (a payment started through the real route, behind the sale gate), the refusal of a request that browses the storefront in maintenance (503) and what the admin screen reads
 * of each (StorefrontAdminBoard) are scanned, names and texts.
 *
 * Covered: those four answers, in English (the language of the API without a header). Not covered: the same answers in another language (the Arabic strings are read, and pinned for what the refusals say,
 * in the language files by ComplianceClaimScanTest), the messages the console commands print, and any answer that is not about the readiness of a storefront.
 */
class ComplianceClaimPayloadsTest extends TestCase
{
    use ApprovesStorefrontTax;
    use BuildsStorefrontLegalDocuments;
    use RestoresLanguageFiles;

    private const PERSON = 'The head of the operations team';

    private const REASON = 'Decision of the operations meeting';

    private const PAY = '\\Customer\\PaymentController@payment';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildStorefrontLegalDocumentsTable();
        $this->buildTheTablesOfAStorefrontDecision(settingPrefix: 'storefront_lifecycle_');
        DB::table('audit_logs')->delete();
        DB::table('business_settings')->where('type', 'like', 'storefront_lifecycle_%')->delete();
        clearWebConfigCacheKeys();

        Schema::dropIfExists('guest_users');
        Schema::create('guest_users', function ($table) {
            $table->id();
            $table->string('ip_address')->nullable();
            $table->string('fcm_token')->nullable();
            $table->timestamps();
        });

        $this->buildTheMarkets();
    }

    protected function tearDown(): void
    {
        foreach (['storefront_legal_documents', 'audit_logs'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function buildTheMarkets(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.countries.QA.active' => true,
            'gcc.storefronts.countries.BH.active' => true,
            'gcc.storefronts.countries.OM.active' => true,
        ]);

        $this->publishRequiredLegalDocuments(country: 'AE');
        $this->publishRequiredLegalDocuments(country: 'QA', reviewed: false);
        $this->publishRequiredLegalDocuments(country: 'BH');
        $this->publishRequiredLegalDocuments(country: 'OM');
        $this->approveNoTaxFor('AE', 'QA', 'BH', 'OM');

        app(StorefrontLifecycle::class)->pause(country: 'BH', mode: StorefrontLifecycle::MAINTENANCE, by: self::PERSON, reason: self::REASON);
        app(StorefrontLifecycle::class)->pause(country: 'OM', mode: StorefrontLifecycle::BROWSE_ONLY, by: self::PERSON, reason: self::REASON);
    }

    private function startAPayment(string $country)
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn (RoutingRoute $candidate) => str_ends_with((string) $candidate->getActionName(), self::PAY));
        $this->assertNotNull($route);

        return $this->postJson('/' . $route->uri(), ['guest_id' => 'guest-1'], ['X-Storefront' => $country]);
    }

    /** @param list<array<string, mixed>> $findings */
    private function assertClaimsNothing(array $findings, string $answer): void
    {
        $scanner = new ComplianceClaimScanner();

        $this->assertSame(
            [],
            array_map(static fn (array $finding): string => $scanner->describe($finding), $findings),
            "{$answer} says a storefront is compliant, certified or approved by an authority. The platform reviews and approves nothing: an answer says a storefront is not open for checkout, or not approved (I2-07).",
        );
    }

    public function test_the_list_of_storefronts_says_what_each_may_do_and_claims_no_compliance_for_any(): void
    {
        $listed = $this->getJson('/api/v1/storefronts')->assertOk()->json();

        $this->assertSame(
            ['SA' => [true, 'active'], 'AE' => [true, 'active'], 'KW' => [false, 'draft'], 'QA' => [false, 'active'], 'BH' => [false, 'paused'], 'OM' => [false, 'paused']],
            collect($listed['storefronts'])->mapWithKeys(fn (array $storefront): array => [$storefront['country'] => [$storefront['sellable'], $storefront['state']]])->all(),
            'the world of this test is not the one it describes: a scan of a payload that is not the one wanted proves nothing',
        );
        $this->assertSame(translate('storefront_is_in_maintenance'), collect($listed['storefronts'])->firstWhere('country', 'BH')['stop_message']);
        $this->assertSame(translate('storefront_not_open_for_checkout_yet'), collect($listed['storefronts'])->firstWhere('country', 'OM')['stop_message']);

        $this->assertClaimsNothing(findings: (new ComplianceClaimScanner())->inPayload(label: 'GET /api/v1/storefronts', payload: $listed), answer: 'The list of storefronts');
    }

    #[DataProvider('storefrontsThatMayNotSell')]
    public function test_a_refused_sale_says_the_storefront_is_not_open_for_checkout_and_claims_no_compliance(string $country): void
    {
        $refusal = $this->startAPayment(country: $country)->assertStatus(422)->assertJsonPath('code', 'storefront_not_sellable')->json();

        $this->assertStringContainsStringIgnoringCase('not open for checkout', $refusal['message']);
        $this->assertClaimsNothing(findings: (new ComplianceClaimScanner())->inPayload(label: "the refusal of a payment in {$country}", payload: $refusal), answer: "The refusal of a sale in {$country}");
    }

    public static function storefrontsThatMayNotSell(): array
    {
        return [
            'a storefront nobody prepared' => ['KW'],
            'a storefront with its texts and no legal review' => ['QA'],
            'a storefront paused in maintenance' => ['BH'],
            'a storefront paused browse-only' => ['OM'],
        ];
    }

    public function test_the_refusal_of_a_request_that_browses_a_storefront_in_maintenance_says_so_and_claims_no_compliance(): void
    {
        Route::middleware(['storefront', 'storefront.available'])->get('/__browse-probe', fn () => response()->json(['ok' => true]));

        $refusal = $this->getJson('/__browse-probe', ['X-Storefront' => 'BH'])->assertStatus(503)->assertJsonPath('code', 'storefront_maintenance')->json();

        $this->assertSame(translate('storefront_is_in_maintenance'), $refusal['message']);
        $this->assertStringContainsStringIgnoringCase('under maintenance', $refusal['message']);
        $this->assertClaimsNothing(findings: (new ComplianceClaimScanner())->inPayload(label: 'the refusal of a request that browses BH', payload: $refusal), answer: 'The refusal of a request that browses a storefront in maintenance');
    }

    public function test_a_sale_in_a_storefront_that_may_sell_is_not_refused_by_the_gate(): void
    {
        $response = $this->startAPayment(country: 'AE');

        $this->assertNotSame(422, $response->getStatusCode());
        $this->assertNull($response->json('code'));
    }

    public function test_what_the_admin_screen_reads_of_a_ready_and_of_a_not_ready_storefront_claims_no_compliance(): void
    {
        $rows = collect(json_decode((string) json_encode(app(StorefrontAdminBoard::class)->rows()), true))->keyBy('country');

        $this->assertSame([], $rows['AE']['unmet'], 'the ready storefront has nothing unmet');
        $this->assertContains('legal_texts', $rows['KW']['unmet']);
        $this->assertContains('legal_review', $rows['KW']['unmet']);
        $this->assertSame(['legal_review'], array_values(array_intersect(['legal_texts', 'legal_review'], $rows['QA']['unmet'])));

        $this->assertClaimsNothing(findings: (new ComplianceClaimScanner())->inPayload(label: 'StorefrontAdminBoard::rows()', payload: $rows->all()), answer: 'What the admin screen reads of the storefronts');
    }

    public function test_the_scan_of_an_answer_finds_a_claim_that_is_added_to_a_real_one(): void
    {
        $listed = $this->getJson('/api/v1/storefronts')->assertOk()->json();
        $scanner = new ComplianceClaimScanner();

        $listed['storefronts'][1]['legally_compliant'] = true;
        $listed['storefronts'][2]['stop_message'] = 'This storefront is fully compliant and approved by the authority.';
        $refusal = $this->startAPayment(country: 'KW')->json();
        $refusal['message'] = 'Kuwait is certified.';

        $this->assertSame(
            ['storefronts.1.legally_compliant', 'storefronts.2.stop_message'],
            array_column($scanner->inPayload(label: 'the list with claims', payload: $listed), 'key'),
        );
        $this->assertSame(['message'], array_column($scanner->inPayload(label: 'the refusal with a claim', payload: $refusal), 'key'));
    }
}
