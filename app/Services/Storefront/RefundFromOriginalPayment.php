<?php

namespace App\Services\Storefront;

use App\Services\OperationalCosts\Support\Money;
use App\Services\Payments\PaymentBelongsToOrder;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * D1-10, criteria 2 and 4, prepared and not adopted: what a refund gives back when it is worked out from what the customer actually paid, in the currency they paid in, and
 * not by converting the order's stored amount again at the rate of the day of the refund. The stored amount only says which share of the order is given back; the money is
 * that share of the original payment.
 *
 * The original payment is, in this order: the gateway record of the order (`payment_requests`, found by the transaction reference the order keeps, paid, and the only one
 * with that reference), whose amount and currency are what the gateway took; else the order's own record of what it was sold in (its currency and the rate that turns a
 * stored amount into it); else nothing, and the answer is null: an order that recorded neither is not guessed at, and the conversion the platform makes today stays what
 * decides. A payment that covered several orders of one cart is shared among them by their stored amounts.
 *
 * Refunds of one order add up to exactly its share of the payment: each refund is the difference of two roundings of the cumulative share, in the decimals of the currency, so
 * no minor unit is lost or made up whatever the number of refunds, and a refund that would take more than the order's amount is capped at it.
 *
 * A share is only a share while the stored amounts still describe what the payment covered. The order is joined to its payment by one mutable text column and the denominator
 * is summed on the day of the refund, so three states answer null rather than a figure: the payment's own stamp names a market or a company that is not the order's
 * (App\Services\Payments\PaymentBelongsToOrder); an order under the reference has been edited since it was paid for (`edited_status`), which moves the amounts the share is
 * taken of and leaves a second payment for the difference that this reference does not name; or the orders under the reference are not one cart (more than one selling
 * company, market or order group among them), or do not add up to at least the order being refunded. Each of those once gave back money that was never paid, or kept back
 * money that was.
 *
 * Nothing is written and nothing is paid here. Which of the two figures a refund SHOULD give back, and the policy around it (the original amount, or the amount at today's
 * rate; credit notes; the merchant account it returns to) is the owner's and the finance review's to adopt.
 */
#[Singleton]
final class RefundFromOriginalPayment
{
    public const GATEWAY_RECORD = 'gateway_record';

    public const ORDER_RECORD = 'order_record';

    private ?bool $paymentsHaveTheStamp = null;

    public function __construct(
        private readonly StorefrontCurrency $currency,
        private readonly PaymentBelongsToOrder $belongsToOrder,
    ) {
    }

    /**
     * @param object|array<string, mixed> $order the order, with its amount, its transaction reference and, when it has them, its currency and rate
     * @param string|int|float $refundAmount the amount of this refund, in the amounts the order is stored in
     * @param string|int|float $alreadyRefunded what the earlier refunds of the same order gave back, in the same amounts
     *
     * @return array{currency: string, amount: string, paid: string, basis: string, transaction: ?string, capped: bool}|null null when the order has no original payment to work from
     *
     * @throws InvalidArgumentException when an amount is not a plain number that is not negative
     */
    public function of(object|array $order, string|int|float $refundAmount, string|int|float $alreadyRefunded = 0): ?array
    {
        $refund = $this->amountOf(amount: $refundAmount, name: 'refund');
        $before = $this->amountOf(amount: $alreadyRefunded, name: 'already refunded');
        $orderAmount = Money::ofNullable($this->plainOrNull(data_get($order, 'order_amount')));

        if ($orderAmount === null || !$orderAmount->isPositive()) {
            return null;
        }

        $original = $this->gatewayRecord(order: $order, orderAmount: $orderAmount) ?? $this->orderRecord(order: $order, orderAmount: $orderAmount);

        if ($original === null) {
            return null;
        }

        $decimals = $this->currency->decimalsOf($original['currency']);
        $upToBefore = $before->min($orderAmount);
        $upToNow = $before->plus($refund)->min($orderAmount);

        return [
            'currency' => $original['currency'],
            'amount' => $this->portion(paid: $original['paid'], part: $upToNow, whole: $original['whole'], decimals: $decimals)
                ->minus($this->portion(paid: $original['paid'], part: $upToBefore, whole: $original['whole'], decimals: $decimals))
                ->toScale($decimals),
            'paid' => $original['paid']->toScale($decimals),
            'basis' => $original['basis'],
            'transaction' => $original['transaction'],
            'capped' => $before->plus($refund)->compareTo($orderAmount) > 0,
        ];
    }

    /** Whether the database has the gateway ledger the first source reads. */
    public function readsGatewayRecords(): bool
    {
        return Schema::hasTable('payment_requests');
    }

    /** @return array{currency: string, paid: Money, whole: Money, basis: string, transaction: ?string}|null */
    private function gatewayRecord(object|array $order, Money $orderAmount): ?array
    {
        $reference = trim((string) data_get($order, 'transaction_ref', ''));

        if ($reference === '' || !$this->readsGatewayRecords()) {
            return null;
        }

        $records = DB::table('payment_requests')->where('transaction_id', $reference)->where('is_paid', 1)->limit(2)->get($this->paymentColumns());

        if ($records->count() !== 1 || !$this->belongsToOrder->payment(payment: (array) $records->first(), order: $order)) {
            return null;
        }

        $paid = Money::ofNullable($this->plainOrNull($records->first()->payment_amount));
        $currency = strtoupper(trim((string) $records->first()->currency_code));

        if ($paid === null || !$paid->isPositive() || $currency === '') {
            return null;
        }

        $cart = $this->cartUnder(reference: $reference, orderAmount: $orderAmount);

        if ($cart === null) {
            return null;
        }

        return ['currency' => $currency, 'paid' => $paid, 'whole' => $cart, 'basis' => self::GATEWAY_RECORD, 'transaction' => $reference];
    }

    /**
     * What is read of the payment: its money, and the stamp that says which market it was started for where the table keeps one.
     *
     * The column is asked for rather than assumed. A database without it is older than the stamp, so there is no market to disagree with; and asking a
     * real server for a column it has not got is an error, where SQLite quietly answers the column's own name as a string and the test passes.
     *
     * @return list<string>
     */
    private function paymentColumns(): array
    {
        $columns = ['payment_amount', 'currency_code'];

        if ($this->paymentsHaveTheStamp ??= Schema::hasColumn('payment_requests', 'additional_data')) {
            $columns[] = 'additional_data';
        }

        return $columns;
    }

    /**
     * The stored amounts the payment covered: the orders of the one cart that carries the reference, summed as they stand now.
     *
     * Null when they are not one cart that still describes the payment (App\Services\Payments\PaymentBelongsToOrder), or when the total is smaller than the order being
     * refunded — which would hand that order a share of a payment larger than the whole it is a part of.
     */
    private function cartUnder(string $reference, Money $orderAmount): ?Money
    {
        $cart = $this->belongsToOrder->cartUnder(reference: $reference);
        $whole = $cart === null ? null : Money::ofNullable($this->plainOrNull($cart->whole));

        return $whole === null || !$whole->isPositive() || $whole->compareTo($orderAmount) < 0 ? null : $whole;
    }

    /** @return array{currency: string, paid: Money, whole: Money, basis: string, transaction: ?string}|null */
    private function orderRecord(object|array $order, Money $orderAmount): ?array
    {
        $currency = strtoupper(trim((string) data_get($order, 'currency_code', '')));
        $rate = Money::ofNullable($this->plainOrNull(data_get($order, 'currency_rate')));

        if ($currency === '' || $rate === null || !$rate->isPositive()) {
            return null;
        }

        return ['currency' => $currency, 'paid' => $orderAmount->times($rate), 'whole' => $orderAmount, 'basis' => self::ORDER_RECORD, 'transaction' => null];
    }

    /** The share of the payment that a part of the amounts it covers stands for, rounded once, in the decimals of the currency. */
    private function portion(Money $paid, Money $part, Money $whole, int $decimals): Money
    {
        return $paid->times($part)->dividedBy($whole, $decimals);
    }

    private function amountOf(string|int|float $amount, string $name): Money
    {
        $plain = $this->plainOrNull($amount);

        if ($plain === null) {
            throw new InvalidArgumentException("The {$name} amount must be a plain number that is not negative.");
        }

        return Money::of($plain);
    }

    /** The value as text when it is a plain number that is not negative, else null. */
    private function plainOrNull(mixed $value): ?string
    {
        $text = is_int($value) || is_float($value) ? number_format((float) $value, 8, '.', '') : (is_string($value) ? trim($value) : '');

        return preg_match('/^\d+(\.\d+)?$/', $text) === 1 ? $text : null;
    }
}
