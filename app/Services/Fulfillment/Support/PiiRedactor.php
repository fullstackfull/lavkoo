<?php

namespace App\Services\Fulfillment\Support;

/**
 * Removes what identifies a person from provider data that is about to be kept in a log table or leave the application as
 * evidence: names, phone numbers, e-mail addresses, street addresses, coordinates, free-text notes.
 *
 * SecretRedactor handles credentials; this handles people. The two are applied together wherever a provider request or response is
 * stored. Keys decide first (a value under `receiverPhone` is personal whatever it looks like); values decide the rest (an
 * e-mail address or a phone number found under an unexpected key is masked too). Opaque identifiers, SKUs, barcodes, tracking
 * numbers and status words are kept: they are what a person debugging an order or deciding a field map needs, and they identify
 * a parcel, not a person.
 *
 * `strict` is for responses that are mostly about people (order lists): there, any string that is not a short code word is
 * hidden, because the key that would have flagged it may be one nobody has seen yet.
 */
final class PiiRedactor
{
    public const MASK = '«redacted»';

    /** Fragments (of a key with punctuation removed, lower-cased) that mark a value as personal wherever they occur. */
    private const PERSONAL_FRAGMENTS = [
        'phone', 'mobile', 'whatsapp', 'telephone', 'email', 'address', 'street', 'receiver', 'recipient', 'consignee', 'customer',
        'contactperson', 'firstname', 'lastname', 'fullname', 'buyer', 'latitude', 'longitude', 'zipcode', 'postal', 'postcode',
        'landmark', 'neighborhood', 'neighbourhood', 'district', 'national', 'iqama', 'identity', 'drivername', 'sendername',
        'note', 'comment', 'remark',
    ];

    /** Keys that are personal only in strict mode: too common in catalogues to hide there. */
    private const STRICT_KEYS = ['name', 'title', 'contact', 'description', 'text', 'message', 'info', 'details'];

    /** A key ending like this holds a code, not a person: kept (unless it also names a phone, an e-mail or an address). */
    private const CODE_SUFFIXES = ['id', 'ids', 'sku', 'barcode', 'code', 'ean', 'upc', 'reference', 'ref', 'invoicenumber', 'tracking', 'trackingnumber', 'awb', 'status', 'type'];

    private const STILL_PERSONAL_IF_CODE_LIKE = ['phone', 'mobile', 'email', 'address'];

    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    private const PHONE = '/(?<![A-Za-z0-9])(?:\+|00)?[0-9][0-9 \-]{7,15}[0-9](?![A-Za-z0-9])/';

    private const CODE_WORD = '/^[A-Za-z0-9_.:\-]{1,40}$/';

    public static function redact(mixed $data, bool $strict = false): mixed
    {
        return self::walk($data, '', $strict);
    }

    /** Masks e-mail addresses and phone numbers inside free text (an error message that echoes what was sent). */
    public static function redactText(string $text): string
    {
        $text = preg_replace(self::EMAIL, self::MASK, $text) ?? $text;

        return preg_replace(self::PHONE, self::MASK, $text) ?? $text;
    }

    /**
     * Free text that is about to be written to a log or stored as an error, with credentials and contact details removed, and short.
     * A provider's error text (and the exception a failed request throws) may echo back what was sent to it: a name, a phone number.
     */
    public static function scrub(string $text, int $maxBytes = 300): string
    {
        return (string) SecretRedactor::truncate(self::redactText(SecretRedactor::redactString($text)), $maxBytes);
    }

    private static function walk(mixed $value, string $key, bool $strict): mixed
    {
        if (is_array($value)) {
            if (self::isList($value) && $value !== [] && self::allScalar($value) && self::isPersonalKey($key, $strict)) {
                return array_fill(0, count($value), self::MASK);
            }

            $result = [];
            foreach ($value as $childKey => $child) {
                $result[$childKey] = self::walk($child, (string) $childKey, $strict);
            }

            return $result;
        }

        if (!is_scalar($value) || is_bool($value)) {
            return $value;
        }
        if (self::isPersonalKey($key, $strict)) {
            return self::MASK;
        }
        if (!is_string($value)) {
            return $value;
        }

        return self::redactStringValue($value, $key, $strict);
    }

    private static function redactStringValue(string $value, string $key, bool $strict): string
    {
        $masked = preg_replace(self::EMAIL, self::MASK, $value) ?? $value;
        if (!self::looksLikeACode($key)) {
            $masked = preg_replace(self::PHONE, self::MASK, $masked) ?? $masked;
        }

        return $strict && $masked !== self::MASK && preg_match(self::CODE_WORD, $masked) !== 1 && $masked !== '' ? self::MASK : $masked;
    }

    private static function isPersonalKey(string $key, bool $strict): bool
    {
        $normal = self::normalise($key);
        if ($normal === '') {
            return false;
        }
        if (self::looksLikeACode($key) && !self::mentions($normal, self::STILL_PERSONAL_IF_CODE_LIKE)) {
            return false;
        }

        return self::mentions($normal, self::PERSONAL_FRAGMENTS) || ($strict && in_array($normal, self::STRICT_KEYS, true));
    }

    private static function looksLikeACode(string $key): bool
    {
        $normal = self::normalise($key);
        foreach (self::CODE_SUFFIXES as $suffix) {
            if ($normal === $suffix || str_ends_with($normal, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $fragments */
    private static function mentions(string $normal, array $fragments): bool
    {
        foreach ($fragments as $fragment) {
            if (str_contains($normal, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private static function normalise(string $key): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $key));
    }

    /** @param array<array-key, mixed> $value */
    private static function isList(array $value): bool
    {
        return array_is_list($value);
    }

    /** @param array<array-key, mixed> $value */
    private static function allScalar(array $value): bool
    {
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                return false;
            }
        }

        return true;
    }
}
