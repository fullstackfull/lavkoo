<?php

namespace App\Services\SalesChannels\Data;

use Carbon\CarbonInterface;

/**
 * What a channel requires of a product before it will list it, as the channel itself said it.
 *
 * `required` and `optional` are the channel's own field names. `unknown` is the honest answer when the channel could not be asked (a switch is off, the call failed): no field is
 * then claimed to be required or optional, and nothing may be sent on the strength of it.
 */
final readonly class ListingRequirements
{
    /** The channel's own answer. */
    public const FROM_THE_CHANNEL = 'channel';
    /** The owner's list for this product type, kept on the account, for a channel that will not say (or whose answer is behind a link this version does not fetch). */
    public const DECLARED_BY_THE_OWNER = 'declared';

    /**
     * @param list<string> $required
     * @param list<string> $optional
     * @param array<string, list<string>> $allowedValues field name => the values the channel accepts, where it says so
     */
    public function __construct(
        public string $productType,
        public array $required = [],
        public array $optional = [],
        public array $allowedValues = [],
        public ?CarbonInterface $readAt = null,
        public bool $unknown = false,
        public string $source = self::FROM_THE_CHANNEL,
    ) {
    }

    public static function unknown(string $productType = ''): self
    {
        return new self($productType, unknown: true);
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     * @param array<string, list<string>> $allowedValues
     */
    public static function declared(string $productType, array $required, array $optional = [], array $allowedValues = []): self
    {
        return new self($productType, $required, $optional, $allowedValues, source: self::DECLARED_BY_THE_OWNER);
    }

    /**
     * The required fields a payload does not fill. A value that is there but empty counts as missing: a channel that insists on a field does not mean an empty one.
     *
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    public function missingFrom(array $payload): array
    {
        $missing = [];
        foreach ($this->required as $field) {
            $value = $payload[$field] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * Fields whose value is not one the channel said it accepts.
     *
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    public function refusedValuesIn(array $payload): array
    {
        $refused = [];
        foreach ($this->allowedValues as $field => $allowed) {
            $value = $payload[$field] ?? null;
            if ($value !== null && $value !== '' && $allowed !== [] && !in_array((string) $value, $allowed, true)) {
                $refused[] = $field;
            }
        }

        return $refused;
    }

    /** @return array<string, mixed> what is stored on the draft, so that a later reader knows what was checked and when */
    public function toArray(): array
    {
        return [
            'product_type' => $this->productType, 'required' => $this->required, 'optional' => $this->optional,
            'allowed_values' => $this->allowedValues, 'unknown' => $this->unknown, 'source' => $this->source,
        ];
    }
}
