<?php

namespace App\Services\OperationalCosts\Contributors;

use App\Enums\OperationalCosts\CostComponent;
use App\Services\OperationalCosts\Calculation\ServiceCostCalculator;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Data\ServiceContext;
use App\Services\OperationalCosts\Sales\SaleDocument;
use App\Services\OperationalCosts\Support\FxConverter;

/**
 * The fee the payment gateway took on a storefront order.
 *
 * The gateway is the company: its contract is looked up under the order's payment method code (`stripe`, `razorpay`...). Orders paid in cash, by wallet or
 * offline have no gateway and cost nothing here. An online payment with no contract for its gateway has a MISSING fee, which is what
 * the cost reports then show as the thing to set up. The actual fee later replaces the estimate (from the gateway's statement).
 */
class PaymentFeeContributor implements CostContributor
{
    use RecordsContractPricedCost;

    /** Payment methods that do not pass through a gateway that charges a fee. */
    private const NO_GATEWAY = ['cash_on_delivery', 'offline_payment', 'pay_by_wallet', 'cash', 'wallet', 'pay_by_cash', 'card_on_delivery', ''];

    public function __construct(
        private readonly ServiceCostCalculator $calculator,
        private readonly CostLedger $ledger,
        private readonly FxConverter $fx,
    ) {
    }

    public function contribute(SaleDocument $document): array
    {
        $method = (string) $document->paymentMethod;
        if ($document->subjectType !== SaleDocument::SUBJECT_ORDER || in_array($method, self::NO_GATEWAY, true) || $document->isCancelled()) {
            return [];
        }

        $context = new ServiceContext(
            serviceDate: $document->occurredAt,
            currency: $document->currency,
            units: array_sum(array_map(static fn ($line): int => $line->quantity, $document->lines)),
            value: $document->orderTotal ?? $document->netRevenue(),
            paymentMethod: $method,
            salesChannel: $document->salesChannel,
        );
        $cost = $this->calculator->price($method, [CostComponent::PaymentGateway], $context, $document->sellerId)[0];
        if ($cost->kind === 'not_priced') {
            return [];
        }

        return [$this->recordPriced(document: $document, component: CostComponent::PaymentGateway, cost: $cost, provider: $method)];
    }
}
