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
 * survives. A string is kept if it is shaped like a code (all capitals, or holding a digit or an underscore, or under a key that
 * names a code) or is a short status word under a key that ends in status, state or stage (any language); a number is kept unless
 * it is long enough to be a phone number or a national id; and keys that name a person, a courier, a user or a place are hidden
 * whatever the value. A lone first name under a key it has no reason to suspect looks like any other word and is hidden only
 * because words that are not shaped like codes are.
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
    private const PERSONAL_EXACT = ['tel', 'cell', 'lat', 'lng', 'lon', 'long', 'ip', 'ipaddress', 'geo', 'coords', 'coordinates', 'dob', 'zip'];

    /** Keys that are personal only in strict mode: too common in catalogues to hide there. */
    private const STRICT_KEYS = [
        'name', 'title', 'contact', 'description', 'text', 'message', 'info', 'details', 'user', 'courier', 'driver', 'rider', 'agent',
        'sender', 'createdby', 'updatedby', 'username', 'owner', 'author', 'operator', 'person', 'client',
    ];

    /** Fragments that mark a value as personal in strict mode only (catalogues have products named after nothing in particular). */
    private const STRICT_FRAGMENTS = [
        'courier', 'driver', 'rider', 'agent', 'deliveryman', 'deliveredto', 'handledby', 'signedby', 'receivedby', 'collectedby', 'captain',
        'surname', 'family', 'given', 'handle', 'supplier',
    ];

    /** A key ending like this holds a code, not a person: kept (unless it also names a phone, an e-mail, an address or an identity). */
    private const CODE_SUFFIXES = ['id', 'ids', 'sku', 'barcode', 'code', 'ean', 'upc', 'reference', 'ref', 'invoicenumber', 'tracking', 'trackingnumber', 'awb', 'status', 'type', 'method', 'mode', 'currency', 'unit', 'kind', 'channel'];

    private const STILL_PERSONAL_IF_CODE_LIKE = ['phone', 'mobile', 'whatsapp', 'telephone', 'email', 'address', 'zip', 'postal', 'postcode', 'national', 'iqama', 'identity', 'passport', 'iban'];

    /** Local part, domain and top-level domain are bounded, so no input can make the scan slow. */
    private const EMAIL = '/[\p{L}\p{N}._%+\-]{1,254}@[\p{L}\p{N}.\-]{1,255}\.\p{L}{2,24}/u';

    /**
     * A run of digits with the separators people put in phone numbers (spaces of every kind, tabs, line breaks, dots, slashes,
     * brackets and every dash); whether it IS a phone number is decided by isPhoneNumber(). The run is long enough to hold three
     * numbers written one after another, and bounded, so no input can make the scan slow.
     */
    private const PHONE_CANDIDATE = '/(?<![A-Za-z0-9])\+?[0-9][0-9\s\p{Z}\p{Pd}\x{2212}.\/()]{7,60}[0-9](?![A-Za-z0-9])/u';

    private const CODE_WORD = '/^[A-Za-z0-9_.:\-]{1,40}$/';

    private const STATUS_WORD = '/^[\p{L}\p{N} _.:\-]{1,30}$/u';

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

        return self::mentions($normal, self::PERSONAL_FRAGMENTS)
            || ($strict && (in_array($normal, self::STRICT_KEYS, true) || self::mentions($normal, self::STRICT_FRAGMENTS) || (preg_match('/name(en|ar)?$/', $normal) === 1 && !self::isStatusKey($normal))));
    }

    /** A key that is itself a phone number or an e-mail address (an object keyed by the receiver's number) is not kept as a key. */
    public static function maskedKeyName(string $key): string
    {
        return self::redactText($key) === $key ? $key : self::MASK;
    }

    /**
     * A request that is shown to the person who has to approve it: names and addresses stay, because they are what is being
     * approved, but a phone number or an e-mail address typed into a note or an address line does not. Codes are left alone.
     */
    public static function maskContactDetails(mixed $data, string $key = ''): mixed
    {
        if (is_array($data)) {
            foreach ($data as $childKey => $child) {
                $data[$childKey] = self::maskContactDetails($child, (string) $childKey);
            }

            return $data;
        }

        return is_string($data) ? self::redactText($data, phones: !self::looksLikeACode($key)) : $data;
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

    /** A word the provider sent that has to fit a column: scrubbed like any provider text, then cut to what fits (no marker is added). */
    public static function scrubToFit(string $text, int $maxChars): string
    {
        return mb_substr(self::scrub($text, $maxChars * 4), 0, $maxChars);
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
        $masked = 0;
        foreach ($value as $childKey => $child) {
            $result[self::keyName($childKey, $masked)] = self::walk($child, (string) $childKey, $strict, $personal);
        }

        return $result;
    }

    /**
     * The key a child is kept under: its own, unless it is itself a phone number or an e-mail address. PHP turns a key such as
     * `966551234567` into an integer, so integers long enough to be a number are looked at too; masked keys stay distinct, so
     * hiding them does not merge their values.
     */
    private static function keyName(int|string $key, int &$maskedSoFar): int|string
    {
        if (is_int($key) && abs($key) < 100_000_000) {
            return $key;
        }
        if (self::maskedKeyName((string) $key) !== self::MASK) {
            return $key;
        }

        return ++$maskedSoFar === 1 ? self::MASK : self::MASK . '#' . $maskedSoFar;
    }

    private static function redactStringValue(string $value, string $key, bool $strict, bool $code): string
    {
        $masked = self::redactText($value, phones: !$code);
        if (!$strict || $masked === '') {
            return $masked;
        }

        // Anything that had a contact detail in it is not a code word, and neither is an address. A plain word is a code only when
        // it is shaped like one or sits under a key that names one: "KSA100", "OUT_FOR_DELIVERY", "SAR", never "Khalid".
        $keep = $masked === $value && filter_var($masked, FILTER_VALIDATE_IP) === false
            && ((preg_match(self::CODE_WORD, $masked) === 1 && ($code || self::isShapedLikeACode($masked))) || self::isStatusWord($key, $masked));

        return $keep ? $masked : self::MASK;
    }

    /** A short word under a key that ends in status, state or stage, in any language: it is the vocabulary a status map is made from, and what strict mode keeps. */
    public static function isStatusWord(string $key, string $value): bool
    {
        if (!self::isStatusKey(self::normalise($key))) {
            return false;
        }

        return preg_match(self::STATUS_WORD, $value) === 1 && substr_count($value, ' ') <= 2 && preg_match('/\d{6,}/', $value) !== 1;
    }

    /** `status`, `deliveryState`, `order_stage`, and the name or label of one (`status_name`): not `status_message` or `statusDescription`, which are free text. */
    private static function isStatusKey(string $normal): bool
    {
        return preg_match('/(status|state|stage)$/', $normal) === 1 || preg_match('/status(name|text|label|word|value)$/', $normal) === 1;
    }

    /** All capitals, or holding a digit or an underscore: how identifiers, enumerations and currencies are written, and names are not. */
    private static function isShapedLikeACode(string $value): bool
    {
        return preg_match('/[a-z]/', $value) !== 1 || preg_match('/[0-9_]/', $value) === 1;
    }

    /** A number long enough to be a phone number or a national id is hidden in strict mode, unless it is a timestamp. */
    private static function looksLikePersonalNumber(string $key, int|float $value): bool
    {
        $digits = strlen(preg_replace('/\D/', '', (string) floor(abs($value))) ?? '');
        if ($digits < 9) {
            return false;
        }

        $normal = self::normalise($key);

        return !str_contains($normal, 'date') && !str_contains($normal, 'time') && preg_match('/(at|ts|eta|etd|expires|expiry|until|since|epoch|when)$/', $normal) !== 1;
    }

    /**
     * Nine digits or more, once the ISO dates in the run are set aside: a telephone number, or several written one after another.
     * There is no upper limit on purpose: two numbers next to each other, or one next to an order number, are more than fifteen digits.
     */
    private static function isPhoneNumber(string $candidate): bool
    {
        $withoutDates = preg_replace('/\d{4}[-\/.]\d{2}[-\/.]\d{2}/', ' ', $candidate) ?? $candidate;

        return strlen(preg_replace('/\D/', '', $withoutDates) ?? '') >= 9;
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
