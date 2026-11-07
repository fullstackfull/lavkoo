<?php

namespace App\Services\OperationalCosts\Invoices;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\ExternalOrderMapping;
use App\Models\OperationalCostEntry;
use App\Models\OperationalCostInvoice;
use App\Models\OperationalCostInvoiceItem;
use App\Models\ProviderFulfillment;
use App\Services\AuditLogger;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Data\CostDraft;
use App\Services\OperationalCosts\Data\LayerReceipt;
use App\Services\OperationalCosts\Data\RecordOutcome;
use App\Services\OperationalCosts\Exceptions\InvalidContractChange;
use App\Services\OperationalCosts\InventoryCostLayerService;
use App\Services\OperationalCosts\Shipments\ShipmentCostService;
use App\Services\OperationalCosts\Shipments\ShipmentFactsFactory;
use App\Services\OperationalCosts\Support\FxConverter;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Invoices from the companies that charge us: a fulfilment provider's monthly invoice, a marketplace statement, a supplier's invoice for goods.
 *
 *  - CREATE keeps the invoice and its lines exactly as received. The same company and invoice number is refused as a duplicate (the same
 *    file, too); an invoice that merely looks like another (same company, currency, total and period) is kept but held for a person.
 *  - APPROVE turns the lines into costs. A service line becomes the ACTUAL cost of the service it is about and the estimate of that service stops counting; a
 *    purchase line becomes a cost layer at the invoice's location (freight lines on the same invoice are added to those units). Approving twice changes nothing.
 *      - A line about a SHIPMENT is a charge of its own (a shipment can be billed in several lines, and a credit note is a line with a minus sign, so they add up); the
 *        first positive one retires the estimate of the same service, and it is split over the shipment's items like the estimate was.
 *      - A line about a MARKETPLACE order takes the channel's own slot for that fee (the one its estimate and its statement figure use), so the same commission arriving as
 *        an estimate, in the statement and on the invoice is one cost; several lines of one order and fee in the same invoice (a commission and a refund of it) are netted.
 *      - A marketplace fee billed as one total for a period, with no order on the line, is not posted when the channel's statements already booked that fee in that period: it
 *        is compared with their total (ReconciliationService::settleAgainstStatements), so the fee is not counted twice. A line the statements say nothing about is posted in
 *        full, and is set against the statements when they arrive (the importer does it as it posts them).
 *
 * An invoice that is approved is never edited. A mistake is a new invoice, a credit note or a reversal.
 */
class InvoiceService
{
    public const KINDS = ['service', 'purchase', 'marketplace_statement'];

    public function __construct(
        private readonly CostLedger $ledger,
        private readonly InventoryCostLayerService $layers,
        private readonly InvoiceLinkResolver $links,
        private readonly FxConverter $fx,
        private readonly AuditLogger $audit,
        private readonly ShipmentCostService $shipmentCosts,
        private readonly ShipmentFactsFactory $shipmentFacts,
        private readonly ReconciliationService $reconciliation,
        private readonly PurchaseOrderInvoiceLinks $purchaseOrders,
    ) {
    }

    /**
     * @param array<string, mixed> $data kind, party_type, provider_code, party_name, invoice_number, invoice_date, currency, total, [period_start, period_end, subtotal, tax_total, inventory_location_id, file_hash, file_path, account_id, notes, import_batch, supplier_id]
     * @param list<array<string, mixed>> $items cost_component, amount, [description, quantity, unit_price, tax_amount, external_reference, service_date, period_start, period_end, product_id, variant_key, sku, order_id, provider_fulfillment_id, external_order_mapping_id, purchase_order_item_id]
     * @throws InvalidContractChange
     */
    public function create(array $data, array $items, ?int $adminId, string $source = 'manual', int $sellerId = 0): InvoiceResult
    {
        $clean = $this->invoiceAttributes($data);
        $rows = $this->itemAttributes($items, $clean);
        $clean += array_filter(['supplier_id' => $this->purchaseOrders->supplierOf($clean, $rows)]);
        $resembles = $this->purchaseOrders->resemblances($clean, $rows);

        $existing = OperationalCostInvoice::where('provider_code', $clean['provider_code'])->where('invoice_number', $clean['invoice_number'])->where('seller_id', $sellerId)->first();
        if ($existing !== null) {
            return new InvoiceResult(InvoiceResult::DUPLICATE, $existing, ['same_invoice_number']);
        }
        if (($clean['file_hash'] ?? null) !== null) {
            $sameFile = OperationalCostInvoice::where('provider_code', $clean['provider_code'])->where('file_hash', $clean['file_hash'])->where('seller_id', $sellerId)->first();
            if ($sameFile !== null) {
                return new InvoiceResult(InvoiceResult::DUPLICATE, $sameFile, ['same_file']);
            }
        }

        $warnings = [];
        $lookalike = $this->lookalike($clean, $sellerId);
        if ($lookalike !== null) {
            $warnings[] = 'looks_like_invoice_' . $lookalike->id;
        }
        $itemsTotal = Money::sum(array_map(static fn (array $row): Money => Money::of($row['amount']), $rows));
        $expected = Money::of($clean['subtotal'] ?? $clean['total']);
        if ($rows !== [] && $itemsTotal->minus($expected)->abs()->compareTo('0.01') > 0) {
            $warnings[] = 'items_do_not_add_up';
        }

        try {
            $invoice = DB::transaction(function () use ($clean, $rows, $adminId, $source, $sellerId, $lookalike, $warnings, $resembles): OperationalCostInvoice {
                $invoice = OperationalCostInvoice::create($clean + [
                    'seller_id' => $sellerId,
                    'status' => $lookalike !== null || $warnings !== [] || $resembles !== [] ? OperationalCostInvoice::STATUS_UNDER_REVIEW : OperationalCostInvoice::STATUS_DRAFT,
                    'source' => $source,
                    'duplicate_of_id' => $lookalike?->id,
                    'created_by' => $adminId,
                ]);
                foreach ($rows as $index => $row) {
                    $item = $invoice->items()->create($row + array_filter(['resembles_purchase_order_item_id' => $resembles[$index] ?? null]) + ['line_no' => $index + 1, 'dedupe_key' => $this->itemKey($invoice, $index + 1, $row)]);
                    $link = $this->links->resolve($invoice->provider_code, $item);
                    if ($link !== []) {
                        $item->forceFill($link)->save();
                    }
                }

                return $invoice;
            });
        } catch (UniqueConstraintViolationException) {
            return new InvoiceResult(InvoiceResult::DUPLICATE, OperationalCostInvoice::where('provider_code', $clean['provider_code'])->where('invoice_number', $clean['invoice_number'])->where('seller_id', $sellerId)->firstOrFail(), ['same_invoice_number']);
        }

        $this->audit->record(action: 'operational_cost.invoice_created', subject: $invoice, context: ['invoice_id' => $invoice->id, 'provider' => $invoice->provider_code, 'warnings' => $warnings, 'resembles_purchase_order_lines' => array_values($resembles)]);

        return new InvoiceResult(
            match (true) {
                $lookalike !== null => InvoiceResult::POSSIBLE_DUPLICATE,
                $resembles !== [] => InvoiceResult::RESEMBLES_RECEIPT,
                default => InvoiceResult::CREATED,
            },
            $invoice,
            [...$warnings, ...array_map(static fn (int $line): string => 'looks_like_receipt_' . $line, array_values($resembles))],
        );
    }

    /** A person has looked: this invoice is not a copy of the one it resembles, nor of goods a purchase order already received. */
    public function markNotDuplicate(OperationalCostInvoice $invoice, ?int $adminId): OperationalCostInvoice
    {
        $this->purchaseOrders->release($invoice);
        $invoice->forceFill(['duplicate_of_id' => null, 'status' => $invoice->status === OperationalCostInvoice::STATUS_UNDER_REVIEW ? OperationalCostInvoice::STATUS_DRAFT : $invoice->status])->save();
        $this->audit->record(action: 'operational_cost.invoice_not_duplicate', subject: $invoice, context: ['invoice_id' => $invoice->id, 'by' => $adminId]);

        return $invoice;
    }

    /**
     * Turns an invoice into costs. Returns the number of lines that produced a cost or a cost layer.
     *
     * @throws InvalidContractChange when the invoice cannot be approved yet
     */
    public function approve(OperationalCostInvoice $invoice, ?int $adminId): int
    {
        if ($invoice->status === OperationalCostInvoice::STATUS_APPROVED) {
            return 0;
        }
        if ($invoice->status === OperationalCostInvoice::STATUS_VOID) {
            throw new InvalidContractChange('A void invoice cannot be approved.');
        }
        if ($invoice->duplicate_of_id !== null) {
            throw new InvalidContractChange("This invoice looks like invoice #{$invoice->duplicate_of_id}: confirm it is not a copy before approving it.");
        }
        if ($invoice->kind === 'purchase' && $invoice->inventory_location_id === null) {
            throw new InvalidContractChange('A purchase invoice needs the location its goods arrived at.');
        }

        $count = DB::transaction(function () use ($invoice, $adminId): int {
            $items = $invoice->items()->lockForUpdate()->get();
            $this->purchaseOrders->assertMayBeApproved($invoice, $items);
            $count = $invoice->kind === 'purchase' ? $this->receiveGoods($invoice, $items) : $this->recordServices($invoice, $items);
            $invoice->forceFill(['status' => OperationalCostInvoice::STATUS_APPROVED, 'approved_at' => now(), 'approved_by' => $adminId])->save();

            return $count;
        }, attempts: 3);
        $this->audit->record(action: 'operational_cost.invoice_approved', subject: $invoice, context: ['invoice_id' => $invoice->id, 'lines' => $count, 'by' => $adminId]);

        return $count;
    }

    public function void(OperationalCostInvoice $invoice, ?int $adminId, string $reason): OperationalCostInvoice
    {
        if ($invoice->status === OperationalCostInvoice::STATUS_APPROVED) {
            throw new InvalidContractChange('An approved invoice is not edited or voided: record a credit note or reverse its entries.');
        }
        $invoice->forceFill(['status' => OperationalCostInvoice::STATUS_VOID, 'notes' => trim($invoice->notes . "\n" . $reason)])->save();
        $this->audit->record(action: 'operational_cost.invoice_voided', subject: $invoice, context: ['invoice_id' => $invoice->id, 'by' => $adminId, 'reason' => $reason]);

        return $invoice;
    }

    /** @param \Illuminate\Support\Collection<int, OperationalCostInvoiceItem> $items */
    private function recordServices(OperationalCostInvoice $invoice, $items): int
    {
        $mappings = ExternalOrderMapping::whereIn('id', $items->pluck('external_order_mapping_id')->filter()->unique()->all())->get()->keyBy('id');
        $marketplaceNets = $this->marketplaceNets($invoice, $items, $mappings);

        $count = 0;
        foreach ($items as $item) {
            if ($item->match_status === OperationalCostInvoiceItem::IGNORED) {
                continue;
            }
            $component = CostComponent::tryFrom($item->cost_component) ?? CostComponent::Other;
            $mapping = $item->external_order_mapping_id === null ? null : $mappings->get($item->external_order_mapping_id);
            [$subjectType, $subjectId] = $this->subjectOf($invoice, $item, $mapping);
            if ($subjectType === 'invoice_item' && $this->reconciliation->settleAgainstStatements($invoice, $item)) {
                $count++;
                continue;
            }
            $date = CarbonImmutable::parse($item->service_date ?? $invoice->period_end ?? $invoice->invoice_date);

            $net = $subjectType === 'channel_order' ? ($marketplaceNets[$this->netKey($item, $component)] ?? null) : null;
            $amount = $net['amount'] ?? Money::of($item->amount);
            $fx = $this->fx->convert($amount, $item->currency, $this->fx->baseCurrency());
            $forAShipment = in_array($subjectType, ['shipment', 'external_shipment'], true);

            $outcome = $this->ledger->record(new CostDraft(
                subjectType: $subjectType,
                subjectId: $subjectId,
                component: $component,
                state: CostState::Actual,
                amount: $amount,
                currency: $item->currency,
                periodDate: $date,
                source: 'invoice',
                identity: $net === null ? 'invoice-item:' . $item->id : 'invoice:' . $invoice->id . ':' . $net['key'],
                serviceRef: match ($subjectType) {
                    'channel_order' => $item->currency === $mapping?->currency ? '' : 'channel-net:' . $item->currency,
                    'invoice_item' => '',
                    default => 'invoice-item:' . $item->id,
                },
                sellerId: (int) $invoice->seller_id,
                salesChannel: $mapping?->sales_channel,
                accountId: $mapping?->account_id ?? $invoice->account_id,
                orderId: $item->order_id,
                externalOrderMappingId: $item->external_order_mapping_id,
                providerFulfillmentId: $item->provider_fulfillment_id,
                provider: $invoice->provider_code,
                inventoryLocationId: $invoice->inventory_location_id,
                productId: $item->product_id,
                variantKey: (string) $item->variant_key,
                sku: $item->sku,
                invoiceItemId: (int) $item->id,
                externalReference: $item->external_reference ?? $invoice->invoice_number,
                calcInputs: ['invoice_id' => (int) $invoice->id, 'invoice_number' => $invoice->invoice_number, 'line' => (int) $item->line_no],
                note: $item->description === null ? null : mb_substr($item->description, 0, 500),
                occurredAt: $date,
                quantity: $item->quantity === null ? null : Money::of($item->quantity),
                unitAmount: $item->unit_price === null ? null : Money::of($item->unit_price),
                fxRate: $fx['rate'] ?? null,
                baseAmount: $fx['amount'] ?? null,
                baseCurrency: $fx === null ? null : $this->fx->baseCurrency(),
                allocationStatus: match (true) {
                    $subjectType === 'invoice_item' => 'unallocated',
                    $forAShipment => 'pending',
                    default => 'not_applicable',
                },
                retiresEstimates: $subjectType !== 'invoice_item' && $amount->isPositive(),
            ));
            if ($forAShipment && $outcome->result === RecordOutcome::CREATED) {
                $this->allocateToItems($outcome->entry, $subjectType, $subjectId);
            }

            $item->forceFill([
                'entry_id' => $outcome->entry->id,
                'match_status' => $subjectType === 'invoice_item' ? OperationalCostInvoiceItem::UNMATCHED : OperationalCostInvoiceItem::MATCHED,
            ])->save();
            $count++;
        }

        return $count;
    }

    /**
     * Where an invoice line belongs in the ledger: the shipment it was billed for, the marketplace order it is a fee of, or, about nothing we know of, a cost of its own
     * that stays unallocated until a person links it.
     *
     * @return array{0: string, 1: int} subject type, subject id
     */
    private function subjectOf(OperationalCostInvoice $invoice, OperationalCostInvoiceItem $item, ?ExternalOrderMapping $mapping): array
    {
        if ($item->provider_fulfillment_id !== null) {
            return ['shipment', (int) $item->provider_fulfillment_id];
        }
        if ($mapping !== null) {
            $isShipmentCost = $mapping->provider !== null && $mapping->provider === $invoice->provider_code;

            return [$isShipmentCost ? 'external_shipment' : 'channel_order', (int) $mapping->id];
        }

        return ['invoice_item', (int) $item->id];
    }

    /**
     * What each marketplace order and fee of this invoice comes to in total (a commission and the refund of part of it are one fee, as the statement nets them).
     *
     * @param \Illuminate\Support\Collection<int, OperationalCostInvoiceItem> $items
     * @param \Illuminate\Support\Collection<int, ExternalOrderMapping> $mappings
     * @return array<string, array{amount: Money, key: string}>
     */
    private function marketplaceNets(OperationalCostInvoice $invoice, $items, $mappings): array
    {
        $nets = [];
        foreach ($items as $item) {
            $mapping = $item->external_order_mapping_id === null ? null : $mappings->get($item->external_order_mapping_id);
            if ($item->match_status === OperationalCostInvoiceItem::IGNORED || $item->provider_fulfillment_id !== null || $mapping === null || $this->subjectOf($invoice, $item, $mapping)[0] !== 'channel_order') {
                continue;
            }
            $key = $this->netKey($item, CostComponent::tryFrom($item->cost_component) ?? CostComponent::Other);
            $nets[$key] = ['key' => $key, 'amount' => ($nets[$key]['amount'] ?? Money::zero())->plus(Money::of($item->amount))];
        }

        return $nets;
    }

    private function netKey(OperationalCostInvoiceItem $item, CostComponent $component): string
    {
        return $item->external_order_mapping_id . ':' . $component->value . ':' . $item->currency;
    }

    private function allocateToItems(OperationalCostEntry $entry, string $subjectType, int $subjectId): void
    {
        $facts = match ($subjectType) {
            'shipment' => ($fulfillment = ProviderFulfillment::find($subjectId)) === null ? null : $this->shipmentFacts->forFulfillment($fulfillment),
            default => ($mapping = ExternalOrderMapping::find($subjectId)) === null ? null : $this->shipmentFacts->forExternalShipment($mapping),
        };
        if ($facts !== null) {
            $this->shipmentCosts->allocateActual($entry, $facts);
        }
    }

    /**
     * A purchase line becomes a cost layer at the invoice's location, with the freight of the invoice added to the goods it was shared over. A line linked to a purchase order line
     * makes no layer (the order's receipt is the layer): it records how far its cost is from what the order says, for a person.
     *
     * @param \Illuminate\Support\Collection<int, OperationalCostInvoiceItem> $items
     */
    private function receiveGoods(OperationalCostInvoice $invoice, $items): int
    {
        $products = $items->filter(static fn (OperationalCostInvoiceItem $item): bool => $item->product_id !== null && (float) $item->quantity > 0 && $item->match_status !== OperationalCostInvoiceItem::IGNORED);
        $linked = $products->filter(static fn (OperationalCostInvoiceItem $item): bool => $item->purchase_order_item_id !== null);
        $products = $products->diffKeys($linked);
        $freight = Money::sum($items->filter(static fn (OperationalCostInvoiceItem $item): bool => $item->product_id === null && $item->cost_component === CostComponent::InboundFreight->value)->map(static fn (OperationalCostInvoiceItem $item): Money => Money::of($item->amount)));
        $goodsValue = Money::sum($products->map(static fn (OperationalCostInvoiceItem $item): Money => Money::of($item->amount)));
        $freightShares = $products->isEmpty() || $freight->isZero() ? [] : $freight->allocate($products->mapWithKeys(static fn (OperationalCostInvoiceItem $item): array => [$item->id => $goodsValue->isZero() ? Money::of(1) : Money::of($item->amount)])->all());

        $count = 0;
        foreach ($linked as $item) {
            $expected = $this->purchaseOrders->expectedCost($item);
            $this->reconciliation->compareWithPurchaseOrder($invoice, $item, $expected === null ? null : $expected['amount'], $expected['currency'] ?? null);
            $count++;
        }
        foreach ($products as $item) {
            $quantity = (int) round((float) $item->quantity);
            if ($quantity < 1) {
                continue;
            }
            $unitCost = Money::of($item->amount)->dividedBy($quantity);
            $landed = isset($freightShares[$item->id]) ? $freightShares[$item->id]->dividedBy($quantity) : null;
            $this->layers->receive(new LayerReceipt(
                locationId: (int) $invoice->inventory_location_id,
                productId: (int) $item->product_id,
                quantity: $quantity,
                unitCost: $unitCost,
                currency: $item->currency,
                receivedAt: CarbonImmutable::parse($invoice->invoice_date),
                sourceType: 'purchase_invoice',
                identity: 'invoice-item:' . $item->id,
                variantKey: (string) $item->variant_key,
                sku: $item->sku,
                sourceId: (int) $invoice->id,
                sourceRef: $invoice->invoice_number,
                landedUnitCost: $landed,
                supplierName: $invoice->party_name,
                sellerId: (int) $invoice->seller_id,
                supplierId: $invoice->getAttribute('supplier_id') === null ? null : (int) $invoice->supplier_id,
            ));
            $item->forceFill(['match_status' => OperationalCostInvoiceItem::MATCHED])->save();
            $count++;
        }

        return $count;
    }

    /** The unreviewed twin of an invoice: same company, currency and total, in the same period, under a different number. */
    private function lookalike(array $clean, int $sellerId): ?OperationalCostInvoice
    {
        return OperationalCostInvoice::query()
            ->where('seller_id', $sellerId)
            ->where('provider_code', $clean['provider_code'])
            ->where('currency', $clean['currency'])
            ->where('total', Money::of($clean['total'])->toStorage())
            ->where('status', '!=', OperationalCostInvoice::STATUS_VOID)
            ->when(isset($clean['period_start'], $clean['period_end']), static fn ($query) => $query->whereDate('period_start', $clean['period_start'])->whereDate('period_end', $clean['period_end']),
                static fn ($query) => $query->whereDate('invoice_date', $clean['invoice_date']))
            ->first();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function invoiceAttributes(array $data): array
    {
        $kind = (string) ($data['kind'] ?? 'service');
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidContractChange('Unknown kind of invoice.');
        }
        foreach (['provider_code', 'party_name', 'invoice_number', 'invoice_date', 'currency'] as $required) {
            if (trim((string) ($data[$required] ?? '')) === '') {
                throw new InvalidContractChange("The invoice needs its {$required}.");
            }
        }
        try {
            $total = Money::of((string) ($data['total'] ?? ''));
            $date = CarbonImmutable::parse((string) $data['invoice_date'])->toDateString();
            $start = ($data['period_start'] ?? '') === '' ? null : CarbonImmutable::parse((string) $data['period_start'])->toDateString();
            $end = ($data['period_end'] ?? '') === '' ? null : CarbonImmutable::parse((string) $data['period_end'])->toDateString();
        } catch (InvalidArgumentException|\Carbon\Exceptions\InvalidFormatException) {
            throw new InvalidContractChange('The invoice total and dates must be valid.');
        }
        if ($start !== null && $end !== null && $end < $start) {
            throw new InvalidContractChange('The invoice period ends before it starts.');
        }

        return [
            'kind' => $kind,
            'party_type' => (string) ($data['party_type'] ?? 'other'),
            'provider_code' => trim((string) $data['provider_code']),
            'party_name' => mb_substr(trim((string) $data['party_name']), 0, 160),
            'invoice_number' => mb_substr(trim((string) $data['invoice_number']), 0, 120),
            'invoice_date' => $date,
            'period_start' => $start,
            'period_end' => $end,
            'currency' => strtoupper(trim((string) $data['currency'])),
            'subtotal' => ($data['subtotal'] ?? '') === '' ? null : Money::of((string) $data['subtotal'])->toStorage(),
            'tax_total' => ($data['tax_total'] ?? '') === '' ? null : Money::of((string) $data['tax_total'])->toStorage(),
            'total' => $total->toStorage(),
            'inventory_location_id' => ($data['inventory_location_id'] ?? null) ?: null,
            'account_id' => ($data['account_id'] ?? null) ?: null,
            'file_path' => $data['file_path'] ?? null,
            'file_hash' => $data['file_hash'] ?? null,
            'import_batch' => $data['import_batch'] ?? null,
            'notes' => $data['notes'] ?? null,
        ] + array_filter(['supplier_id' => ($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : null]);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $invoice
     * @return list<array<string, mixed>>
     */
    private function itemAttributes(array $items, array $invoice): array
    {
        $rows = [];
        foreach ($items as $index => $item) {
            $line = $index + 1;
            try {
                $amount = Money::of((string) ($item['amount'] ?? ''));
            } catch (InvalidArgumentException) {
                throw new InvalidContractChange("Line {$line}: the amount must be a number.");
            }
            $component = (string) ($item['cost_component'] ?? 'other');
            if (CostComponent::tryFrom($component) === null) {
                throw new InvalidContractChange("Line {$line}: unknown kind of cost '{$component}'.");
            }
            $currency = strtoupper(trim((string) ($item['currency'] ?? $invoice['currency'])));
            if ($currency !== $invoice['currency']) {
                throw new InvalidContractChange("Line {$line}: every line of an invoice is in the invoice currency.");
            }
            $rows[] = [
                'description' => isset($item['description']) ? mb_substr((string) $item['description'], 0, 500) : null,
                'cost_component' => $component,
                'quantity' => ($item['quantity'] ?? '') === '' ? null : Money::of((string) $item['quantity'])->toStorage(),
                'unit_price' => ($item['unit_price'] ?? '') === '' ? null : Money::of((string) $item['unit_price'])->toStorage(),
                'amount' => $amount->toStorage(),
                'tax_amount' => ($item['tax_amount'] ?? '') === '' ? null : Money::of((string) $item['tax_amount'])->toStorage(),
                'currency' => $currency,
                'product_id' => ($item['product_id'] ?? null) ?: null,
                'variant_key' => (string) ($item['variant_key'] ?? ''),
                'sku' => $item['sku'] ?? null,
                'reference_type' => $item['reference_type'] ?? null,
                'order_id' => ($item['order_id'] ?? null) ?: null,
                'provider_fulfillment_id' => ($item['provider_fulfillment_id'] ?? null) ?: null,
                'external_order_mapping_id' => ($item['external_order_mapping_id'] ?? null) ?: null,
                'external_reference' => isset($item['external_reference']) ? mb_substr(trim((string) $item['external_reference']), 0, 191) : null,
                'service_date' => ($item['service_date'] ?? '') === '' ? null : CarbonImmutable::parse((string) $item['service_date'])->toDateString(),
                'period_start' => ($item['period_start'] ?? '') === '' ? null : CarbonImmutable::parse((string) $item['period_start'])->toDateString(),
                'period_end' => ($item['period_end'] ?? '') === '' ? null : CarbonImmutable::parse((string) $item['period_end'])->toDateString(),
                'match_status' => OperationalCostInvoiceItem::UNMATCHED,
                'raw' => $item['raw'] ?? null,
            ] + array_filter(['purchase_order_item_id' => ($item['purchase_order_item_id'] ?? null) ? (int) $item['purchase_order_item_id'] : null]);
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function itemKey(OperationalCostInvoice $invoice, int $line, array $row): string
    {
        return hash('sha256', implode('|', ['invoice-item', $invoice->seller_id, $invoice->provider_code, $invoice->invoice_number, $line, $row['cost_component'], $row['amount'], $row['external_reference'] ?? '']));
    }
}
