<?php

namespace App\Services\Fulfillment\Support;

/**
 * Removes what identifies a person from provider data that is about to be kept in a log table or leave the application as
 * evidence: names, phone numbers, e-mail addresses, street addresses, coordinates, national ids, free-text notes.
 *
 * SecretRedactor handles credentials; this handles people. The two are applied together wherever a provider request or response is
 * stored. Keys decide first (a value under `receiverPhone` is personal whatever it looks like, and so is everything nested under a
 * `receiver` or an `address`); values decide the rest (an e-mail address or a phone number found under an unexpected key is masked
 * too). Opaque identifiers, SKUs, barcodes, tracking numbers and status words are kept: they are what a person debugging an order
 * or deciding a field map needs, and they identify a parcel, not a person.
 *
 * `strict` is for responses that are mostly about people (order lists, order details, package status): there only an allow-list
 * survives. A string is kept if it is a short code word, or a short status word under a status-like key (any language); a number
 * is kept unless it is long enough to be a phone number or a national id; and keys that name a person, a courier, a user or a
 * place are hidden whatever the value.
 *
 * It cannot recognise a name or a street address that sits under a key it has no reason to suspect, in free text. What it
 * promises is: contact details and credentials are removed by pattern everywhere, and names and addresses under the keys listed
 * here and (in strict mode) everything that is not on the allow-list.
 */
final class PiiRedactor
{
    public const MASK = '«redacted»';

    /** Free text is cut to this many bytes before it is scanned: the scan stays fast, and nothing beyond it is ever kept. */
    private const MAX_TEXT_BYTES = 20000;

    /** Fragments (of a key with punctuation removed, lower-cased) that mark a value as personal wherever they occur. */
    private const PERSONAL_FRAGMENTS = [
        'phone', 'mobile', 'whatsapp', 'telephone', 'email', 'address', 'street', 'receiver', 'recipient', 'consignee', 'customer',
        'contactperson', 'contactname', 'contactno', 'firstname', 'lastname', 'fullname', 'buyer', 'latitude', 'longitude', 'zipcode',
        'postal', 'postcode', 'landmark', 'neighborhood', 'neighbourhood', 'district', 'national', 'iqama', 'identity', 'passport',
        'iban', 'drivername', 'sendername', 'note', 'comment', 'remark',
    ];

    /** Keys that are personal when they are exactly this, in any mode: too short to be a fragment. */
    private const PERSONAL_EXACT = ['tel', 'cell', 'lat', 'lng', 'lon', 'long', 'ip', 'ipaddress', 'geo', 'coords', 'coordinates', 'dob'];

    /** Keys that are personal only in strict mode: too common in catalogues to hide there. */
    private const STRICT_KEYS = [
        'name', 'title', 'contact', 'description', 'text', 'message', 'info', 'details', 'user', 'courier', 'driver', 'rider', 'agent',
        'sender', 'createdby', 'updatedby', 'username', 'owner', 'author', 'operator', 'person', 'client',
    ];

    /** A key ending like this holds a code, not a person: kept (unless it also names a phone, an e-mail, an address or an identity). */
    private const CODE_SUFFIXES = ['id', 'ids', 'sku', 'barcode', 'code', 'ean', 'upc', 'reference', 'ref', 'invoicenumber', 'tracking', 'trackingnumber', 'awb', 'status', 'type'];

    private const STILL_PERSONAL_IF_CODE_LIKE = ['phone', 'mobile', 'email', 'address', 'national', 'iqama', 'identity', 'passport', 'iban'];

    /** Local part, domain and top-level domain are bounded, so no input can make the scan slow. */
    private const EMAIL = '/[\p{L}\p{N}._%+\-]{1,254}@[\p{L}\p{N}.\-]{1,255}\.\p{L}{2,24}/u';

    /** A run of digits with the separators people put in phone numbers; whether it IS a phone number is decided by PhoneLike. */
    private const PHONE_CANDIDATE = '/(?<![A-Za-z0-9])\+?[0-9][0-9 \-.\/()\x{A0}]{7,24}[0-9](?![A-Za-z0-9])/u';

    private const CODE_WORD = '/^[A-Za-z0-9_.:\-]{1,40}$/';

    private const STATUS_WORD = '/^[\p{L}\p{N} _.:\-]{1,40}$/u';

    /** Arabic-Indic, Extended Arabic-Indic and full-width digits, folded to the ASCII digits the patterns look for. */
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4', '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
    ];

    public static function redact(mixed $data, bool $strict = false): mixed
    {
        return self::walk($data, '', $strict, false);
    }

    /**
     * One value, as it would be after redact(): for code that walks a structure itself and needs the same decision.
     *
     * @param bool $insidePersonal true when a parent key was personal (`receiver`, `address`…): then only a code survives
     */
    public static function redactScalar(mixed $value, string $key, bool $strict, bool $insidePersonal = false): mixed
    {
        if (!is_scalar($value) || is_bool($value)) {
            return $value;
        }

        $code = self::looksLikeACode($key);
        if (self::isPersonalKey($key, $strict) || ($insidePersonal && !$code)) {
            return self::MASK;
        }
        if (!is_string($value)) {
            return $strict && !$code && self::looksLikePersonalNumber($key, $value) ? self::MASK : $value;
        }

        return self::redactStringValue($value, $key, $strict, $code);
    }

    /** True when a value under this key is personal. */
    public static function isPersonalKey(string $key, bool $strict): bool
    {
        $normal = self::normalise($key);
        if ($normal === '') {
            return false;
        }
        if (in_array($normal, self::PERSONAL_EXACT, true)) {
            return true;
        }
        if (self::looksLikeACode($key) && !self::mentions($normal, self::STILL_PERSONAL_IF_CODE_LIKE)) {
            return false;
        }

        return self::mentions($normal, self::PERSONAL_FRAGMENTS) || ($strict && in_array($normal, self::STRICT_KEYS, true));
    }

    /** A key that is itself a phone number or an e-mail address (an object keyed by the receiver's number) is not kept as a key. */
    public static function maskedKeyName(string $key): string
    {
        return self::redactText($key) === $key ? $key : self::MASK;
    }

    /** Masks e-mail addresses and phone numbers inside free text (an error message that echoes what was sent). */
    public static function redactText(string $text, bool $phones = true): string
    {
        $original = self::bounded(mb_scrub($text));
        $folded = strtr($original, self::DIGITS);

        $masked = preg_replace(self::EMAIL, self::MASK, $folded);
        if ($masked !== null && $phones) {
            $masked = preg_replace_callback(self::PHONE_CANDIDATE, static fn (array $match): string => self::isPhoneNumber($match[0]) ? self::MASK : $match[0], $masked);
        }

        // Text that held nothing to mask comes back as it was (not with its digits folded); a pattern that could not run must not let it through.
        return $masked === null ? self::MASK : ($masked === $folded ? $original : $masked);
    }

    /**
     * Free text that is about to be written to a log or stored as an error, with credentials and contact details removed, control
     * characters dropped, and short. A provider's error text (and the exception a failed request throws) may echo back what was
     * sent to it: a phone number, an e-mail address. It does not remove a name or a street address it has no key for.
     */
    public static function scrub(string $text, int $maxBytes = 300): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', self::redactText(SecretRedactor::redactString($text))) ?? self::MASK;

        return (string) SecretRedactor::truncate($text, $maxBytes);
    }

    private static function walk(mixed $value, string $key, bool $strict, bool $insidePersonal): mixed
    {
        if (!is_array($value)) {
            return self::redactScalar($value, $key, $strict, $insidePersonal);
        }

        if (array_is_list($value) && $value !== [] && self::allScalar($value) && self::isPersonalKey($key, $strict)) {
            return array_fill(0, count($value), self::MASK);
        }

        $personal = $insidePersonal || self::isPersonalKey($key, $strict);
        $result = [];
        foreach ($value as $childKey => $child) {
            $name = is_string($childKey) ? self::maskedKeyName($childKey) : $childKey;
            $result[$name] = self::walk($child, (string) $childKey, $strict, $personal);
        }

        return $result;
    }

    private static function redactStringValue(string $value, string $key, bool $strict, bool $code): string
    {
        $masked = self::redactText($value, phones: !$code);
        if (!$strict || $masked === '') {
            return $masked;
        }

        // Anything that had a contact detail in it is not a code word, and neither is an address.
        $keep = $masked === $value && filter_var($masked, FILTER_VALIDATE_IP) === false
            && (preg_match(self::CODE_WORD, $masked) === 1 || self::isStatusWord($key, $masked));

        return $keep ? $masked : self::MASK;
    }

    /** A short word under a status-like key, in any language: it is the vocabulary a status map is made from, and what strict mode keeps. */
    public static function isStatusWord(string $key, string $value): bool
    {
        $normal = self::normalise($key);
        if (!str_contains($normal, 'status') && !str_ends_with($normal, 'state') && !str_ends_with($normal, 'stage')) {
            return false;
        }

        return preg_match(self::STATUS_WORD, $value) === 1 && preg_match('/\d{6,}/', $value) !== 1;
    }

    /** A number long enough to be a phone number or a national id is hidden in strict mode, unless it is a timestamp. */
    private static function looksLikePersonalNumber(string $key, int|float $value): bool
    {
        $digits = strlen(preg_replace('/\D/', '', (string) floor(abs($value))) ?? '');
        if ($digits < 9) {
            return false;
        }

        $normal = self::normalise($key);

        return !str_contains($normal, 'date') && !str_contains($normal, 'time') && preg_match('/(created|updated|deleted|delivered|shipped|picked|paid|cancell?ed)at$/', $normal) !== 1;
    }

    /** Nine to fifteen digits, written however people write them, and not an ISO date: a telephone number. */
    private static function isPhoneNumber(string $candidate): bool
    {
        if (preg_match('/^\d{4}[-\/.]\d{2}[-\/.]\d{2}/', $candidate) === 1) {
            return false;
        }

        $digits = strlen(preg_replace('/\D/', '', $candidate) ?? '');

        return $digits >= 9 && $digits <= 15;
    }

    private static function bounded(string $text): string
    {
        return strlen($text) > self::MAX_TEXT_BYTES ? mb_strcut($text, 0, self::MAX_TEXT_BYTES) . '…' : $text;
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
