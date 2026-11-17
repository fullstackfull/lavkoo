<?php

namespace Tests\Support;

use App\Services\Catalog\ProductCodeScope;
use Illuminate\Support\Facades\DB;

/**
 * The six-country matrix of the country-scoped codes (GCC-14b): every case is generated from the countries the platform is configured with, never
 * from a list written in a test, so a country added to config/gcc.php is covered by the next run. A test of the matrix loops over its cases inside one
 * method and collects what failed, so the catalogue is built once per test and a failure names the countries it concerns.
 */
trait BuildsCountryMatrix
{
    /** @return list<string> every configured country, open or not, in the order of the configuration */
    protected function matrixCountries(): array
    {
        return array_keys((array) config('gcc.storefronts.countries'));
    }

    /** @return list<array{0: string, 1: string}> every ordered pair of different countries: the code is held in the first and asked for in the second */
    protected function orderedPairs(): array
    {
        $pairs = [];
        foreach ($this->matrixCountries() as $from) {
            foreach ($this->matrixCountries() as $to) {
                if ($from !== $to) {
                    $pairs[] = [$from, $to];
                }
            }
        }

        return $pairs;
    }

    /** A code of nine digits, so that it passes the rules of every gate (the seller app v2 asks for digits): one for each ordered pair. */
    protected function codeOfPair(string $from, string $to): string
    {
        return '7' . str_pad((string) $this->pairNumber($from, $to), 8, '0', STR_PAD_LEFT);
    }

    /** A barcode of thirteen digits: one for each ordered pair. */
    protected function barcodeOfPair(string $from, string $to): string
    {
        return '629' . str_pad((string) $this->pairNumber($from, $to), 10, '0', STR_PAD_LEFT);
    }

    /** A code of nine digits for each country (the number of the country, so it never equals the code of a pair). */
    protected function codeOfCountry(string $country, string $series = '8'): string
    {
        return $series . str_pad((string) $this->countryNumber($country), 8, '0', STR_PAD_LEFT);
    }

    protected function barcodeOfCountry(string $country, string $series = '8'): string
    {
        return '629' . $series . str_pad((string) $this->countryNumber($country), 9, '0', STR_PAD_LEFT);
    }

    private function countryNumber(string $country): int
    {
        return (int) array_search($country, $this->matrixCountries(), true) + 1;
    }

    private function pairNumber(string $from, string $to): int
    {
        return $this->countryNumber($from) * 100 + $this->countryNumber($to);
    }

    /** What the add path of the admin saves a new product's country as: the same answer the validation rule is asked for. */
    protected function countrySavedForAdmin(string $requested): string
    {
        return app(ProductCodeScope::class)->countryOfNewProduct(addedBy: 'admin', requestedCountry: $requested);
    }

    /** One product for every ordered pair: the code and the barcode of the pair, held in the first country of it. */
    protected function insertHoldersOfEveryPair(array $extra = []): void
    {
        $rows = [];
        foreach ($this->orderedPairs() as [$from, $to]) {
            $rows[] = array_merge([
                'name' => "Holder {$from} for {$to}", 'code' => $this->codeOfPair($from, $to), 'barcode' => $this->barcodeOfPair($from, $to), 'storefront_country' => $from,
            ], $extra);
        }
        DB::table('products')->insert($rows);
    }

    /** @param list<string> $failures what the cases of a matrix found wrong, each naming its case */
    protected function assertMatrixHolds(array $failures): void
    {
        $this->assertSame([], $failures, count($failures) . " case(s) of the matrix failed:\n" . implode("\n", $failures));
    }

    /**
     * Records a case of the matrix that did not give the expected answer.
     *
     * @param list<string> $failures
     */
    protected function matrixCase(array &$failures, bool $expected, bool $actual, string $case): void
    {
        if ($expected !== $actual) {
            $failures[] = $case . ' (expected ' . ($expected ? 'yes' : 'no') . ', got ' . ($actual ? 'yes' : 'no') . ')';
        }
    }
}
