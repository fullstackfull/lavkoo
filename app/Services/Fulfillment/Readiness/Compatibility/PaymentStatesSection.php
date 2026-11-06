<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

use Illuminate\Database\Query\Builder;

/**
 * How this store's payment states map onto the two ways a provider shipment can be paid: prepaid, or cash collected by the
 * courier. The integration reads exactly two facts per order: whether the method is "cash_on_delivery" and whether the
 * status is "paid" (anything else counts as unpaid). Everything below is a count of orders; no customer or amount is printed.
 */
final class PaymentStatesSection implements CompatibilitySection
{
    private const SECTION = 'payment_states';

    public function checks(ReadOnlyProbe $probe, CompatibilityOptions $options): array
    {
        $columns = $probe->columns('orders');
        if (!isset($columns['payment_status'], $columns['payment_method'])) {
            return [new CompatibilityCheck(self::SECTION, 'payment states', CompatibilityCheck::INFO, 'not checked: orders.payment_status or orders.payment_method is missing (the schema check reports it)')];
        }

        return [
            $this->statuses($probe),
            $this->methods($probe),
            $this->howOrdersWouldBePaid($probe, isset($columns['paid_amount'], $columns['order_amount'])),
        ];
    }

    private function statuses(ReadOnlyProbe $probe): CompatibilityCheck
    {
        $description = Enumeration::describe($probe->distribution('orders', 'payment_status', CompatibilityOptions::MAX_LISTED_VALUES * 2), CompatibilitySpec::PAYMENT_STATUSES);
        $surprising = Enumeration::hasSurprises($description);

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'payment status values',
            status: $surprising ? CompatibilityCheck::WARN : CompatibilityCheck::PASS,
            detail: $surprising
                ? 'payment status value(s) other than "paid" and "unpaid" exist: the integration treats every status except "paid" as unpaid, so a status such as a partial payment would be handled as unpaid'
                : 'only "paid" and "unpaid" occur',
            facts: $description,
        );
    }

    private function methods(ReadOnlyProbe $probe): CompatibilityCheck
    {
        $description = Enumeration::describe($probe->distribution('orders', 'payment_method', CompatibilityOptions::MAX_LISTED_VALUES * 2), [CompatibilitySpec::PAYMENT_METHOD_CASH_ON_DELIVERY]);
        $cash = $description['values'][CompatibilitySpec::PAYMENT_METHOD_CASH_ON_DELIVERY] ?? 0;

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'payment methods',
            status: $description['other_values'] > 0 ? CompatibilityCheck::WARN : CompatibilityCheck::INFO,
            detail: "{$cash} of {$description['total_rows']} orders are cash on delivery; the other " . (count($description['values']) - ($cash > 0 ? 1 : 0)) . ' payment method(s) are treated as prepaid once the order is paid'
                . ($description['other_values'] > 0 ? "; {$description['other_values']} method value(s) are not a code word and are not shown" : ''),
            facts: ['orders_by_method' => $description['values'], 'cash_on_delivery' => $cash, 'not_shown_values' => $description['other_values'], 'null_rows' => $description['null_rows']],
        );
    }

    private function howOrdersWouldBePaid(ReadOnlyProbe $probe, bool $hasAmounts): CompatibilityCheck
    {
        $cod = static fn (Builder $query) => $query->where('payment_method', CompatibilitySpec::PAYMENT_METHOD_CASH_ON_DELIVERY);
        $notCod = static fn (Builder $query) => $query->where(static fn (Builder $inner) => $inner->where('payment_method', '<>', CompatibilitySpec::PAYMENT_METHOD_CASH_ON_DELIVERY)->orWhereNull('payment_method'));
        $paid = static fn (Builder $query) => $query->where('payment_status', 'paid');
        $notPaid = static fn (Builder $query) => $query->where(static fn (Builder $inner) => $inner->where('payment_status', '<>', 'paid')->orWhereNull('payment_status'));

        $facts = [
            'cash_on_delivery_unpaid' => $probe->count('orders', static fn (Builder $query) => $notPaid($cod($query))),
            'cash_on_delivery_already_paid' => $probe->count('orders', static fn (Builder $query) => $paid($cod($query))),
            'prepaid_and_paid' => $probe->count('orders', static fn (Builder $query) => $paid($notCod($query))),
            'prepaid_not_yet_paid' => $probe->count('orders', static fn (Builder $query) => $notPaid($notCod($query))),
        ];
        if ($hasAmounts) {
            $facts['cash_on_delivery_part_paid'] = $probe->count('orders', static fn (Builder $query) => $notPaid($cod($query))->where('paid_amount', '>', 0)->whereColumn('paid_amount', '<', 'order_amount'));
        }

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'how orders would be paid at a provider',
            status: CompatibilityCheck::INFO,
            detail: "{$facts['cash_on_delivery_unpaid']} orders would ask the courier for cash, {$facts['prepaid_and_paid']} are prepaid and paid; {$facts['prepaid_not_yet_paid']} prepaid orders are not paid yet and would wait ('payment pending') rather than be sent",
            facts: $facts,
        );
    }
}
