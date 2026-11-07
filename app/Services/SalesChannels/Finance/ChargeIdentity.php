<?php

namespace App\Services\SalesChannels\Finance;

use App\Enums\OperationalCosts\CostComponent;
use App\Services\OperationalCosts\Support\Money;

/**
 * The ONE canonical identity of a charge a channel makes, whoever reports it (the API, a statement a person imported, an invoice): what was charged, and about what.
 *
 *  - `shape` is what was charged: the channel account, the fee type, the currency and the amount through Money to six decimals, so 25, 25.0 and 25.00 are the same amount.
 *  - `reference` is what it was about, when the report says: the marketplace order (and its package) or, for a charge that belongs to no order, the settlement.
 *    A report that names none has no reference, and such a charge can only be recognised by its shape and its date.
 *
 * Two reports of the same charge have the same shape and, when both name what it is about, the same reference. The shape is stored on the event (`charge_key`, indexed) and
 * the reference is the event's `external_reference`, so a manual row meeting an entry the API gave is found with one query (see ChargeOverlapMatcher).
 */
final readonly class ChargeIdentity
{
    private function __construct(public string $shape, public ?string $reference)
    {
    }

    public static function of(int $accountId, ?string $orderId, ?string $packageId, ?string $settlementId, CostComponent $component, string $currency, Money $amount): self
    {
        return new self(
            shape: hash('sha256', implode('|', ['charge', $accountId, $component->value, strtoupper(trim($currency)), $amount->toStorage()])),
            reference: self::referenceOf($orderId, $packageId, $settlementId),
        );
    }

    /** What a charge is about: `order|package` (the package is empty when the channel has none), else `settlement:id`, else nothing. */
    public static function referenceOf(?string $orderId, ?string $packageId, ?string $settlementId): ?string
    {
        $orderId = self::clean($orderId);
        $settlementId = self::clean($settlementId);

        return match (true) {
            $orderId !== null => $orderId . '|' . (self::clean($packageId) ?? ''),
            $settlementId !== null => 'settlement:' . $settlementId,
            default => null,
        };
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }
}
