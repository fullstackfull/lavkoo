<?php

namespace App\Services\OperationalCosts\Data;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A cost on its way into the ledger. Built by whatever computed or received it (a contract rule, an invoice line, an API fee event,
 * a cost layer) and handed to CostLedger::record(), which is the only writer of the table.
 *
 * `serviceRef` says WHICH service of the subject this is (the same component can be charged twice for one subject: two parcels, two
 * invoice lines); `identity` says which COMPUTATION or DOCUMENT produced it. Same identity, same row: that is the whole idempotency rule.
 *
 * `retiresEstimates`: this is a real charge that sits in a slot of its own (an invoice line), so the estimate (or the missing marker) of the same component of the same
 * subject, which sits in another slot, must stop counting once it is recorded.
 */
final readonly class CostDraft
{
    /**
     * @param array<string, mixed> $calcInputs
     */
    public function __construct(
        public string $subjectType,
        public int $subjectId,
        public CostComponent $component,
        public CostState $state,
        public ?Money $amount,
        public string $currency,
        public CarbonInterface $periodDate,
        public string $source,
        public string $identity,
        public string $serviceRef = '',
        public int $sellerId = 0,
        public ?string $salesChannel = null,
        public ?int $accountId = null,
        public ?int $orderId = null,
        public ?int $externalOrderMappingId = null,
        public ?int $providerFulfillmentId = null,
        public ?string $provider = null,
        public ?int $inventoryLocationId = null,
        public ?int $productId = null,
        public string $variantKey = '',
        public ?string $sku = null,
        public ?int $contractId = null,
        public ?int $contractVersion = null,
        public ?int $ruleId = null,
        public ?int $invoiceItemId = null,
        public ?string $externalReference = null,
        public array $calcInputs = [],
        public ?string $missingReason = null,
        public ?string $note = null,
        public ?CarbonInterface $occurredAt = null,
        public ?Money $quantity = null,
        public ?Money $unitAmount = null,
        public ?Money $fxRate = null,
        public ?Money $baseAmount = null,
        public ?string $baseCurrency = null,
        public string $allocationStatus = 'not_applicable',
        public bool $retiresEstimates = false,
    ) {
        if ($state === CostState::Missing && $amount !== null) {
            throw new InvalidArgumentException('A missing cost has no amount: it is NULL, not zero.');
        }
        if ($state !== CostState::Missing && $amount === null) {
            throw new InvalidArgumentException('Only a missing cost may have no amount.');
        }
    }

    public function slotKey(): string
    {
        return hash('sha256', implode('|', ['slot', $this->sellerId, $this->subjectType, $this->subjectId, $this->component->value, $this->serviceRef]));
    }

    public function dedupeKey(): string
    {
        return hash('sha256', implode('|', ['entry', $this->slotKey(), $this->state->value, $this->identity]));
    }
}
