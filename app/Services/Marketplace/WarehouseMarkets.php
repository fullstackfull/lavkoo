<?php

namespace App\Services\Marketplace;

use App\Services\Storefront\StorefrontMarketRows;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which markets a warehouse actually holds stock for, derived from its own shelves (A1-18).
 *
 * `inventory_locations` carries a market of its own since `2026_12_27_100000`. The marketplace's `warehouses` table does not, and giving it
 * one is NOT this class's business: whether one warehouse may hold two markets' stock is the owner's open question on A1-18, and a single
 * `storefront_country` column would answer it -- storing one market forbids two, deriving nothing allows two silently. Either way a
 * restricted employee SAW every shelf, which is a real leak whichever answer comes back.
 *
 * So the market of a warehouse is read off what is ON it: the markets of the products whose units it holds. That narrowing presupposes
 * nothing. A warehouse holding one market's stock belongs to that market; a warehouse holding two is shown to employees of either, which is
 * exactly the shape the owner's answer would take if it were "yes"; and when the answer is "one market only", this reading is still right,
 * because a warehouse's stock will then be of one market anyway.
 *
 * **A warehouse with NO stock at all names no market and is shown to everybody.** That is the honest answer rather than a gap: an empty
 * shelf has nothing to belong to, and hiding one would make a warehouse vanish from the screen of the employee who had just created it.
 *
 * Read with the QUERY BUILDER and never through `WarehouseStock`, which carries `StaffCountryProductRowScope`: a model read of the shelves
 * is already narrowed to the employee's own products, so deriving the markets through it would ask the narrowing to answer the question the
 * narrowing is for, and every warehouse would look like the employee's own.
 *
 * An unmarked product row is the DEFAULT storefront's, which is {@see StorefrontMarketRows}' rule and the reason it is not restated here.
 */
final class WarehouseMarkets
{
    private const SHELVES = 'warehouse_stock';

    private const WAREHOUSES = 'warehouses';

    public function __construct(private readonly StorefrontMarketRows $rows)
    {
    }

    /**
     * Whether a warehouse's market can be derived at all: the shelves are there and the products say which market they belong to.
     *
     * Asked of the columns and not only of the tables, because appending a `where` on a column the table may not have is refused outright
     * by MariaDB, while SQLite reads the unknown identifier as a string and returns the empty answer the narrowing was going to give --
     * which looks like the guard working and is the one failure mode that cannot be told from success.
     */
    public function derivable(): bool
    {
        return Schema::hasTable(self::SHELVES)
            && Schema::hasTable('products')
            && Schema::hasColumn('products', StorefrontMarketRows::COLUMN);
    }

    /**
     * The markets this warehouse holds stock for, in no particular order, or an empty list for an empty shelf.
     *
     * @return list<string>
     */
    public function marketsOf(int|string $warehouseId): array
    {
        if (!$this->derivable()) {
            return [];
        }

        $markets = DB::table(self::SHELVES)
            ->join('products', 'products.id', '=', self::SHELVES . '.product_id')
            ->where(self::SHELVES . '.warehouse_id', $warehouseId)
            ->where(self::SHELVES . '.quantity', '>', 0)
            ->distinct()
            ->pluck('products.' . StorefrontMarketRows::COLUMN)
            ->all();

        $derived = [];

        foreach ($markets as $market) {
            $code = trim((string) $market) === '' ? $this->rows->defaultCountry() : strtoupper(trim((string) $market));

            if (!in_array($code, $derived, true)) {
                $derived[] = $code;
            }
        }

        return $derived;
    }

    /**
     * Narrows a warehouse listing to the ones an employee of these countries may see.
     *
     * A warehouse is kept when it holds stock of one of those countries, or when it holds no stock at all. An employee restricted to
     * nothing sees the empty shelves and no others, which follows from the same rule rather than being a special case.
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     * @param list<string> $countries
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public function narrow(Builder $query, array $countries): Builder
    {
        if (!$this->derivable()) {
            return $query;
        }

        $keep = $this->warehouseIdsHolding($countries);
        $withStock = $this->warehouseIdsWithAnyStock();
        $column = $query->getModel()->qualifyColumn('id');

        return $query->where(static function (Builder $inner) use ($column, $keep, $withStock): void {
            if ($keep !== []) {
                $inner->whereIn($column, $keep);
            } else {
                $inner->whereRaw('1 = 0');
            }

            if ($withStock !== []) {
                $inner->orWhereNotIn($column, $withStock);
            } else {
                $inner->orWhereRaw('1 = 1');
            }
        });
    }

    /**
     * The ids of the warehouses that hold stock of any of these countries.
     *
     * @param list<string> $countries
     * @return list<int>
     */
    private function warehouseIdsHolding(array $countries): array
    {
        if ($countries === []) {
            return [];
        }

        $holdsTheDefault = in_array($this->rows->defaultCountry(), $countries, true);

        return DB::table(self::SHELVES)
            ->join('products', 'products.id', '=', self::SHELVES . '.product_id')
            ->where(self::SHELVES . '.quantity', '>', 0)
            ->where(static function ($inner) use ($countries, $holdsTheDefault): void {
                $inner->whereIn('products.' . StorefrontMarketRows::COLUMN, $countries);

                if ($holdsTheDefault) {
                    $inner->orWhereNull('products.' . StorefrontMarketRows::COLUMN);
                }
            })
            ->distinct()
            ->pluck(self::SHELVES . '.warehouse_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @return list<int> the ids of every warehouse that holds anything at all, so the empty ones can be told from the others */
    private function warehouseIdsWithAnyStock(): array
    {
        return DB::table(self::SHELVES)
            ->where('quantity', '>', 0)
            ->distinct()
            ->pluck('warehouse_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
