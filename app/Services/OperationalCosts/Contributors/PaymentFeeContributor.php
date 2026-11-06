<?php

namespace App\Services\OperationalCosts\Contributors;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Services\OperationalCosts\Calculation\ServiceCostCalculator;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Data\CostDraft;
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

        $common = [
            'subjectType' => $document->subjectType,
            'subjectId' => $document->subjectId,
            'component' => CostComponent::PaymentGateway,
            'periodDate' => $document->occurredAt,
            'sellerId' => $document->sellerId,
            'salesChannel' => $document->salesChannel,
            'orderId' => $document->orderId,
            'provider' => $method,
            'contractId' => $cost->contract?->id,
            'contractVersion' => $cost->contract?->version,
            'occurredAt' => $document->occurredAt,
            'externalReference' => $document->reference,
        ];

        if ($cost->isMissing()) {
            return [$this->ledger->record(new CostDraft(...$common + [
                'state' => CostState::Missing, 'amount' => null, 'currency' => $document->currency, 'source' => 'rule',
                'identity' => 'missing:' . $cost->missingReason . ':' . ($cost->contract?->id ?? 0), 'missingReason' => $cost->missingReason,
            ]))];
        }

        $result = $cost->result;
        $fx = $this->fx->convert($result->amount, $result->currency, $this->fx->baseCurrency());

        return [$this->ledger->record(new CostDraft(...$common + [
            'state' => CostState::Estimated, 'amount' => $result->amount, 'currency' => $result->currency, 'source' => 'rule',
            'identity' => 'rule:' . $cost->contract?->id . ':' . $cost->contract?->version . ':' . $result->ruleId . ':' . hash('sha256', json_encode([$result->amount->toStorage(), $result->inputs])),
            'ruleId' => $result->ruleId, 'calcInputs' => $result->inputs,
            'fxRate' => $fx['rate'] ?? null, 'baseAmount' => $fx['amount'] ?? null, 'baseCurrency' => $fx === null ? null : $this->fx->baseCurrency(),
        ]))];
    }
}
