<?php

namespace App\Services\SalesChannels\Amazon;

use App\Enums\OperationalCosts\CostComponent;
use App\Models\SalesChannelAccount;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteFinancialEntry;
use Carbon\CarbonImmutable;

/**
 * Turns a Finances API v2024-06-19 transaction into the financial facts it contains.
 *
 * A transaction is a tree: `breakdowns` (and those of each `items[]`) nest `breakdownType` nodes with signed `currencyAmount`s, and Amazon publishes NO closed list of
 * the types. So the leaves are classified by patterns kept in `config/sales_channels.php` (`amazon.finance_map`); a leaf matching none is UNCLASSIFIED and is kept for a
 * person, never silently counted as a cost or as revenue. Signs are taken as given: expenses are negative, so a fee's cost to us is the negation.
 *
 * ONE level of the tree is read: the transaction's own breakdowns already add up what its items' breakdowns explain, so reading both would count every fee twice. The items'
 * breakdowns are read only when the transaction carries none of its own, and then each fact names its item, so two items with the same fee are two facts. A REFUND transaction
 * is read item by item whenever its items carry breakdowns, because what it gives back has to be told per item (and so per order line); its fees are read the same way and are
 * netted against the fees of the order as every posting of it is.
 *
 * A refunded amount is classified by what it gives back (`refund_parts` in config: the goods, the shipping, the promotion that was taken off, the tax) and keeps the item it
 * is about, the refund it belongs to and its SKU in its meta, so ChannelRefundApplier can take it off the revenue of the right order line. An amount of a refund that is none
 * of those is UNCLASSIFIED and kept for a person: it is never counted, and never dropped.
 *
 * Identity is stable under redelivery: transaction id + item + path in the tree + amount, and no date, because the posted date of a deferred transaction moves when it is
 * released. So importing a window twice, or a transaction that moves from DEFERRED to released with another posted date, records each fact once, the fees (and the commission a
 * refund gives back) as well as the revenue. A transaction whose AMOUNT changes between the two is two facts: nothing here can tell a correction from a new charge.
 */
class AmazonFinanceMapper
{
    /** A refunded amount that is none of the parts a refund is made of. */
    private const OTHER_PART = 'other';

    /**
     * @param array<string, mixed> $transaction one element of `payload.transactions[]`
     * @return list<RemoteFinancialEntry>
     */
    public function map(array $transaction, SalesChannelAccount $account): array
    {
        $transactionId = (string) ($transaction['transactionId'] ?? hash('sha256', json_encode($transaction)));
        $type = (string) ($transaction['transactionType'] ?? 'Transaction');
        $postedAt = CarbonImmutable::parse((string) ($transaction['postedDate'] ?? now()->toIso8601String()));
        $orderId = $this->related((array) ($transaction['relatedIdentifiers'] ?? []), 'ORDER_ID');
        $refundId = $this->related((array) ($transaction['relatedIdentifiers'] ?? []), 'REFUND_ID');
        $settlementId = $this->related((array) ($transaction['relatedIdentifiers'] ?? []), 'SETTLEMENT_ID') ?? $this->related((array) ($transaction['relatedIdentifiers'] ?? []), 'FINANCIAL_EVENT_GROUP_ID');
        $sku = $this->contextValue($transaction, 'sku');
        $currencyFallback = (string) ($transaction['totalAmount']['currencyCode'] ?? $account->currency ?? '');

        $leaves = $this->leavesOf($transaction, $this->isRefund($type));
        if ($leaves === [] && isset($transaction['totalAmount']['currencyAmount'])) {
            $leaves[] = ['path' => [$type], 'amount' => $transaction['totalAmount']['currencyAmount'], 'currency' => $currencyFallback, 'item' => null, 'sku' => null];
        }

        $entries = [];
        foreach ($leaves as $leaf) {
            $amount = Money::of((string) $leaf['amount']);
            if ($amount->isZero()) {
                continue;
            }
            $path = implode(' > ', $leaf['path']);
            [$category, $component] = $this->classify($type, $leaf['path']);
            $part = $category === RemoteFinancialEntry::REFUND ? $this->refundPart($leaf['path']) : null;
            if ($part === self::OTHER_PART) {
                $category = RemoteFinancialEntry::UNCLASSIFIED;
            }
            $cost = in_array($category, [RemoteFinancialEntry::FEE, RemoteFinancialEntry::ADJUSTMENT], true) ? $amount->negated() : $amount;

            $entries[] = new RemoteFinancialEntry(
                identity: hash('sha256', implode('|', array_filter(['amazon', $account->id, $transactionId, $leaf['item'] === null ? null : 'item:' . $leaf['item'], $path, $amount->toStorage()], static fn ($part): bool => $part !== null))),
                category: $category,
                component: $component ?? CostComponent::Other,
                amount: $cost,
                currency: strtoupper((string) ($leaf['currency'] ?: $currencyFallback)),
                orderId: $orderId,
                packageId: null,
                lineId: $part === null ? null : $leaf['item'],
                sku: $leaf['sku'] ?? $sku,
                postedAt: $postedAt,
                rawType: mb_substr($type . ' > ' . $path, 0, 190),
                meta: array_filter([
                    'transaction_id' => $transactionId,
                    'status' => $transaction['transactionStatus'] ?? null,
                    'settlement_id' => $settlementId,
                    'refund_id' => $part === null ? null : $refundId,
                    'refund_part' => $part === self::OTHER_PART ? null : $part,
                    'item' => $part === null ? null : $leaf['item'],
                ], static fn ($value): bool => $value !== null),
            );
        }

        return $entries;
    }

    /**
     * The leaves of the transaction's own breakdowns, or, when it has none, of every item's; a refund is read item by item when its items carry any.
     *
     * @param array<string, mixed> $transaction
     * @return list<array{path: list<string>, amount: mixed, currency: string, item: string|null, sku: string|null}>
     */
    private function leavesOf(array $transaction, bool $itemByItem): array
    {
        if ($itemByItem) {
            $leaves = $this->itemLeaves($transaction);
            if ($leaves !== []) {
                return $leaves;
            }
        }

        $leaves = [];
        foreach ((array) ($transaction['breakdowns'] ?? []) as $breakdown) {
            $this->collect((array) $breakdown, [], $leaves, item: null, sku: null);
        }

        return $leaves !== [] ? $leaves : $this->itemLeaves($transaction);
    }

    /**
     * @param array<string, mixed> $transaction
     * @return list<array{path: list<string>, amount: mixed, currency: string, item: string|null, sku: string|null}>
     */
    private function itemLeaves(array $transaction): array
    {
        $leaves = [];
        foreach (array_values((array) ($transaction['items'] ?? [])) as $position => $item) {
            $item = (array) $item;
            $name = $this->itemName($item, $position);
            $sku = $this->contextValue(['contexts' => $item['contexts'] ?? []], 'sku');
            foreach ((array) ($item['breakdowns'] ?? []) as $breakdown) {
                $this->collect((array) $breakdown, [], $leaves, item: $name, sku: $sku);
            }
        }

        return $leaves;
    }

    /** What tells one item of a transaction from another: Amazon's own item identifier when it gives one, else the item's place in the list. */
    private function itemName(array $item, int $position): string
    {
        foreach (['ORDER_ADJUSTMENT_ITEM_ID', 'ORDER_ITEM_ID'] as $identifier) {
            $value = $this->related((array) ($item['relatedIdentifiers'] ?? []), $identifier);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return 'position-' . $position;
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $path
     * @param list<array{path: list<string>, amount: mixed, currency: string, item: string|null, sku: string|null}> $leaves
     */
    private function collect(array $node, array $path, array &$leaves, ?string $item, ?string $sku): void
    {
        $path[] = (string) ($node['breakdownType'] ?? 'Unnamed');
        $children = (array) ($node['breakdowns'] ?? []);
        if ($children === []) {
            if (isset($node['breakdownAmount']['currencyAmount'])) {
                $leaves[] = ['path' => $path, 'amount' => $node['breakdownAmount']['currencyAmount'], 'currency' => (string) ($node['breakdownAmount']['currencyCode'] ?? ''), 'item' => $item, 'sku' => $sku];
            }

            return;
        }
        foreach ($children as $child) {
            $this->collect((array) $child, $path, $leaves, $item, $sku);
        }
    }

    /**
     * @param list<string> $path
     * @return array{0: string, 1: ?CostComponent}
     */
    /**
     * What one of Amazon's money labels means for our books.
     *
     * **Public because the settlement REPORT needs the same answer as the Finances API.** A settlement row carries its own two
     * labels (`transaction-type`, and `amount-type` / `amount-description`), which is the same shape of question as a
     * breakdown node's type and path — so it feeds them to this rather than to a second set of patterns. One place decides what
     * "FBAPerUnitFulfillmentFee" means, and a label nobody matched is UNCLASSIFIED in both readers alike.
     *
     * @param list<string> $path the label and any sub-label, outermost first
     * @return array{0: string, 1: CostComponent|null}
     */
    public function classify(string $transactionType, array $path): array
    {
        $map = (array) config('sales_channels.amazon.finance_map', []);
        $leaf = end($path) ?: '';
        $text = $transactionType . ' ' . implode(' ', $path);

        foreach ((array) ($map['payout'] ?? []) as $pattern) {
            if (preg_match($pattern, $transactionType) === 1 || preg_match($pattern, $leaf) === 1) {
                return [RemoteFinancialEntry::PAYOUT, null];
            }
        }
        foreach ((array) ($map['fee'] ?? []) as $pattern => $component) {
            if (preg_match($pattern, $leaf) === 1 || preg_match($pattern, $text) === 1 && preg_match('/^(sales?|product charges?)/i', $path[0] ?? '') !== 1) {
                return [RemoteFinancialEntry::FEE, CostComponent::tryFrom($component)];
            }
        }
        foreach ((array) ($map['refund'] ?? []) as $pattern) {
            if (preg_match($pattern, $leaf) === 1 || preg_match($pattern, $transactionType) === 1) {
                return [RemoteFinancialEntry::REFUND, null];
            }
        }
        foreach ((array) ($map['adjustment'] ?? []) as $pattern) {
            if (preg_match($pattern, $leaf) === 1 || preg_match($pattern, $transactionType) === 1) {
                return [RemoteFinancialEntry::ADJUSTMENT, CostComponent::MarketplaceAdjustment];
            }
        }
        foreach ((array) ($map['sale'] ?? []) as $pattern) {
            if (preg_match($pattern, $path[0] ?? '') === 1 || preg_match($pattern, $leaf) === 1) {
                return [RemoteFinancialEntry::SALE, null];
            }
        }

        return [RemoteFinancialEntry::UNCLASSIFIED, null];
    }

    /** What a refund transaction is: its type says so (the patterns of the `refund` category, "Refund" in practice). */
    private function isRefund(string $transactionType): bool
    {
        foreach ((array) config('sales_channels.amazon.finance_map.refund', []) as $pattern) {
            if (preg_match($pattern, $transactionType) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * What a refunded amount gives back, by the type of its breakdown (`sales_channels.amazon.refund_parts`; the first part whose pattern matches wins, so "Shipping tax" is
     * tax): the goods, the shipping, a promotion that was taken off, the tax. Anything else is OTHER_PART.
     *
     * @param list<string> $path
     */
    private function refundPart(array $path): string
    {
        $leaf = end($path) ?: '';
        foreach ((array) config('sales_channels.amazon.refund_parts', []) as $part => $patterns) {
            foreach ((array) $patterns as $pattern) {
                if (preg_match($pattern, $leaf) === 1) {
                    return (string) $part;
                }
            }
        }

        return self::OTHER_PART;
    }

    /**
     * Identifiers come as `relatedIdentifierName` / `relatedIdentifierValue` on a transaction and, in the model's own spelling, as `itemRelatedIdentifierName` /
     * `itemRelatedIdentifierValue` on an item: both are read.
     *
     * @param list<array<string, mixed>> $identifiers
     */
    private function related(array $identifiers, string $name): ?string
    {
        foreach ($identifiers as $identifier) {
            if (($identifier['relatedIdentifierName'] ?? $identifier['itemRelatedIdentifierName'] ?? null) === $name) {
                return (string) ($identifier['relatedIdentifierValue'] ?? $identifier['itemRelatedIdentifierValue'] ?? '');
            }
        }

        return null;
    }

    /** @param array<string, mixed> $transaction */
    private function contextValue(array $transaction, string $key): ?string
    {
        foreach ([(array) ($transaction['contexts'] ?? []), ...array_map(static fn ($item): array => (array) ($item['contexts'] ?? []), (array) ($transaction['items'] ?? []))] as $contexts) {
            foreach ($contexts as $context) {
                if (isset($context[$key]) && $context[$key] !== '') {
                    return (string) $context[$key];
                }
            }
        }

        return null;
    }
}
