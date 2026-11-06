<?php

namespace App\Services\OperationalCosts\Contributors;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Services\OperationalCosts\Calculation\ServiceCostCalculator;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Data\CostDraft;
use App\Services\OperationalCosts\Data\ServiceContext;
use App\Services\OperationalCosts\Sales\SaleDocument;
use App\Services\OperationalCosts\Support\FxConverter;

/**
 * What a sales channel charges for an order it sold for us.
 *
 *  - COMMISSION is priced from the channel's contract (provider code = the channel: `amazon`, `trendyol`) when one exists; with none it is MISSING, not zero, which is what
 *    the cost reports then show as the thing to set up. The channel's own statement later supplies the actual charge, which replaces this figure; the commission is
 *    never added on top of it.
 *  - FBA FEES have no contract here: only the channel's statement can say them. Until it does, an FBA order's fulfilment fee is MISSING.
 *
 * It writes nothing for orders that are not channel orders, nor for cancelled ones.
 */
class MarketplaceFeeContributor implements CostContributor
{
    use RecordsContractPricedCost;

    public function __construct(
        private readonly ServiceCostCalculator $calculator,
        private readonly CostLedger $ledger,
        private readonly FxConverter $fx,
    ) {
    }

    public function contribute(SaleDocument $document): array
    {
        if ($document->subjectType !== SaleDocument::SUBJECT_CHANNEL_ORDER || $document->isCancelled()) {
            return [];
        }

        $context = new ServiceContext(
            serviceDate: $document->occurredAt,
            currency: $document->currency,
            units: array_sum(array_map(static fn ($line): int => $line->quantity, $document->lines)),
            value: $document->netRevenue(),
            salesChannel: $document->salesChannel,
        );
        $commission = $this->calculator->price($document->salesChannel, [CostComponent::MarketplaceCommission], $context, $document->sellerId)[0];

        $outcomes = [];
        if ($commission->kind !== 'not_priced') {
            $outcomes[] = $this->recordPriced(document: $document, component: CostComponent::MarketplaceCommission, cost: $commission, provider: $document->salesChannel);
        }
        if ($document->fulfillmentMode === FulfillmentMode::Fba->value) {
            $outcomes[] = $this->ledger->record(new CostDraft(
                subjectType: $document->subjectType,
                subjectId: $document->subjectId,
                component: CostComponent::FbaFulfillment,
                state: CostState::Missing,
                amount: null,
                currency: $document->currency,
                periodDate: $document->occurredAt,
                source: 'rule',
                identity: 'missing:awaiting_channel_statement',
                sellerId: $document->sellerId,
                salesChannel: $document->salesChannel,
                accountId: $document->accountId,
                orderId: $document->orderId,
                externalOrderMappingId: $document->mappingId,
                provider: $document->salesChannel,
                externalReference: $document->reference,
                missingReason: 'awaiting_channel_statement',
                occurredAt: $document->occurredAt,
            ));
        }

        return $outcomes;
    }
}
