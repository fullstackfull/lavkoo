<?php

namespace App\Services\Fulfillment;

use App\Services\Fulfillment\Contracts\ProviderIdentity;

/**
 * A carrier that exists because somebody declared it in `config/fulfillment.php`, with no case in `ProviderCode` (H1-11).
 *
 * This is what makes "one adapter class plus one configuration entry" true. Everything a carrier's identity has to answer comes from its entry: the code
 * is the key it is declared under, the label is `label`, and the short code that goes into a fulfilment reference is `reference_code` -- or, where nobody
 * wrote one, the first two letters of the code in capitals, which is what the two shipped carriers' own codes are ('m5azn' gives MZ as surely as the match
 * arm did, and 'torod_point' gives TO rather than TP, which is why a carrier that cares declares one).
 *
 * A code is accepted here only if it is spelled the way a stored code is spelled -- lowercase letters, digits and underscores -- because this value ends
 * up in a URL (the webhook route), in a fulfilment reference and in a `fulfillment_provider` column, and a code with a slash or a space in it would be a
 * different string in each of those places.
 */
final class ConfiguredProvider implements ProviderIdentity
{
    private function __construct(
        private readonly string $code,
        private readonly string $label,
        private readonly string $referenceCode,
    ) {
    }

    /** The carrier declared under this key, or null when nothing is declared there or the key is not a code. */
    public static function fromConfig(string $code): ?self
    {
        if (preg_match('/^[a-z0-9_]+$/', $code) !== 1) {
            return null;
        }

        $entry = config('fulfillment.providers.' . $code);

        if (!is_array($entry)) {
            return null;
        }

        return new self(
            code: $code,
            label: is_string($entry['label'] ?? null) ? $entry['label'] : $code,
            referenceCode: self::shortCodeOf($entry, $code),
        );
    }

    public function code(): string
    {
        return $this->code;
    }

    public function slug(): string
    {
        return str_replace('_', '-', $this->code);
    }

    public function referenceCode(): string
    {
        return $this->referenceCode;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @param array<string, mixed> $entry */
    private static function shortCodeOf(array $entry, string $code): string
    {
        $declared = $entry['reference_code'] ?? null;

        return is_string($declared) && $declared !== '' ? strtoupper($declared) : strtoupper(substr($code, 0, 2));
    }
}
