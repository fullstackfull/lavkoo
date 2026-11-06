<?php

namespace Tests\Support;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryLocation;
use App\Models\OperationalCostContract;
use App\Models\OperationalCostEntry;
use App\Models\SalesChannelAccount;
use App\Services\OperationalCosts\ContractService;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Data\CostDraft;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The books of the operational-cost admin tests, written through the real services (ContractService, CostLedger) the way the application writes them. Every price,
 * fee and total here is an arbitrary number made up for the test: the module ships with none.
 */
trait CreatesOperationalCostData
{
    private int $costSequence = 0;

    /** @var array<string, SalesChannelAccount> */
    private array $costChannelAccounts = [];

    protected function draftContract(string $provider = 'torod_point', int $seller = 0, string $from = '2026-01-01', string $currency = 'SAR', string $partyType = 'fulfillment_provider'): OperationalCostContract
    {
        return app(ContractService::class)->createDraft([
            'party_type' => $partyType, 'provider_code' => $provider, 'party_name' => 'Test company ' . $provider, 'currency' => $currency,
            'effective_from' => $from, 'reference' => 'TEST-1', 'settings' => ['expected_components' => ['shipping']],
        ], adminId: 1, sellerId: $seller);
    }

    protected function activeContract(string $provider = 'torod_point', string $amount = '12', int $seller = 0, string $from = '2026-01-01', string $currency = 'SAR'): OperationalCostContract
    {
        $contracts = app(ContractService::class);
        $contract = $this->draftContract($provider, $seller, $from, $currency);
        $contracts->addRule($contract, ['service_type' => 'shipping', 'calc_method' => 'per_shipment', 'params' => ['amount' => $amount]], adminId: 1);
        $contracts->activate($contract, 1, 'a test contract with invented numbers');

        return $contract->fresh();
    }

    protected function stockLocation(string $name = 'Torod warehouse', int $seller = 0, string $type = InventoryLocation::TYPE_TOROD, ?string $provider = 'torod_point'): InventoryLocation
    {
        return InventoryLocation::create([
            'code' => 'loc-' . ++$this->costSequence, 'name' => $name, 'seller_id' => $seller, 'type' => $type, 'provider' => $provider,
            'source_of_truth' => InventoryLocation::TRUTH_MANUAL, 'is_active' => true,
        ]);
    }

    /**
     * One cost in the ledger, in the reporting currency (SAR) unless said otherwise. A missing cost has no amount.
     *
     * @param array<string, mixed> $with seller, provider, channel, accountId, orderId, mappingId, productId, reference, reason, note, subjectType, subjectId, serviceRef, source, contractId, contractVersion, identity, currency
     */
    protected function recordCost(CostState $state, ?string $amount, CostComponent $component = CostComponent::Shipping, string $date = '2026-11-10', array $with = []): OperationalCostEntry
    {
        $sequence = ++$this->costSequence;
        $currency = $with['currency'] ?? 'SAR';
        $money = $amount === null ? null : Money::of($amount);

        return app(CostLedger::class)->record(new CostDraft(
            subjectType: $with['subjectType'] ?? 'shipment',
            subjectId: $with['subjectId'] ?? $sequence,
            component: $component,
            state: $state,
            amount: $money,
            currency: $currency,
            periodDate: CarbonImmutable::parse($date),
            source: $with['source'] ?? ($state === CostState::Estimated ? 'rule' : 'invoice'),
            identity: $with['identity'] ?? 'test-' . $sequence,
            serviceRef: $with['serviceRef'] ?? '',
            sellerId: $with['seller'] ?? 0,
            salesChannel: $with['channel'] ?? null,
            accountId: $with['accountId'] ?? null,
            orderId: $with['orderId'] ?? null,
            externalOrderMappingId: $with['mappingId'] ?? null,
            productId: $with['productId'] ?? null,
            provider: array_key_exists('provider', $with) ? $with['provider'] : 'torod_point',
            contractId: $with['contractId'] ?? null,
            contractVersion: $with['contractVersion'] ?? null,
            externalReference: $with['reference'] ?? null,
            missingReason: $state === CostState::Missing ? ($with['reason'] ?? 'no_contract') : null,
            note: $with['note'] ?? null,
            occurredAt: CarbonImmutable::parse($date),
            fxRate: $money !== null && $currency === 'SAR' ? Money::of(1) : null,
            baseAmount: $money !== null && $currency === 'SAR' ? $money : null,
            baseCurrency: $money !== null && $currency === 'SAR' ? 'SAR' : null,
        ))->entry;
    }

    /** A delivered channel order of one line, in riyals with no tax in its prices: its revenue is exactly `$lineTotal`. */
    protected function channelSale(string $lineTotal = '100', string $channel = 'trendyol', string $purchasedAt = '2026-11-05 09:00:00', int $seller = 0, int $productId = 50): ExternalOrderMapping
    {
        $account = $this->costChannelAccounts[$channel] ??= $channel === 'amazon' ? $this->amazonAccount() : $this->trendyolAccount();
        $sequence = ++$this->costSequence;

        $mapping = ExternalOrderMapping::create([
            'account_id' => $account->id, 'sales_channel' => $channel, 'seller_id' => $seller, 'external_order_id' => 'ORD-' . $sequence,
            'external_package_id' => $channel === 'amazon' ? '' : 'PKG-' . $sequence, 'status' => 'delivered', 'currency' => 'SAR',
            'fulfillment_mode' => 'merchant_managed', 'prices_include_tax' => false, 'purchased_at' => $purchasedAt, 'order_total' => $lineTotal, 'items_total' => $lineTotal,
        ]);
        ExternalOrderLine::create([
            'mapping_id' => $mapping->id, 'external_line_id' => 'L' . $sequence, 'sku' => 'SKU-' . $productId, 'product_id' => $productId, 'quantity' => 1,
            'quantity_cancelled' => 0, 'line_total' => $lineTotal, 'discount_total' => '0', 'tax_total' => '0', 'currency' => 'SAR', 'status' => 'open',
        ]);

        return $mapping;
    }

    /** A delivered storefront order of one line. Its amounts are in the platform's stored unit (USD-based): the services convert them to riyals. */
    protected function storefrontSale(int $orderId = 900, int $storedPrice = 40, string $createdAt = '2026-11-05 10:00:00', int $productId = 50): int
    {
        DB::table('orders')->insert([
            'id' => $orderId, 'order_status' => 'delivered', 'payment_status' => 'paid', 'order_amount' => $storedPrice, 'shipping_cost' => 0, 'seller_is' => 'admin',
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
        DB::table('order_details')->insert(['order_id' => $orderId, 'product_id' => $productId, 'qty' => 1, 'price' => $storedPrice, 'discount' => 0, 'tax' => 0, 'created_at' => $createdAt, 'updated_at' => $createdAt]);

        return $orderId;
    }

    /** @return list<string> the messages of the toasts the last response flashed */
    protected function flashedToasts(): array
    {
        return array_map(static fn (array $toast): string => (string) ($toast['message'] ?? ''), array_values((array) session('laravel-toaster-magic::messages', [])));
    }
}
