<?php

namespace App\Services\Catalog;

use App\Services\Storefront\ProductCountry;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What an operator needs to know before the country-scoped SKU and barcode switch is turned on: how the catalogue is spread over the
 * countries, which codes are held twice inside one country (the switch refuses those from then on, and every lookup would meet an
 * ambiguity), which are shared by two countries (what the switch allows), and which sites still read a code as one product. Read only.
 */
final class ProductCodeReadiness
{
    private const TABLE = 'products';

    /**
     * @return array{
     *     flag: bool,
     *     storefronts_enabled: bool,
     *     column: bool,
     *     countries: list<array{country: string, products: int}>,
     *     unconfigured_countries: list<array{country: string, products: int}>,
     *     empty_country: int,
     *     within_country: list<array{kind: string, country: string, value: string, products: int}>,
     *     across_countries: list<array{kind: string, value: string, countries: list<string>}>,
     *     sites: list<array{id: string, where: string, status: string, note: string}>,
     *     survey: array{covered: int, labelled: int, id_keyed: int, open: int},
     *     indexes: list<array{kind: string, name: string, applied: bool, blocking_groups: int, absent_values: int}>,
     *     ready: bool
     * }
     */
    public function report(): array
    {
        $column = StorefrontCatalog::columnReady();
        $report = [
            'flag' => filter_var(config(ProductCodeScope::FLAG, false), FILTER_VALIDATE_BOOLEAN),
            'storefronts_enabled' => (bool) config('gcc.storefronts.enabled'),
            'column' => $column,
            'countries' => [],
            'unconfigured_countries' => [],
            'empty_country' => 0,
            'within_country' => [],
            'across_countries' => [],
            'sites' => ProductCodeSites::notCovered(),
            'survey' => ProductCodeSites::counts(),
            'indexes' => [],
            'ready' => false,
        ];
        if (!$column) {
            return $report;
        }

        [$report['countries'], $report['unconfigured_countries']] = $this->countryCounts();
        $report['empty_country'] = $this->productsWithoutCountry();
        $kinds = $this->kindsOfCode();
        $report['within_country'] = $kinds->flatMap(fn (string $kind): array => $this->heldTwiceInOneCountry($kind))->all();
        $report['across_countries'] = $kinds->flatMap(fn (string $kind): array => $this->sharedByCountries($kind))->all();
        $report['indexes'] = $kinds->map(fn (string $kind): array => $this->indexReadiness($kind))->all();
        $report['ready'] = $report['empty_country'] === 0 && $report['within_country'] === [];

        return $report;
    }

    /**
     * Whether the database could take the unique index for one kind of code, counted with the database's own comparison — which on a
     * case-insensitive collation reads 'A1' and 'a1' as one value, so it can block where the PHP gates would not. Read only: it creates
     * nothing. `absent_values` is the rows the index leaves out (no code, or an empty one), which is why it does not collapse them.
     *
     * @return array{kind: string, name: string, applied: bool, blocking_groups: int, absent_values: int}
     */
    private function indexReadiness(string $kind): array
    {
        return [
            'kind' => $kind,
            'name' => ProductCodeIndex::indexName($kind),
            'applied' => ProductCodeIndex::applied($kind),
            'blocking_groups' => ProductCodeIndex::blockers($kind),
            'absent_values' => DB::table(self::TABLE)->where(static fn ($query) => $query->whereNull($kind)->orWhere($kind, ''))->count(),
        ];
    }
    /** @return Collection<int, string> the columns that hold a lookup value: the barcode column is part of the baseline schema, but two services guard it, so it is not assumed */
    private function kindsOfCode(): Collection
    {
        return collect(['code', 'barcode'])->filter(static fn (string $kind): bool => Schema::hasColumn(self::TABLE, $kind))->values();
    }

    /** @return array{0: list<array{country: string, products: int}>, 1: list<array{country: string, products: int}>} */
    private function countryCounts(): array
    {
        $counts = DB::table(self::TABLE)
            ->select(StorefrontCatalog::COLUMN . ' as country', DB::raw('count(*) as products'))
            ->whereNotNull(StorefrontCatalog::COLUMN)->where(StorefrontCatalog::COLUMN, '!=', '')
            ->groupBy(StorefrontCatalog::COLUMN)
            ->pluck('products', 'country');

        $configured = array_map(static fn (string $country): array => ['country' => $country, 'products' => (int) ($counts[$country] ?? 0)], ProductCountry::options());
        $unconfigured = $counts->except(ProductCountry::options())->map(static fn ($products, string $country): array => ['country' => $country, 'products' => (int) $products])->sortKeys()->values()->all();

        return [$configured, $unconfigured];
    }

    private function productsWithoutCountry(): int
    {
        return DB::table(self::TABLE)->where(static fn ($query) => $query->whereNull(StorefrontCatalog::COLUMN)->orWhere(StorefrontCatalog::COLUMN, ''))->count();
    }

    /** @return list<array{kind: string, country: string, value: string, products: int}> */
    private function heldTwiceInOneCountry(string $kind): array
    {
        return DB::table(self::TABLE)
            ->select(StorefrontCatalog::COLUMN . ' as country', $kind . ' as value', DB::raw('count(*) as products'))
            ->whereNotNull($kind)->where($kind, '!=', '')
            ->groupBy(StorefrontCatalog::COLUMN, $kind)
            ->havingRaw('count(*) > 1')
            ->orderBy(StorefrontCatalog::COLUMN)->orderBy($kind)
            ->get()
            ->map(static fn (object $row): array => ['kind' => $kind, 'country' => (string) $row->country, 'value' => (string) $row->value, 'products' => (int) $row->products])
            ->all();
    }

    /** @return list<array{kind: string, value: string, countries: list<string>}> */
    private function sharedByCountries(string $kind): array
    {
        return DB::table(self::TABLE)
            ->whereNotNull($kind)->where($kind, '!=', '')
            ->groupBy($kind)
            ->havingRaw('count(distinct ' . StorefrontCatalog::COLUMN . ') > 1')
            ->orderBy($kind)
            ->pluck($kind)
            ->map(fn (string $value): array => ['kind' => $kind, 'value' => $value, 'countries' => $this->countriesHolding(kind: $kind, value: $value)])
            ->all();
    }

    /** @return list<string> */
    private function countriesHolding(string $kind, string $value): array
    {
        return DB::table(self::TABLE)->where($kind, $value)->distinct()->orderBy(StorefrontCatalog::COLUMN)->pluck(StorefrontCatalog::COLUMN)->map(static fn ($country): string => (string) $country)->all();
    }
}
