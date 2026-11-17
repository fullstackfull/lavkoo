<?php

namespace Tests\Feature\Catalog;

use App\Http\Controllers\RestAPI\v3\seller\POSController;
use App\Models\Seller;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\BuildsProductCatalog;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * C1: the seller app scans a code at the point of sale (GET api/v3/seller/pos/products?code=).
 *
 * With the switch off the answer is today's: the first product of the seller whose code is the scanned value (never the barcode), else an
 * empty list. With the switch on it looks at the code and the barcode, narrows to the country the request names (the default storefront when
 * it names none), and when more than one product still answers it says so instead of choosing one.
 */
class PosScanCodeScopeTest extends TestCase
{
    use BuildsCodeScopeCatalog, BuildsProductCatalog, RestoresLanguageFiles;

    private const SELLER_ID = 5;

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
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    /** @param array<string, mixed> $extra */
    private function sellerProduct(int $id, string $code, string $country = 'SA', ?string $barcode = null, int $seller = self::SELLER_ID, array $extra = []): int
    {
        $this->insertProduct(id: $id, name: 'Serum ' . $id, userId: $seller, addedBy: 'seller', extra: $extra + ['code' => $code, 'barcode' => $barcode, 'storefront_country' => $country]);

        return $id;
    }

    /** @param array<string, string> $query */
    private function scan(array $query): JsonResponse
    {
        $request = Request::create('/api/v3/seller/pos/products', 'GET', $query);
        $request['seller'] = (new Seller())->forceFill(['id' => self::SELLER_ID]);

        return app(POSController::class)->get_product_by_barcode($request);
    }

    private function scannedId(array $query): ?int
    {
        $answer = $this->scan($query)->getData(true);

        return $answer === [] ? null : (int) $answer['id'];
    }

    // ---- the switch off: today's answer ----------------------------------------------------------------------------------------

    public function test_with_the_switch_off_the_first_product_with_the_code_is_the_answer(): void
    {
        $first = $this->sellerProduct(1, 'SKU100', 'SA');
        $this->sellerProduct(2, 'SKU100', 'KW');

        $this->assertSame($first, $this->scannedId(['code' => 'SKU100']), 'today the lowest id answers, whatever its country');
    }

    public function test_with_the_switch_off_the_barcode_is_never_looked_at(): void
    {
        $this->sellerProduct(1, 'SKU100', 'SA', barcode: '6291234567890');

        $this->assertNull($this->scannedId(['code' => '6291234567890']));
    }

    public function test_with_the_switch_off_the_answer_is_an_empty_list_when_nothing_matches(): void
    {
        $this->assertSame(200, $this->scan(['code' => 'NOPE'])->getStatusCode());
        $this->assertSame([], $this->scan(['code' => 'NOPE'])->getData(true));
    }

    public function test_with_the_switch_off_only_the_sellers_own_products_answer(): void
    {
        $this->sellerProduct(1, 'SKU100', 'SA', seller: 9);
        $own = $this->sellerProduct(2, 'SKU100', 'SA');

        $this->assertSame($own, $this->scannedId(['code' => 'SKU100']));
    }

    public function test_with_the_switch_off_a_country_in_the_request_is_ignored_even_when_it_is_nonsense(): void
    {
        $first = $this->sellerProduct(1, 'SKU100', 'SA');
        $this->sellerProduct(2, 'SKU100', 'KW');

        $this->assertSame($first, $this->scannedId(['code' => 'SKU100', 'country' => 'KW']));
        $this->assertSame($first, $this->scannedId(['code' => 'SKU100', 'country' => 'XX']));
    }

    public function test_a_missing_column_acts_as_off(): void
    {
        $first = $this->sellerProduct(1, 'SKU100', 'SA', barcode: '6291234567890');
        $this->scopeCodesByCountry();
        $this->dropStorefrontCountryColumn();

        $this->assertSame($first, $this->scannedId(['code' => 'SKU100', 'country' => 'XX']));
        $this->assertNull($this->scannedId(['code' => '6291234567890']));
    }

    // ---- the switch on ---------------------------------------------------------------------------------------------------------

    public function test_with_the_switch_on_a_scan_without_a_country_is_the_default_storefronts(): void
    {
        $this->scopeCodesByCountry();
        $saudi = $this->sellerProduct(1, 'SKU100', 'SA');
        $this->sellerProduct(2, 'SKU100', 'KW');

        $this->assertSame($saudi, $this->scannedId(['code' => 'SKU100']));
    }

    public function test_with_the_switch_on_the_country_of_the_request_picks_the_product_of_that_country(): void
    {
        $this->scopeCodesByCountry();
        $this->sellerProduct(1, 'SKU100', 'SA');
        $kuwaiti = $this->sellerProduct(2, 'SKU100', 'KW');

        $this->assertSame($kuwaiti, $this->scannedId(['code' => 'SKU100', 'country' => 'KW']));
    }

    public function test_with_the_switch_on_a_product_of_another_country_is_not_found_rather_than_picked(): void
    {
        $this->scopeCodesByCountry();
        $this->sellerProduct(1, 'SKU100', 'KW');

        $this->assertNull($this->scannedId(['code' => 'SKU100']), 'the default storefront has no such product');
        $this->assertSame(200, $this->scan(['code' => 'SKU100'])->getStatusCode());
    }

    public function test_with_the_switch_on_the_barcode_is_scanned_too(): void
    {
        $this->scopeCodesByCountry();
        $product = $this->sellerProduct(1, 'SKU100', 'SA', barcode: '6291234567890');

        $this->assertSame($product, $this->scannedId(['code' => '6291234567890']));
        $this->assertSame($product, $this->scannedId(['code' => 'SKU100']));
    }

    public function test_with_the_switch_on_a_scan_without_a_usable_code_finds_nothing_instead_of_every_product_without_a_barcode(): void
    {
        $this->scopeCodesByCountry();
        $this->sellerProduct(1, 'SKU100', 'SA');
        $this->sellerProduct(2, 'SKU200', 'SA');
        $this->sellerProduct(3, 'SKU300', 'SA', barcode: '');

        foreach ([[], ['code' => ''], ['code' => ['SKU100']]] as $query) {
            $response = $this->scan($query);

            $this->assertSame(200, $response->getStatusCode(), json_encode($query));
            $this->assertSame([], $response->getData(true), json_encode($query));
        }
    }

    public function test_with_the_switch_on_a_code_that_is_the_number_zero_is_still_a_code(): void
    {
        $this->scopeCodesByCountry();
        $product = $this->sellerProduct(1, '0', 'SA');

        $this->assertSame($product, $this->scannedId(['code' => '0']));
    }

    public function test_with_the_switch_on_only_the_sellers_own_products_answer(): void
    {
        $this->scopeCodesByCountry();
        $this->sellerProduct(1, 'SKU100', 'SA', seller: 9);

        $this->assertNull($this->scannedId(['code' => 'SKU100']));
    }

    public function test_a_single_match_is_the_same_product_data_as_with_the_switch_off(): void
    {
        $this->sellerProduct(1, 'SKU100', 'SA');
        $off = $this->scan(['code' => 'SKU100'])->getContent();

        $this->scopeCodesByCountry();
        Cache::flush();

        $this->assertSame($off, $this->scan(['code' => 'SKU100'])->getContent());
    }

    public function test_two_products_that_still_answer_are_an_ambiguity_not_a_choice(): void
    {
        $this->scopeCodesByCountry();
        $this->sellerProduct(1, 'SKU100', 'SA');
        $this->sellerProduct(2, 'SKU200', 'SA', barcode: 'SKU100');

        $response = $this->scan(['code' => 'SKU100']);

        $this->assertSame(409, $response->getStatusCode());
        $error = $response->getData(true)['errors'][0];
        $this->assertSame('ambiguous_code', $error['code']);
        $this->assertNotEmpty($error['message']);
        $this->assertSame(
            [['id' => 1, 'name' => 'Serum 1', 'country' => 'SA'], ['id' => 2, 'name' => 'Serum 2', 'country' => 'SA']],
            $error['candidates'],
        );
    }

    public function test_a_code_held_twice_inside_one_country_is_an_ambiguity_too(): void
    {
        $this->scopeCodesByCountry();
        $this->sellerProduct(1, 'SKU100', 'KW');
        $this->sellerProduct(2, 'SKU100', 'KW');

        $this->assertSame(409, $this->scan(['code' => 'SKU100', 'country' => 'KW'])->getStatusCode());
    }

    public function test_the_ambiguity_names_only_the_candidates_of_the_narrowed_country(): void
    {
        $this->scopeCodesByCountry();
        $this->sellerProduct(1, 'SKU100', 'SA');
        $this->sellerProduct(2, 'SKU100', 'SA');
        $this->sellerProduct(3, 'SKU100', 'KW');

        $candidates = $this->scan(['code' => 'SKU100'])->getData(true)['errors'][0]['candidates'];

        $this->assertSame([1, 2], array_column($candidates, 'id'));
    }

    public function test_a_country_that_is_not_configured_is_refused_with_the_switch_on(): void
    {
        $this->scopeCodesByCountry();
        $this->sellerProduct(1, 'SKU100', 'SA');

        foreach (['XX', 'kw', '1', ''] as $country) {
            $response = $this->scan(['code' => 'SKU100', 'country' => $country]);
            if ($country === '') {
                $this->assertSame(200, $response->getStatusCode(), 'an empty country is no country');

                continue;
            }
            $this->assertSame(422, $response->getStatusCode(), $country);
            $this->assertSame('country', $response->getData(true)['errors'][0]['code']);
        }
    }

    public function test_a_country_that_is_a_list_is_refused_with_the_switch_on(): void
    {
        $this->scopeCodesByCountry();

        $this->assertSame(422, $this->scan(['code' => 'SKU100', 'country' => ['KW']])->getStatusCode());
    }

    public function test_every_configured_country_can_be_asked_for_even_before_it_opens(): void
    {
        $this->scopeCodesByCountry();
        $this->assertFalse(config('gcc.storefronts.countries.KW.active'));
        $this->sellerProduct(1, 'SKU100', 'KW');

        $this->assertSame(1, $this->scannedId(['code' => 'SKU100', 'country' => 'KW']));
    }

    public function test_the_messages_of_the_two_refusals_exist_in_every_language_file(): void
    {
        foreach (['en', 'sa', 'ae'] as $locale) {
            $messages = require base_path("resources/lang/{$locale}/new-messages.php");

            $this->assertArrayHasKey('the_scanned_code_matches_more_than_one_product', $messages, $locale);
            $this->assertArrayHasKey('the_country_must_be_one_of_the_configured_storefront_countries', $messages, $locale);
        }
    }
}
