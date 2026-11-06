<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

/** Reads a JSON column the way the integration does, to say whether it can: the content is examined and never reported. */
final class JsonShape
{
    /**
     * @return array{valid: bool, double_encoded: bool, value: mixed} `valid` is false for an empty or malformed value;
     *         a document that was encoded twice (a JSON string holding JSON) is unwrapped once and flagged
     */
    public static function decode(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return ['valid' => false, 'double_encoded' => false, 'value' => null];
        }

        $value = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['valid' => false, 'double_encoded' => false, 'value' => null];
        }

        if (is_string($value)) {
            $inner = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($inner)) {
                return ['valid' => true, 'double_encoded' => true, 'value' => $inner];
            }
        }

        return ['valid' => true, 'double_encoded' => false, 'value' => $value];
    }

    /** A non-empty string value under a key, or null. */
    public static function text(mixed $document, string $key): ?string
    {
        $value = is_array($document) ? ($document[$key] ?? null) : null;

        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }
}
