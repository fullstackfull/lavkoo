<?php

namespace App\Services\Fulfillment\Providers\TorodPoint;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\FieldMapStore;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\Support\ProviderConfig;
use InvalidArgumentException;

/**
 * Has anybody confirmed, independently of the field map, that the invoice number we send with an order comes back
 * unchanged in Torod Point's order list?
 *
 * Finding an order again after an ambiguous send (a timeout, a dropped connection) depends on it: the lookup compares OUR
 * invoice number with the one the provider lists. The field map can only say that the response has a field of that name,
 * and most historical warehouse orders carry null there. Only the provider's own confirmation, or an order created for
 * the purpose and read back, shows that the value we send is the value they return. Until a master administrator has
 * recorded that, with the evidence, no real order is submitted.
 *
 * The confirmation names the path it was made for, so changing the path withdraws it, and it is stored with the provider's
 * settings, so every change of it is in the audit log with who made it.
 */
final class TorodInvoiceRoundTrip
{
    public const SETTING = 'invoice_round_trip';
    public const MIN_EVIDENCE_LENGTH = 10;
    public const MAX_EVIDENCE_LENGTH = 500;

    public function __construct(
        private readonly ProviderConnectionManager $connections,
        private readonly FieldMapStore $fieldMaps,
    ) {
    }

    public function isConfirmed(ProviderConfig $config): bool
    {
        return $this->confirmation($config) !== null;
    }

    /**
     * The confirmation that applies to the path the orders map reads the invoice number from now, if there is one.
     *
     * @return array{confirmed_at: string, confirmed_by: int|null, confirmed_by_name: string, evidence: string, invoice_path: string}|null
     */
    public function confirmation(ProviderConfig $config): ?array
    {
        $record = $config->get(self::SETTING);
        $path = $this->invoicePath();

        if (!is_array($record) || $path === null || ($record['invoice_path'] ?? null) !== $path || trim((string) ($record['confirmed_at'] ?? '')) === '') {
            return null;
        }

        return $record;
    }

    /**
     * Where the orders map reads our invoice number from in the provider's order list; a confirmation can only be made for a path that is set.
     * The order's own identifier (its barcode, its id, whatever the reference reads) is never an invoice number: a map pointed there has
     * no path, so nothing can be confirmed and no real order is sent.
     */
    public function invoicePath(): ?string
    {
        $map = $this->fieldMaps->get(ProviderCode::TorodPoint, TorodFieldMaps::ORDERS)->map();
        $path = $map->path('invoice_number');
        $identifiers = [$map->path('reference'), ...TorodFieldMaps::CANDIDATES[TorodFieldMaps::ORDERS]['reference']];

        return $path !== null && !in_array($path, $identifiers, true) ? $path : null;
    }

    public function confirm(int $adminId, string $adminName, string $evidence): void
    {
        $path = $this->invoicePath();
        $evidence = trim($evidence);
        if ($path === null || mb_strlen($evidence) < self::MIN_EVIDENCE_LENGTH) {
            throw new InvalidArgumentException('The invoice number path and the evidence for the confirmation are both required.');
        }

        $this->connections->save(
            provider: ProviderCode::TorodPoint,
            enabled: null,
            baseUrl: null,
            settings: [self::SETTING => [
                'confirmed_at' => now()->toDateTimeString(),
                'confirmed_by' => $adminId,
                'confirmed_by_name' => $adminName,
                'evidence' => mb_substr($evidence, 0, self::MAX_EVIDENCE_LENGTH),
                'invoice_path' => $path,
            ]],
            auditContext: ['change' => 'invoice_round_trip_confirmed'],
        );
    }

    public function withdraw(): void
    {
        $this->connections->save(
            provider: ProviderCode::TorodPoint,
            enabled: null,
            baseUrl: null,
            settings: [self::SETTING => null],
            auditContext: ['change' => 'invoice_round_trip_withdrawn'],
        );
    }
}
