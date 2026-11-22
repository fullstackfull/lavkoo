<?php

namespace App\Services\Storefront;

use App\Services\Payments\OrderPaymentAccount;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D1-10, criteria 3, 4 and 6: what a refund hands back, and through which merchant account, worked out from the payment the customer actually made.
 *
 * The refund flow converts the stored amount of a refund at the rate of the day it is paid out (`usdToDefaultCurrency`), so a sale made when the riyal
 * stood at 3.75 and refunded when it stands at 3.00 gives the buyer back 300 of a 375 payment. This answers the other figure: the refund's share of the
 * ORIGINAL payment, in the currency that payment was taken in (App\Services\Storefront\RefundFromOriginalPayment), together with the merchant account and
 * the company that took it (App\Services\Payments\OrderPaymentAccount). The rate of the day is not an input to either.
 *
 * It is the only place in the application that reaches the computation, and it answers NOTHING at all while
 * `gcc.storefronts.refunds.from_original_payment` is off, which it is by default: no statement is issued, no table is asked for, and every caller falls
 * back to exactly the figure it used before. Which of the two figures a refund should give back is the owner's decision and the finance review's, and it
 * is not made here: the mechanism is built, switched off, and waiting for that ruling (docs: D1-10).
 *
 * While it is on, an order with no original payment to work from — a cash-on-delivery order, an order placed before the record existed, a payment that was
 * never marked paid, two paid payments under one reference — answers null as well, so the flow does what it always did rather than guess.
 */
#[Singleton]
final class RefundPayout
{
    public const SETTING = 'gcc.storefronts.refunds.from_original_payment';

    private const AMOUNT_COLUMN = 'original_payment_amount';

    private const CURRENCY_COLUMN = 'original_payment_currency';

    /** @var array<string, bool> */
    private array $tableHasColumn = [];

    public function __construct(
        private readonly RefundFromOriginalPayment $computation,
        private readonly OrderPaymentAccount $paymentAccount,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) config(self::SETTING, false);
    }

    /**
     * What this refund gives back of the original payment, and the account that took it.
     *
     * @param object|array<string, mixed>|null $order the order being refunded, with its amount, its transaction reference and its recorded currency
     * @param object|array<string, mixed>|null $refund the refund request, with the amount it is for and the order it belongs to
     *
     * @return array{currency: string, amount: string, paid: string, basis: string, transaction: ?string, capped: bool, account_id: ?int, company_id: ?int}|null
     */
    public function forRefund(object|array|null $order, object|array|null $refund): ?array
    {
        if (!$this->isEnabled() || $order === null || $refund === null) {
            return null;
        }

        $amount = data_get($refund, 'amount');

        if (!is_numeric($amount) || (float) $amount < 0) {
            return null;
        }

        $worked = $this->computation->of(
            order: $order,
            refundAmount: $this->plain($amount),
            alreadyRefunded: $this->whatTheEarlierRefundsGaveBack(order: $order, refund: $refund),
        );

        if ($worked === null) {
            return null;
        }

        $stamp = $this->paymentAccount->of(order: $order);

        return $worked + ['account_id' => $stamp?->accountId, 'company_id' => $stamp?->companyId];
    }

    /**
     * The amount to credit a customer's wallet with so that what they get back is exactly what they paid, or null when that is not this mechanism's to
     * decide.
     *
     * A wallet credit is given in the platform's own currency (`createWalletTransaction` converts it from there into the amounts balances are kept in), so
     * a payment taken in that same currency can be handed back whole. A payment taken in another one cannot, because a customer has a single balance in the
     * platform's money until balances are per market (GCC-15), and at what rate one currency becomes another in a refund is a decision nobody has taken.
     * That case keeps the figure the flow used before and is recorded on the refund transaction, so the amount owed is not lost.
     *
     * @param array{currency: string, amount: string} $payout
     */
    public function walletCredit(array $payout): ?string
    {
        return strcasecmp($payout['currency'], (string) getCurrencyCode(type: 'default')) === 0 ? $payout['amount'] : null;
    }

    /**
     * What the refund transaction records of the original payment: the amount it gives back of it and the currency it was taken in.
     *
     * A column is written only when the table has it, so a database that has not run the migration writes the row exactly as it wrote it before.
     *
     * @param array{currency: string, amount: string} $payout
     *
     * @return array<string, string>
     */
    public function recordOn(string $table, array $payout): array
    {
        return array_filter([
            self::AMOUNT_COLUMN => $this->hasColumn(table: $table, column: self::AMOUNT_COLUMN) ? $payout['amount'] : null,
            self::CURRENCY_COLUMN => $this->hasColumn(table: $table, column: self::CURRENCY_COLUMN) ? $payout['currency'] : null,
        ], static fn (?string $value): bool => $value !== null);
    }

    /**
     * What the refunds of the same order have already given back, in the amounts the order is stored in, so that the shares of one payment add up to
     * exactly what was paid however many parts it is refunded in. The refund being paid out now is left out: it is the part being worked out.
     */
    private function whatTheEarlierRefundsGaveBack(object|array $order, object|array $refund): string
    {
        $orderId = data_get($refund, 'order_id') ?? data_get($order, 'id');

        if ($orderId === null || !Schema::hasTable('refund_requests')) {
            return '0';
        }

        $thisRefund = data_get($refund, 'id');

        return $this->plain(DB::table('refund_requests')
            ->where('order_id', $orderId)
            ->where('status', 'refunded')
            ->when($thisRefund !== null, static fn ($query) => $query->where('id', '!=', $thisRefund))
            ->sum('amount'));
    }

    private function plain(mixed $amount): string
    {
        return is_int($amount) || is_float($amount) ? number_format((float) $amount, 8, '.', '') : trim((string) $amount);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->tableHasColumn["{$table}.{$column}"] ??= Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }
}
