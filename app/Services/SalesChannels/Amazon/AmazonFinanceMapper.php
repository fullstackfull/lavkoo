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
 * breakdowns are read only when the transaction carries none of its own, and then each fact names its item, so two items with the same fee are two facts.
 *
 * Identity is stable under redelivery (transaction id + item + path in the tree + amount + date), so importing a window twice, or a transaction that moves from DEFERRED to
 * released, records each fact once.
 */
class AmazonFinanceMapper
{
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
        $sku = $this->contextValue($transaction, 'sku');
        $currencyFallback = (string) ($transaction['totalAmount']['currencyCode'] ?? $account->currency ?? '');

        $leaves = $this->leavesOf($transaction);
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
            $cost = in_array($category, [RemoteFinancialEntry::FEE, RemoteFinancialEntry::ADJUSTMENT], true) ? $amount->negated() : $amount;

            $entries[] = new RemoteFinancialEntry(
                identity: hash('sha256', implode('|', array_filter(['amazon', $account->id, $transactionId, $leaf['item'] === null ? null : 'item:' . $leaf['item'], $path, $amount->toStorage(), $postedAt->toIso8601String()], static fn ($part): bool => $part !== null))),
                category: $category,
                component: $component ?? CostComponent::Other,
                amount: $cost,
                currency: strtoupper((string) ($leaf['currency'] ?: $currencyFallback)),
                orderId: $orderId,
                packageId: null,
                lineId: null,
                sku: $leaf['sku'] ?? $sku,
                postedAt: $postedAt,
                rawType: mb_substr($type . ' > ' . $path, 0, 190),
                meta: ['transaction_id' => $transactionId, 'status' => $transaction['transactionStatus'] ?? null],
            );
        }

        return $entries;
    }

    /**
     * The leaves of the transaction's own breakdowns, or, when it has none, of every item's.
     *
     * @param array<string, mixed> $transaction
     * @return list<array{path: list<string>, amount: mixed, currency: string, item: string|null, sku: string|null}>
     */
    private function leavesOf(array $transaction): array
    {
        $leaves = [];
        foreach ((array) ($transaction['breakdowns'] ?? []) as $breakdown) {
            $this->collect((array) $breakdown, [], $leaves, item: null, sku: null);
        }
        if ($leaves !== []) {
            return $leaves;
        }

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
    private function classify(string $transactionType, array $path): array
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

    /** @param list<array<string, mixed>> $identifiers */
    private function related(array $identifiers, string $name): ?string
    {
        foreach ($identifiers as $identifier) {
            if (($identifier['relatedIdentifierName'] ?? null) === $name) {
                return (string) $identifier['relatedIdentifierValue'];
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
