<?php

namespace App\Services\Storefront;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Schema;

/**
 * D1-10: a refund row names the money and the company of the order it gives back, copied from the order's own record when the row is made. The order's record is whatever
 * the sale wrote (the storefront, its currency and rate, the selling company), so a refund read after the rates or the settings changed still says what was sold in and by
 * whom. A column is returned only when the order has a value and the table has the column, so an order with no record (placed before, or with the switches off) and a
 * database that has not run the migration both give nothing, and the row is made exactly as it always was.
 */
#[Singleton]
final class RefundOrderMoney
{
    private const COLUMNS = ['storefront_country', 'currency_code', 'currency_rate', 'legal_entity_id'];

    /** @var array<string, bool> */
    private array $tableHasColumn = [];

    /**
     * @param object|array<string, mixed>|null $order
     *
     * @return array<string, mixed> the columns of the refund table that the order's record fills
     */
    public function of(object|array|null $order, string $table): array
    {
        if ($order === null) {
            return [];
        }

        $values = [];

        foreach (self::COLUMNS as $column) {
            $value = data_get($order, $column);

            if ($value !== null && $value !== '' && $this->hasColumn(table: $table, column: $column)) {
                $values[$column] = $value;
            }
        }

        return $values;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->tableHasColumn["{$table}.{$column}"] ??= Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }
}
