<?php

namespace App\Services\Storefront;

use App\Services\Payments\OrderPaymentAccount;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Schema;

/**
 * D1-10: a refund row names the money and the company of the order it gives back, copied from the order's own record when the row is made, and the merchant
 * account and company that took the payment, copied from the stamp that payment carries. The order's record is whatever the sale wrote (the storefront, its
 * currency and rate, the selling company), so a refund read after the rates or the settings changed still says what was sold in and by whom; the payment's
 * stamp is whatever the payment was started against, so it still says who took the money after the account has been edited, disabled or deleted.
 *
 * The company that sold and the company whose merchant account took the money are two facts, and both are kept: `legal_entity_id` is the order's selling
 * company and `payment_legal_entity_id` is the one the payment was taken by. A column is returned only when there is a value for it and the table has the
 * column, so an order with no record (placed before, or with the switches off), a payment with no stamp (every payment while per-company accounts are off,
 * and every cash-on-delivery order, which has no payment at all) and a database that has not run the migrations all give nothing, and the row is made
 * exactly as it always was.
 */
#[Singleton]
final class RefundOrderMoney
{
    private const ORDER_COLUMNS = ['storefront_country', 'currency_code', 'currency_rate', 'legal_entity_id'];

    private const ACCOUNT_COLUMN = 'payment_gateway_account_id';

    private const PAYMENT_ENTITY_COLUMN = 'payment_legal_entity_id';

    /** @var array<string, bool> */
    private array $tableHasColumn = [];

    public function __construct(private readonly OrderPaymentAccount $paymentAccount)
    {
    }

    /**
     * @param object|array<string, mixed>|null $order
     *
     * @return array<string, mixed> the columns of the refund table that the order and its payment fill
     */
    public function of(object|array|null $order, string $table): array
    {
        if ($order === null) {
            return [];
        }

        $values = [];

        foreach (self::ORDER_COLUMNS as $column) {
            $value = data_get($order, $column);

            if ($this->isRecorded($value) && $this->hasColumn(table: $table, column: $column)) {
                $values[$column] = $value;
            }
        }

        return $values + $this->ofThePayment(order: $order, table: $table);
    }

    /**
     * The account the order's payment was taken through and the company that took it, from the payment's own stamp.
     *
     * The payment is looked for only when the refund table can hold the answer, so a database that has not run this migration asks nothing of the payments
     * table and issues exactly the statements it issued before.
     *
     * @param object|array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function ofThePayment(object|array $order, string $table): array
    {
        $wantsAccount = $this->hasColumn(table: $table, column: self::ACCOUNT_COLUMN);
        $wantsEntity = $this->hasColumn(table: $table, column: self::PAYMENT_ENTITY_COLUMN);

        if (!$wantsAccount && !$wantsEntity) {
            return [];
        }

        $stamp = $this->paymentAccount->of(order: $order);

        if ($stamp === null) {
            return [];
        }

        return array_filter([
            self::ACCOUNT_COLUMN => $wantsAccount ? $stamp->accountId : null,
            self::PAYMENT_ENTITY_COLUMN => $wantsEntity ? $stamp->companyId : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function isRecorded(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->tableHasColumn["{$table}.{$column}"] ??= Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }
}
