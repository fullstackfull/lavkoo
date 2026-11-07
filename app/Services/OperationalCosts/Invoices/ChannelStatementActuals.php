<?php

namespace App\Services\OperationalCosts\Invoices;

use App\Enums\OperationalCosts\CostClass;
use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Enums\SalesChannels\SalesChannel;
use App\Models\OperationalCostEntry;
use App\Models\OperationalCostInvoice;
use App\Models\OperationalCostInvoiceItem;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the channel's own statements already booked for the fee and the period an invoice line bills.
 *
 * A marketplace invoice often bills a fee as one total for a period, with no order on the line. The statements (the API's, or a file a person imported) book that fee order
 * by order, as actual costs: posting the invoice line as a second actual would count the fee twice. So such a line is compared with what the statements gave instead
 * (ReconciliationService::settleAgainstStatements), whichever of the two came first.
 *
 * What the statements booked is what the ledger counts for them, told day by day: an order's fee is one net entry whose date is its latest posting, so the entry says which
 * days it is made of (`calc_inputs.days`) and each posting counts in the period of its OWN day (a credit of the next month does not move the commission out of the month it was
 * charged in). A charge a person reversed is taken off again, on the days the reversed entry was made of.
 */
class ChannelStatementActuals
{
    /**
     * Whether an invoice line is a fee billed as one total for a period (no order, no shipment) by a connected channel, with the period it covers.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null the first and the last day, or null when the line is not one a statement can book
     */
    public function periodOf(OperationalCostInvoice $invoice, OperationalCostInvoiceItem $item): ?array
    {
        $channel = SalesChannel::tryFrom((string) $invoice->provider_code);
        $component = CostComponent::tryFrom((string) $item->cost_component);
        $from = $item->period_start ?? $invoice->period_start;
        $to = $item->period_end ?? $invoice->period_end;
        if ($channel === null || !$channel->isExternal() || $component?->costClass() !== CostClass::MarketplaceFee || $from === null || $to === null
            || $item->provider_fulfillment_id !== null || $item->external_order_mapping_id !== null) {
            return null;
        }

        return [CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($to)->startOfDay()];
    }

    /**
     * The total of the actual costs the statements booked for the line's fee, currency and period; null when the line is not one a statement can book, has no period to look
     * in, or the statements booked nothing in it. A fee that was booked and given back is a total of zero, not nothing.
     */
    public function totalFor(OperationalCostInvoice $invoice, OperationalCostInvoiceItem $item): ?Money
    {
        $period = $this->periodOf($invoice, $item);
        if ($period === null) {
            return null;
        }
        [$from, $to] = $period;

        $booked = OperationalCostEntry::query()
            ->where('seller_id', $invoice->seller_id)
            ->where('sales_channel', $invoice->provider_code)
            ->when($invoice->account_id !== null, static fn ($query) => $query->where('account_id', $invoice->account_id))
            ->where('cost_component', $item->cost_component)
            ->where('currency', $item->currency)
            ->whereIn('state', [CostState::Actual->value, CostState::Reconciled->value])
            ->effective();

        // An order's net is dated at its latest posting, so it holds no day earlier than that date: nothing dated before the period can be made of days inside it.
        $total = null;
        foreach ((clone $booked)->where('source', 'channel_api')->where('period_date', '>=', $from->toDateString())->lazyById(1000) as $entry) {
            $part = $this->daysWithin($entry, $from, $to);
            $total = $part === null ? $total : ($total ?? Money::zero())->plus($part);
        }

        foreach ($this->reversedStatementEntries($booked) as $reversed) {
            $part = $this->daysWithin($reversed, $from, $to);
            $total = $part === null ? $total : ($total ?? Money::zero())->minus($part);
        }

        return $total;
    }

    /**
     * What an entry counts in a period: the sum of its days that fall in it (null when none does). An entry that does not say which days it is made of belongs to its own date.
     */
    private function daysWithin(OperationalCostEntry $entry, CarbonImmutable $from, CarbonImmutable $to): ?Money
    {
        $days = $entry->calc_inputs['days'] ?? null;
        if (!is_array($days)) {
            $date = $entry->period_date->toDateString();

            return $date >= $from->toDateString() && $date <= $to->toDateString() ? Money::of($entry->amount) : null;
        }

        $sum = null;
        foreach ($days as $day => $amount) {
            if ((string) $day >= $from->toDateString() && (string) $day <= $to->toDateString()) {
                $sum = ($sum ?? Money::zero())->plus(Money::of($amount));
            }
        }

        return $sum;
    }

    /**
     * The statements' entries a person reversed, each of which may have been replaced by a newer net since: its reversal still stands, so it still counts against the days it was
     * made of.
     *
     * @param Builder<OperationalCostEntry> $booked the entries of the fee, currency, account and states in question
     * @return list<OperationalCostEntry> one for each reversal
     */
    private function reversedStatementEntries(Builder $booked): array
    {
        $reversed = (clone $booked)->where('source', 'manual')->get()
            ->map(static fn (OperationalCostEntry $entry): ?int => isset($entry->calc_inputs['reverses']) ? (int) $entry->calc_inputs['reverses'] : null)
            ->filter();
        if ($reversed->isEmpty()) {
            return [];
        }
        $originals = OperationalCostEntry::whereIn('id', $reversed->unique()->all())->where('source', 'channel_api')->get()->keyBy('id');

        return $reversed->map(static fn (int $id): ?OperationalCostEntry => $originals->get($id))->filter()->values()->all();
    }
}
