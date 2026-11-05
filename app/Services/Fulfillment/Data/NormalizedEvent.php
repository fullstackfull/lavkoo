<?php

namespace App\Services\Fulfillment\Data;

use Carbon\CarbonInterface;

/** A provider's inbound event reduced to the few facts the processor needs, whatever the provider's payload looked like. */
final readonly class NormalizedEvent
{
    /**
     * @param array<string, string|null> $references named identifiers found in the payload (barcode, invoice, package_barcode…)
     * @param array<string, mixed> $meta values worth keeping on the fulfillment (postponed date, driver notes…)
     */
    public function __construct(
        public string $type,
        public string $dedupeKey,
        public array $references,
        public ?string $providerStatus,
        public ?CarbonInterface $occurredAt,
        public bool $informational = false,
        public ?string $warehouseStatus = null,
        public array $meta = [],
        public ?string $note = null,
    ) {
    }

    public function reference(string $name): ?string
    {
        $value = $this->references[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
