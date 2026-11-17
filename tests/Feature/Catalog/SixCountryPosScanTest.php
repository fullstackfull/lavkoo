<?php

namespace Tests\Feature\Catalog;

use App\Http\Controllers\RestAPI\v3\seller\POSController;
use App\Models\Seller;
use App\Services\Storefront\ProductCountry;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\BuildsCountryMatrix;
use Tests\Support\BuildsProductCatalog;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * C1 across the six countries: the seller app scans a code at the point of sale (GET api/v3/seller/pos/products?code=). For every ordered pair of
 * countries a seller holds the same code and barcode in both; a scan answers the product of the country it names, the default storefront's when it
 * names none, 409 for a code held twice inside the country, 422 for a country that is not configured and nothing for a code that is missing or
 * empty. With the switch off the first product with the code answers, whatever its country, as today.
 */
class SixCountryPosScanTest extends TestCase
{
    use BuildsCodeScopeCatalog, BuildsCountryMatrix, BuildsProductCatalog, RestoresLanguageFiles;

    private const SELLER_ID = 5;

    /** @var array<string, int> the product of each pair and country, as "from>to@country" */
    private array $ids = [];

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
        Cache::flush();
        $id = 0;
        foreach ($this->orderedPairs() as [$from, $to]) {
            foreach ([$from, $to] as $country) {
                $id++;
                $this->insertProduct(id: $id, name: "Serum {$from}>{$to}@{$country}", userId: self::SELLER_ID, addedBy: 'seller', extra: [
                    'code' => $this->codeOfPair($from, $to), 'barcode' => $this->barcodeOfPair($from, $to), 'storefront_country' => $country,
                ]);
                $this->ids["{$from}>{$to}@{$country}"] = $id;
            }
        }
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    /** @param array<string, mixed> $query */
    private function scan(array $query): JsonResponse
    {
        $request = Request::create('/api/v3/seller/pos/products', 'GET', $query);
        $request['seller'] = (new Seller())->forceFill(['id' => self::SELLER_ID]);

        return app(POSController::class)->get_product_by_barcode($request);
    }

    /** The id of the one product a scan answers, null for an empty answer, and 0 for anything else (a refusal, an ambiguity). */
    private function scannedId(array $query): ?int
    {
        $response = $this->scan($query);
        $answer = $response->getData(true);

        return $response->getStatusCode() !== 200 ? 0 : ($answer === [] ? null : (int) $answer['id']);
    }

    private function idOf(string $from, string $to, string $country): int
    {
        return $this->ids["{$from}>{$to}@{$country}"];
    }

    public function test_with_the_switch_on_a_scan_answers_the_product_of_the_country_it_names_by_code_and_by_barcode(): void
    {
        $this->scopeCodesByCountry();
        $failures = [];
        foreach ($this->orderedPairs() as [$from, $to]) {
            foreach ([$from, $to] as $country) {
                foreach (['code' => $this->codeOfPair($from, $to), 'barcode' => $this->barcodeOfPair($from, $to)] as $kind => $value) {
                    $this->matrixCase($failures, true, $this->scannedId(['code' => $value, 'country' => $country]) === $this->idOf($from, $to, $country), "the {$kind} of {$from}/{$to} scanned for {$country}");
                }
            }
            foreach (array_diff($this->matrixCountries(), [$from, $to]) as $other) {
                $this->matrixCase($failures, true, $this->scannedId(['code' => $this->codeOfPair($from, $to), 'country' => $other]) === null, "the code of {$from}/{$to} scanned for {$other}, which holds none: nothing");
            }
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_on_a_scan_that_names_no_country_answers_the_default_storefronts_product(): void
    {
        $this->scopeCodesByCountry();
        $default = ProductCountry::defaultCountry();
        $failures = [];
        foreach ($this->orderedPairs() as [$from, $to]) {
            $expected = in_array($default, [$from, $to], true) ? $this->idOf($from, $to, $default) : null;
            $this->matrixCase($failures, true, $this->scannedId(['code' => $this->codeOfPair($from, $to)]) === $expected, "the code of {$from}/{$to} scanned with no country (the default storefront is {$default})");
            $this->matrixCase($failures, true, $this->scannedId(['code' => $this->codeOfPair($from, $to), 'country' => '']) === $expected, "the same with an empty country");
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_on_a_code_held_twice_inside_a_country_is_an_ambiguity_there_and_only_there(): void
    {
        $this->scopeCodesByCountry();
        $default = ProductCountry::defaultCountry();
        $failures = [];
        $next = 1000;
        foreach ($this->matrixCountries() as $twin) {
            $code = $this->codeOfCountry($twin, '5');
            $twins = [];
            foreach ([$twin, $twin, ...array_diff($this->matrixCountries(), [$twin])] as $country) {
                $next++;
                $this->insertProduct(id: $next, name: "Twin {$twin}@{$country}", userId: self::SELLER_ID, addedBy: 'seller', extra: ['code' => $code, 'storefront_country' => $country]);
                $twins[$country][] = $next;
            }

            foreach ($this->matrixCountries() as $country) {
                $response = $this->scan(['code' => $code, 'country' => $country]);
                $expectedStatus = $country === $twin ? 409 : 200;
                $this->matrixCase($failures, true, $response->getStatusCode() === $expectedStatus, "{$code} held twice in {$twin}, scanned for {$country}: status {$response->getStatusCode()}, expected {$expectedStatus}");
                if ($country === $twin) {
                    $candidates = array_column($response->getData(true)['errors'][0]['candidates'] ?? [], 'id');
                    $this->matrixCase($failures, true, $candidates === $twins[$twin], "{$code} held twice in {$twin}: the ambiguity names exactly the two products");
                    $this->matrixCase($failures, true, ($response->getData(true)['errors'][0]['code'] ?? null) === 'ambiguous_code', "{$code} held twice in {$twin}: the error is ambiguous_code");
                }
            }
            $this->matrixCase($failures, true, $this->scan(['code' => $code])->getStatusCode() === ($twin === $default ? 409 : 200), "{$code} held twice in {$twin}, scanned with no country (the default storefront is {$default})");
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_on_a_country_that_is_not_configured_is_refused_and_a_scan_without_a_usable_code_finds_nothing(): void
    {
        $this->scopeCodesByCountry();
        [$from, $to] = $this->orderedPairs()[0];
        $failures = [];
        foreach (['XX', strtolower($from), '1', ['x' => $from], [$from]] as $country) {
            $response = $this->scan(['code' => $this->codeOfPair($from, $to), 'country' => $country]);
            $this->matrixCase($failures, true, $response->getStatusCode() === 422 && ($response->getData(true)['errors'][0]['code'] ?? null) === 'country', 'the country ' . json_encode($country) . ' is refused with 422');
        }
        foreach ($this->matrixCountries() as $country) {
            foreach ([[], ['code' => ''], ['code' => ['x']], ['code' => null]] as $query) {
                $response = $this->scan($query + ['country' => $country]);
                $this->matrixCase($failures, true, $response->getStatusCode() === 200 && $response->getData(true) === [], 'scanned for ' . $country . ' with the code ' . json_encode($query['code'] ?? '(missing)') . ': nothing');
            }
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_off_the_first_product_with_the_code_answers_whatever_its_country_and_the_barcode_and_the_country_are_ignored(): void
    {
        $failures = [];
        foreach ($this->orderedPairs() as [$from, $to]) {
            $first = $this->idOf($from, $to, $from);
            $this->matrixCase($failures, true, $this->scannedId(['code' => $this->codeOfPair($from, $to)]) === $first, "the code of {$from}/{$to}: the lowest id answers");
            $this->matrixCase($failures, true, $this->scannedId(['code' => $this->codeOfPair($from, $to), 'country' => $to]) === $first, "the code of {$from}/{$to} with country {$to}: the country is ignored");
            $this->matrixCase($failures, true, $this->scannedId(['code' => $this->codeOfPair($from, $to), 'country' => 'XX']) === $first, "the code of {$from}/{$to} with a nonsense country: ignored, no refusal");
            $this->matrixCase($failures, true, $this->scannedId(['code' => $this->barcodeOfPair($from, $to)]) === null, "the barcode of {$from}/{$to}: never looked at");
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_the_products_are_the_sellers_own_in_every_country(): void
    {
        $this->scopeCodesByCountry();
        DB::table('products')->update(['user_id' => 99]);

        $this->assertNull($this->scannedId(['code' => $this->codeOfPair(...$this->orderedPairs()[0]), 'country' => $this->orderedPairs()[0][0]]));
    }
}
