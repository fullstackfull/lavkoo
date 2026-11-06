<?php

namespace App\Services\SalesChannels\Trendyol;

use App\Enums\OperationalCosts\CostComponent;
use App\Models\SalesChannelAccount;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteFinancialEntry;
use Carbon\CarbonImmutable;

/**
 * Turns rows of the Current Account Statement (`settlements` and `otherfinancials`) into financial facts.
 *
 *  - `Sale` and `Return` settlements carry the commission on the sale: `commissionAmount` is negative on a sale and positive on a return, so the cost to us is its negation
 *    (a return gives part of the commission back). The sale and the refund themselves are reported but are not costs: the order already is the revenue.
 *  - `CommissionInvoice` rows bill the commission the Sale rows already carry, so they are reported as duplicates and never counted a second time.
 *  - `DeductionInvoices` are amounts taken from the payout; what they are is read from the description with the patterns in config (`trendyol.finance_map`), and a description
 *    that matches none stays UNCLASSIFIED for a person.
 *  - `PaymentOrder` is the payout: not revenue.
 *
 * Trendyol re-issued every row id and payment order id in August 2026, so an id is never part of a fact's identity: the identity is built from what the row says.
 * The statement rows carry no currency; the account's currency is assumed and said so in the entry's meta.
 */
class TrendyolFinanceMapper
{
    /**
     * @param array<string, mixed> $row one element of a settlements `content[]`
     * @return list<RemoteFinancialEntry>
     */
    public function settlement(array $row, string $type, SalesChannelAccount $account): array
    {
        $isReturn = $type === 'Return';
        $commission = Money::of((string) ($row['commissionAmount'] ?? 0));
        $entries = [
            $this->entry(
                row: $row, type: $type, part: $isReturn ? 'refund' : 'sale', account: $account,
                category: $isReturn ? RemoteFinancialEntry::REFUND : RemoteFinancialEntry::SALE,
                component: CostComponent::Other,
                amount: $isReturn ? Money::of((string) ($row['debt'] ?? 0))->abs() : Money::of((string) ($row['sellerRevenue'] ?? $row['credit'] ?? 0)),
            ),
        ];
        if (!$commission->isZero()) {
            $entries[] = $this->entry(
                row: $row, type: $type, part: 'commission', account: $account,
                category: RemoteFinancialEntry::FEE, component: CostComponent::MarketplaceCommission, amount: $commission->negated(),
            );
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $row one element of an otherfinancials `content[]`
     * @return list<RemoteFinancialEntry>
     */
    public function other(array $row, string $type, SalesChannelAccount $account): array
    {
        $debt = Money::of((string) ($row['debt'] ?? 0));
        $credit = Money::of((string) ($row['credit'] ?? 0));
        $cost = $debt->minus($credit);

        [$category, $component, $amount] = match ($type) {
            'PaymentOrder' => [RemoteFinancialEntry::PAYOUT, CostComponent::Other, $credit->isZero() ? $debt->abs() : $credit],
            'CommissionInvoice' => [RemoteFinancialEntry::DUPLICATE_OF_ANOTHER_ENTRY, CostComponent::MarketplaceCommission, $cost],
            'CreditNote' => [RemoteFinancialEntry::ADJUSTMENT, CostComponent::MarketplaceAdjustment, $cost],
            'ADS_BALANCE_TOP_UP_FEE_VIA_CREDIT_CARD' => [RemoteFinancialEntry::FEE, CostComponent::MarketplaceServiceFee, $cost],
            'DeductionInvoices' => $this->deduction((string) ($row['description'] ?? ''), $cost),
            default => [RemoteFinancialEntry::UNCLASSIFIED, CostComponent::Other, $cost],
        };

        return [$this->entry(row: $row, type: $type, part: 'row', account: $account, category: $category, component: $component, amount: $amount)];
    }

    /** @return array{0: string, 1: CostComponent, 2: Money} */
    private function deduction(string $description, Money $cost): array
    {
        foreach ((array) config('sales_channels.trendyol.finance_map.deduction', []) as $pattern => $component) {
            if (preg_match($pattern, $description) === 1) {
                return [RemoteFinancialEntry::FEE, CostComponent::tryFrom($component) ?? CostComponent::Other, $cost];
            }
        }

        return [RemoteFinancialEntry::UNCLASSIFIED, CostComponent::Other, $cost];
    }

    /** @param array<string, mixed> $row */
    private function entry(array $row, string $type, string $part, SalesChannelAccount $account, string $category, CostComponent $component, Money $amount): RemoteFinancialEntry
    {
        $postedAt = is_numeric($row['transactionDate'] ?? null) ? CarbonImmutable::createFromTimestampMsUTC((int) $row['transactionDate']) : CarbonImmutable::now();
        $orderNumber = $this->nullable($row['orderNumber'] ?? null);
        $packageId = $this->nullable($row['shipmentPackageId'] ?? null);

        return new RemoteFinancialEntry(
            identity: hash('sha256', json_encode([
                'trendyol', $account->id, $type, $part, $orderNumber, $packageId, $row['barcode'] ?? null, $row['transactionDate'] ?? null, $row['receiptId'] ?? null,
                (string) ($row['debt'] ?? 0), (string) ($row['credit'] ?? 0), (string) ($row['commissionAmount'] ?? 0), $row['description'] ?? null,
            ], JSON_THROW_ON_ERROR)),
            category: $category,
            component: $component,
            amount: $amount,
            currency: strtoupper((string) ($account->currency ?? '')),
            orderId: $orderNumber,
            packageId: $packageId,
            lineId: null,
            sku: null,
            postedAt: $postedAt,
            rawType: mb_substr($type . ($part === 'row' ? '' : ':' . $part), 0, 190),
            meta: array_filter([
                'barcode' => $row['barcode'] ?? null,
                'description' => $row['description'] ?? null,
                'commission_rate' => $row['commissionRate'] ?? null,
                'seller_revenue' => $row['sellerRevenue'] ?? null,
                'payment_order_id' => $row['paymentOrderId'] ?? null,
                'payment_date' => is_numeric($row['paymentDate'] ?? null) ? CarbonImmutable::createFromTimestampMsUTC((int) $row['paymentDate'])->toIso8601String() : null,
                'commission_invoice' => $row['commissionInvoiceSerialNumber'] ?? null,
                'currency_assumed' => true,
            ], static fn ($value): bool => $value !== null && $value !== ''),
        );
    }

    private function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
