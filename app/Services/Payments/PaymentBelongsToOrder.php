<?php

namespace App\Services\Payments;

use App\Models\PaymentRequest;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a paid gateway payment is the payment of the order being asked about, and what that payment covered (D1-10).
 *
 * An order is joined to its payment by one mutable text column: `orders.transaction_ref` against `payment_requests.transaction_id`. Nothing else binds
 * them, and nothing records what a payment covered when it was taken. Two orders of different companies that come to carry one reference are therefore
 * read as one cart, and the refund of either is worked out from — and recorded against — whichever payment answers: another company's merchant account,
 * and a share of money that buyer never handed over.
 *
 * Two questions are asked here, and a "no" to either means the flow does for this refund what it does for an order with no payment at all: the figure it
 * has always used.
 *
 * The first is the payment's own: the market it was started for (PaymentTransactionRecord, written by PaymentAccountBinding when the payment starts) must
 * be the market the order was sold from. The company is deliberately NOT compared: a market's gateway account may belong to another country's company,
 * which is why the refund keeps the selling company and the paying company as two columns rather than one.
 *
 * The second is the orders': the rows carrying the reference must be one cart, still describing what that payment covered — one selling company, one
 * market and one order group among them, and none of them edited since (`edited_status`). An edit moves the amounts the share is taken of and leaves the
 * difference to a second payment this reference does not name, and either of those once gave a buyer back money that was never paid, or kept back money
 * that was.
 *
 * Silence is not disagreement. A payment with no stamp, an order placed before the storefront columns existed, a database without the column: none of
 * them says whose payment this is, so none refuses on its own. What refuses is two recorded values that differ.
 */
#[Singleton]
final class PaymentBelongsToOrder
{
    /** What the orders under one reference have to agree on to be one cart, and the most each may answer. Read only where the database has the column. */
    private const CART_SIGNALS = [
        'edited' => ['column' => 'edited_status', 'select' => 'max(edited_status)', 'most' => 0],
        'companies' => ['column' => 'legal_entity_id', 'select' => 'count(distinct legal_entity_id)', 'most' => 1],
        'markets' => ['column' => 'storefront_country', 'select' => 'count(distinct storefront_country)', 'most' => 1],
        'carts' => ['column' => 'order_group_id', 'select' => 'count(distinct order_group_id)', 'most' => 1],
    ];

    /** @var array<string, bool> */
    private array $ordersHaveColumn = [];

    public function __construct(private readonly PaymentAccountBinding $binding)
    {
    }

    /**
     * @param PaymentRequest|array<string, mixed>|null $payment the paid gateway record found by the order's reference
     * @param object|array<string, mixed>|null $order the order being refunded
     */
    public function payment(PaymentRequest|array|null $payment, object|array|null $order): bool
    {
        return $this->stamp(stamp: $this->binding->of(payment: $payment), order: $order);
    }

    /** The same question asked of a stamp already read, so a caller that has one does not decode it twice. */
    public function stamp(?PaymentTransactionRecord $stamp, object|array|null $order): bool
    {
        if ($stamp === null || $order === null) {
            return true;
        }

        return $this->agree(ofThePayment: $stamp->market, ofTheOrder: data_get($order, 'storefront_country'));
    }

    /** Whether the orders carrying this reference are one cart. A database that cannot answer says nothing, and silence is not disagreement. */
    public function oneCartUnder(string $reference): bool
    {
        return $this->cartRow(reference: $reference) !== false;
    }

    /**
     * The orders that carry this reference when they are one cart that still describes the payment, with the stored amounts it covered.
     *
     * @return object|null the aggregate, whose `whole` is the sum of their amounts; null when they are not one cart, or when nothing answers for them
     */
    public function cartUnder(string $reference): ?object
    {
        $row = $this->cartRow(reference: $reference);

        return $row === false ? null : $row;
    }

    /** The aggregate for the reference, `false` when a signal disagrees and null when there is nothing to judge by. */
    private function cartRow(string $reference): object|false|null
    {
        if (!Schema::hasTable('orders')) {
            return null;
        }

        $row = DB::table('orders')->where('transaction_ref', $reference)->selectRaw($this->cartSelect())->first();

        if ($row === null) {
            return null;
        }

        foreach (self::CART_SIGNALS as $signal => $rule) {
            if (isset($row->{$signal}) && (int) $row->{$signal} > $rule['most']) {
                return false;
            }
        }

        return $row;
    }

    /** The one aggregate that answers for the cart, asking only for the signals the database has a column for. */
    private function cartSelect(): string
    {
        $select = ['sum(order_amount) as whole'];

        foreach (self::CART_SIGNALS as $signal => $rule) {
            if ($this->ordersHave(column: $rule['column'])) {
                $select[] = "{$rule['select']} as {$signal}";
            }
        }

        return implode(', ', $select);
    }

    private function ordersHave(string $column): bool
    {
        return $this->ordersHaveColumn[$column] ??= Schema::hasColumn('orders', $column);
    }

    /** Two recorded values of the same fact, compared as numbers when both are numbers and as text otherwise; either one unrecorded says nothing. */
    private function agree(mixed $ofThePayment, mixed $ofTheOrder): bool
    {
        if (!$this->isRecorded($ofThePayment) || !$this->isRecorded($ofTheOrder)) {
            return true;
        }

        if (is_numeric($ofThePayment) && is_numeric($ofTheOrder)) {
            return (float) $ofThePayment === (float) $ofTheOrder;
        }

        return strcasecmp(trim((string) $ofThePayment), trim((string) $ofTheOrder)) === 0;
    }

    private function isRecorded(mixed $value): bool
    {
        return $value !== null && (!is_string($value) || trim($value) !== '');
    }
}
