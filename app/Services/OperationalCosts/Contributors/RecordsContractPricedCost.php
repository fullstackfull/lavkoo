<?php

namespace App\Services\OperationalCosts\Contributors;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Data\ComputedCost;
use App\Services\OperationalCosts\Data\CostDraft;
use App\Services\OperationalCosts\Data\RecordOutcome;
use App\Services\OperationalCosts\Sales\SaleDocument;
use App\Services\OperationalCosts\Support\FxConverter;

/**
 * Writes the outcome of pricing one service of a sale from a contract: an ESTIMATE when a rule priced it, MISSING (never zero) when the contract or its rule is absent.
 * The ledger keeps a better-known figure of the same service (the actual charge) over this one, so recording it again later is harmless.
 *
 * @property CostLedger $ledger
 * @property FxConverter $fx
 */
trait RecordsContractPricedCost
{
    protected function recordPriced(SaleDocument $document, CostComponent $component, ComputedCost $cost, ?string $provider, string $serviceRef = ''): RecordOutcome
    {
        $common = [
            'subjectType' => $document->subjectType,
            'subjectId' => $document->subjectId,
            'component' => $component,
            'periodDate' => $document->occurredAt,
            'serviceRef' => $serviceRef,
            'sellerId' => $document->sellerId,
            'salesChannel' => $document->salesChannel,
            'accountId' => $document->accountId,
            'orderId' => $document->orderId,
            'externalOrderMappingId' => $document->subjectType === SaleDocument::SUBJECT_CHANNEL_ORDER ? $document->mappingId : null,
            'provider' => $provider,
            'contractId' => $cost->contract?->id,
            'contractVersion' => $cost->contract?->version,
            'occurredAt' => $document->occurredAt,
            'externalReference' => $document->reference,
        ];

        if ($cost->isMissing()) {
            return $this->ledger->record(new CostDraft(...$common + [
                'state' => CostState::Missing, 'amount' => null, 'currency' => $document->currency, 'source' => 'rule',
                'identity' => 'missing:' . $cost->missingReason . ':' . ($cost->contract?->id ?? 0), 'missingReason' => $cost->missingReason,
            ]));
        }

        $result = $cost->result;
        $converted = $this->fx->convert($result->amount, $result->currency, $this->fx->baseCurrency());

        return $this->ledger->record(new CostDraft(...$common + [
            'state' => CostState::Estimated, 'amount' => $result->amount, 'currency' => $result->currency, 'source' => 'rule',
            'identity' => 'rule:' . $cost->contract?->id . ':' . $cost->contract?->version . ':' . $result->ruleId . ':' . hash('sha256', json_encode([$result->amount->toStorage(), $result->inputs])),
            'ruleId' => $result->ruleId, 'calcInputs' => $result->inputs,
            'fxRate' => $converted['rate'] ?? null, 'baseAmount' => $converted['amount'] ?? null, 'baseCurrency' => $converted === null ? null : $this->fx->baseCurrency(),
        ]));
    }
}
