<?php

namespace App\Services\Fulfillment\Providers\TorodPoint;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\FieldMapStore;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\Support\PiiRedactor;
use App\Services\Fulfillment\Support\ProviderConfig;
use App\Services\Fulfillment\Support\SecretRedactor;
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
 * The confirmation names what it was made for: the path the invoice number is read from, and the account (company and base
 * URL). Changing the path removes it; another account makes it lapse. It is stored with the provider's settings, so every
 * change of it is in the audit log with who made it.
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
     * The confirmation that applies to the path the orders map reads the invoice number from now, for the account that is set now.
     *
     * @return array{confirmed_at: string, confirmed_by: int|null, confirmed_by_name: string, evidence: string, invoice_path: string, company_id: string, base_url: string}|null
     */
    public function confirmation(ProviderConfig $config): ?array
    {
        $record = $config->get(self::SETTING);
        $path = $this->invoicePath();

        $applies = is_array($record) && $path !== null
            && ($record['invoice_path'] ?? null) === $path
            && ($record['company_id'] ?? null) === $config->string('company_id')
            && ($record['base_url'] ?? null) === $config->baseUrl
            && trim((string) ($record['confirmed_at'] ?? '')) !== '';

        return $applies ? $record : null;
    }

    /**
     * Where the orders map reads our invoice number from in the provider's order list; a confirmation can only be made for a path that is set.
     * The order's own identifier (its barcode, its id, whatever the reference reads) is never an invoice number: a map pointed there has
     * no path, so nothing can be confirmed and no real order is sent.
     */
    public function invoicePath(): ?string
    {
        $path = $this->configuredPath();
        if ($path === null) {
            return null;
        }

        $referencePath = $this->fieldMaps->get(ProviderCode::TorodPoint, TorodFieldMaps::ORDERS)->map()->path('reference');

        return $path === $referencePath || $this->namesAnIdentifier($path) ? null : $path;
    }

    /** Does any step of the path carry an identifier's name, whatever its separators and casing: order_id, data.0.orderBarcode, id.value? */
    private function namesAnIdentifier(string $path): bool
    {
        foreach (explode('.', $path) as $step) {
            if (preg_match(TorodFieldMaps::IDENTIFIER_NAME, strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $step))) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The path the orders map has for the invoice number, whatever it is. */
    public function configuredPath(): ?string
    {
        return $this->fieldMaps->get(ProviderCode::TorodPoint, TorodFieldMaps::ORDERS)->map()->path('invoice_number');
    }

    /**
     * @param string $seenPath the path the administrator was shown next to the statement they confirmed: a page left open across a change of the path confirms nothing
     * @throws InvalidArgumentException when there is no usable path, the path is no longer the one shown, or the evidence is too short
     */
    public function confirm(int $adminId, string $adminName, string $evidence, string $seenPath): void
    {
        $path = $this->invoicePath();
        $evidence = trim(mb_substr(PiiRedactor::scrub($evidence, self::MAX_EVIDENCE_LENGTH * 4), 0, self::MAX_EVIDENCE_LENGTH));
        $substance = trim(str_replace([PiiRedactor::MASK, SecretRedactor::MASK], '', $evidence));
        if ($path === null || $seenPath !== $path || mb_strlen($substance) < self::MIN_EVIDENCE_LENGTH) {
            throw new InvalidArgumentException('The invoice number path shown, the path now set, and the evidence for the confirmation have to agree and be present.');
        }

        $config = $this->connections->config(ProviderCode::TorodPoint);
        $this->connections->save(
            provider: ProviderCode::TorodPoint,
            enabled: null,
            baseUrl: null,
            settings: [self::SETTING => [
                'confirmed_at' => now()->toDateTimeString(),
                'confirmed_by' => $adminId,
                'confirmed_by_name' => $adminName,
                'evidence' => $evidence,
                'invoice_path' => $path,
                'company_id' => $config->string('company_id'),
                'base_url' => $config->baseUrl,
            ]],
            auditContext: ['change' => 'invoice_round_trip_confirmed', 'invoice_path' => $path],
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

    /** A new path is a new question: the confirmation made for the old one is removed, so that the old path coming back does not bring it back. */
    public function withdrawIfPathChanged(?string $pathBefore): void
    {
        $record = $this->connections->config(ProviderCode::TorodPoint)->get(self::SETTING);

        if (is_array($record) && $pathBefore !== $this->configuredPath()) {
            $this->withdraw();
        }
    }
}
