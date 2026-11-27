<?php

namespace Tests\Feature\Catalog;

use App\Http\Controllers\RestAPI\v3\seller\POSController;
use App\Models\Seller;
use App\Services\Catalog\ProductCodeScope;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\BuildsProductCatalog;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * How to turn the country-scoped codes OFF AGAIN once two countries share a code (M1-18).
 *
 * `gcc.catalog.country_scoped_codes` off means a code names one product across the whole table again, which is why the runbook of GCC-14b
 * has to say "never switch the codes switch off once two countries share a code": the till in Riyadh scans a box and is answered the Dubai
 * product that carries the same code -- a product of a market this operator does not sell in. The second test below is that measurement. A
 * switch somebody is told never to use is not a switch, which is what the criterion is about.
 *
 * `gcc.catalog.off_is_one_market` is the answer, in the shape `gcc.storefronts.off_is_one_market` already has for the catalogue
 * ({@see \Tests\Feature\Storefront\StorefrontKillSwitchTest}). It ships OFF, and the first section is that with it off the scan answers
 * byte-for-byte what it answers today.
 *
 * With it on, off means ONE MARKET: a scan reads the default market's products and the market that was added cannot answer it. Only the
 * ROWS it may reach change -- the SHAPE of the answer stays the one off gives, the code and never the barcode, one product and never an
 * ambiguity list -- because this is a kill switch for the exposure and not a way of half-enabling the feature. Uniqueness is not narrowed
 * by it: refusing a code that is free is safe where accepting one that is held is not.
 */
class CodeScopeKillSwitchTest extends TestCase
{
    use BuildsCodeScopeCatalog;
    use BuildsProductCatalog;
    use RestoresLanguageFiles;

    private const SELLER_ID = 5;

    private ProductCodeScope $scope;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCatalogTables();
        Schema::table('products', function ($table) {
            $table->string('code')->nullable();
            $table->string('barcode')->nullable();
        });
        $this->addStorefrontCountryColumn();
        $this->scope = app(ProductCodeScope::class);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    /** @param array<string, mixed> $extra */
    private function sellerProduct(int $id, string $code, string $country = 'SA', ?string $barcode = null, array $extra = []): int
    {
        $this->insertProduct(id: $id, name: 'Serum ' . $id, userId: self::SELLER_ID, addedBy: 'seller', extra: $extra + ['code' => $code, 'barcode' => $barcode, 'storefront_country' => $country]);

        return $id;
    }

    /** @param array<string, string> $query */
    private function scan(array $query): JsonResponse
    {
        $request = Request::create('/api/v3/seller/pos/products', 'GET', $query);
        $request['seller'] = (new Seller())->forceFill(['id' => self::SELLER_ID]);

        return app(POSController::class)->get_product_by_barcode($request);
    }

    /** @param array<string, string> $query */
    private function scannedId(array $query): ?int
    {
        $answer = $this->scan($query)->getData(true);

        return $answer === [] ? null : (int) $answer['id'];
    }

    private function offMeansOneMarket(): void
    {
        config(['gcc.catalog.country_scoped_codes' => false, ProductCodeScope::OFF_IS_ONE_MARKET => true]);
    }

    // ---- as it ships: off is today's scan --------------------------------------------------------------------------------------------

    public function test_the_switch_ships_off(): void
    {
        $this->assertFalse((bool) config(ProductCodeScope::OFF_IS_ONE_MARKET), 'the switch that changes this ships off');
        $this->assertStringContainsString(
            "'off_is_one_market' => filter_var(env('GCC_CATALOG_OFF_IS_ONE_MARKET', false), FILTER_VALIDATE_BOOLEAN),",
            (string) file_get_contents(config_path('gcc.php')),
            'and it ships off in the config file itself, not only in this process',
        );
    }

    /**
     * The measurement this criterion rested on: with the codes switched off again, the added market's product answers the scan of the
     * market that remains. Nothing here is a defect of the scope -- off is global by design -- which is exactly why off was not a way of
     * stopping it.
     */
    public function test_turning_the_scope_off_lets_the_added_market_answer_a_scan_so_it_is_not_a_kill_switch(): void
    {
        $this->sellerProduct(1, 'SKU100', 'AE');
        $this->sellerProduct(2, 'SKU100', 'SA');

        $this->assertSame(1, $this->scannedId(['code' => 'SKU100']), 'the Dubai product answers a till that sells in Riyadh');
    }

    /**
     * The FIRST scan of a process answers one key more than the ones after it (`seller`, which the formatter resolves once), so the baseline
     * is the second scan and not the first: comparing the first with any later one measures that warm-up and not the switch.
     */
    public function test_with_the_kill_switch_off_a_one_market_scan_answers_byte_for_byte_what_it_always_answered(): void
    {
        $this->sellerProduct(1, 'SKU100', 'SA');
        $this->scan(['code' => 'SKU100']);
        $asItShips = $this->scan(['code' => 'SKU100'])->getContent();

        $this->offMeansOneMarket();

        $this->assertSame($asItShips, $this->scan(['code' => 'SKU100'])->getContent(), 'the same keys, in the same order, with the same values');
    }

    // ---- with the kill switch on: off means one market -------------------------------------------------------------------------------

    public function test_off_means_one_market_so_the_added_market_cannot_answer_a_scan(): void
    {
        $this->sellerProduct(1, 'SKU100', 'AE');
        $this->sellerProduct(2, 'SKU100', 'SA');
        $this->offMeansOneMarket();

        $this->assertSame(2, $this->scannedId(['code' => 'SKU100']), 'the market the platform started as answers, whatever the lower id holds');
    }

    public function test_a_code_only_the_added_market_holds_answers_nothing_rather_than_the_wrong_market_s_product(): void
    {
        $this->sellerProduct(1, 'SKU100', 'AE');
        $this->offMeansOneMarket();

        $this->assertNull($this->scannedId(['code' => 'SKU100']));
        $this->assertSame([], $this->scan(['code' => 'SKU100'])->getData(true), 'an empty list, which is what a code nobody holds has always answered');
    }

    public function test_a_scan_that_names_the_added_market_is_still_answered_from_the_market_left_behind(): void
    {
        $this->sellerProduct(1, 'SKU100', 'AE');
        $this->sellerProduct(2, 'SKU100', 'SA');
        $this->offMeansOneMarket();

        $this->assertSame(2, $this->scannedId(['code' => 'SKU100', 'country' => 'AE']), 'a market a request names is a request, never a grant');
    }

    // ---- the shape of the answer is still the one off gives --------------------------------------------------------------------------

    public function test_the_barcode_is_still_never_looked_at(): void
    {
        $this->sellerProduct(1, 'SKU100', 'SA', barcode: '6291234567890');
        $this->offMeansOneMarket();

        $this->assertNull($this->scannedId(['code' => '6291234567890']), 'the feature is off: only the code answers');
    }

    public function test_two_products_of_the_market_left_behind_sharing_a_code_still_answer_with_one_product_and_never_an_ambiguity(): void
    {
        $this->sellerProduct(1, 'SKU100', 'SA');
        $this->sellerProduct(2, 'SKU100', 'SA');
        $this->offMeansOneMarket();

        $response = $this->scan(['code' => 'SKU100']);

        $this->assertSame(200, $response->getStatusCode(), 'off answers the first product, as it always did, and never 409s');
        $this->assertArrayNotHasKey('errors', (array) $response->getData(true));
        $this->assertSame(1, $this->scannedId(['code' => 'SKU100']));
    }

    public function test_a_country_the_request_names_is_still_not_validated_because_the_feature_is_off(): void
    {
        $this->offMeansOneMarket();

        $this->assertTrue($this->scope->isScanCountryAllowed('NOT_A_COUNTRY'), 'off reads nothing of the request, so there is nothing to refuse');
        $this->assertNull($this->scope->countryOfScan('AE'), 'and the scan still belongs to no country the caller can see');
    }

    // ---- what it deliberately does not change -----------------------------------------------------------------------------------------

    public function test_uniqueness_stays_global_because_refusing_a_free_code_is_safe_and_accepting_a_held_one_is_not(): void
    {
        $this->sellerProduct(1, 'SKU100', 'AE');
        $this->offMeansOneMarket();

        $this->assertTrue($this->scope->isCodeTaken(code: 'SKU100', country: 'SA'), 'a code the added market holds is still taken');
        $this->assertFalse($this->scope->enabled(), 'and the feature itself is still off');
    }

    public function test_it_is_inert_before_the_products_country_column_exists(): void
    {
        $this->sellerProduct(1, 'SKU100', 'SA');
        $this->dropStorefrontCountryColumn();
        $this->offMeansOneMarket();

        $this->assertSame(1, $this->scannedId(['code' => 'SKU100']), 'no column, so no market was ever added and there is nothing to keep out');
    }

    public function test_turning_the_scope_on_again_answers_the_added_market_its_own_product(): void
    {
        $this->sellerProduct(1, 'SKU100', 'AE');
        $this->sellerProduct(2, 'SKU100', 'SA');
        $this->offMeansOneMarket();
        $this->assertSame(2, $this->scannedId(['code' => 'SKU100']));

        config(['gcc.catalog.country_scoped_codes' => true]);

        $this->assertSame(1, $this->scannedId(['code' => 'SKU100', 'country' => 'AE']), 'turning it off is reversible: the rows were never touched');
    }
}
