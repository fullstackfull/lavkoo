<?php

namespace App\Http\Controllers\Admin\OperationalCosts;

use App\Enums\OperationalCosts\ContractStatus;
use App\Enums\OperationalCosts\CostState;
use App\Models\InventoryCostLayer;
use App\Models\OperationalCostInvoice;
use App\Models\OperationalCostReconciliation;

/**
 * The colour of a badge for each state the cost screens show. A state is never told by colour alone (the badge carries a dot and the word); this keeps the same
 * state the same colour on every page.
 */
final class CostTones
{
    public static function state(CostState $state): string
    {
        return match ($state) {
            CostState::Estimated => 'info',
            CostState::Actual => 'accent',
            CostState::Reconciled => 'success',
            CostState::Missing => 'danger',
            CostState::Disputed => 'warning',
        };
    }

    public static function contract(ContractStatus $status): string
    {
        return match ($status) {
            ContractStatus::Active => 'success',
            ContractStatus::Superseded => 'info',
            ContractStatus::Draft, ContractStatus::Archived => 'neutral',
        };
    }

    public static function invoice(string $status): string
    {
        return match ($status) {
            OperationalCostInvoice::STATUS_APPROVED => 'success',
            OperationalCostInvoice::STATUS_UNDER_REVIEW, OperationalCostInvoice::STATUS_DISPUTED => 'warning',
            default => 'neutral',
        };
    }

    public static function reconciliation(string $status): string
    {
        return match ($status) {
            OperationalCostReconciliation::MATCHED, OperationalCostReconciliation::RESOLVED => 'success',
            OperationalCostReconciliation::VARIANCE, OperationalCostReconciliation::DISPUTED => 'warning',
            OperationalCostReconciliation::UNMATCHED_INVOICE, OperationalCostReconciliation::UNMATCHED_ESTIMATE => 'danger',
            default => 'neutral',
        };
    }

    public static function layer(string $status): string
    {
        return match ($status) {
            InventoryCostLayer::OPEN => 'success',
            InventoryCostLayer::VOID => 'danger',
            default => 'neutral',
        };
    }
}
