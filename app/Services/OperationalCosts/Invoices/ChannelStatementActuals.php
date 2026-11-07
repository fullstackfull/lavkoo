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

/**
 * What the channel's own statements already booked for the fee and the period an invoice line bills.
 *
 * A marketplace invoice often bills a fee as one total for a period, with no order on the line. The statements (the API's, or a file a person imported) have already booked that
 * fee order by order, as actual costs: posting the invoice line as a second actual would count the fee twice. So such a line is compared with what the statements gave instead
 * (ReconciliationService::compareWithStatements), and only a line the statements say nothing about is posted.
 */
class ChannelStatementActuals
{
    /**
     * The total of the actual costs the statements booked for the line's fee, currency and period; null when the line is not a marketplace fee of a connected channel, has no
     * period to look in, or the statements booked nothing in it.
     */
    public function totalFor(OperationalCostInvoice $invoice, OperationalCostInvoiceItem $item): ?Money
    {
        $channel = SalesChannel::tryFrom((string) $invoice->provider_code);
        $component = CostComponent::tryFrom((string) $item->cost_component);
        $from = $item->period_start ?? $invoice->period_start;
        $to = $item->period_end ?? $invoice->period_end;
        if ($channel === null || !$channel->isExternal() || $component?->costClass() !== CostClass::MarketplaceFee || $from === null || $to === null) {
            return null;
        }

        $booked = OperationalCostEntry::query()
            ->where('seller_id', $invoice->seller_id)
            ->where('sales_channel', $channel->value)
            ->when($invoice->account_id !== null, static fn ($query) => $query->where('account_id', $invoice->account_id))
            ->where('cost_component', $component->value)
            ->where('currency', $item->currency)
            ->where('source', 'channel_api')
            ->whereIn('state', [CostState::Actual->value, CostState::Reconciled->value])
            ->effective()
            ->whereBetween('period_date', [CarbonImmutable::parse($from)->toDateString(), CarbonImmutable::parse($to)->toDateString()])
            ->get();

        return $booked->isEmpty() ? null : Money::sum($booked->map(static fn (OperationalCostEntry $entry): Money => Money::of($entry->amount)));
    }
}
