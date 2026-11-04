<?php

namespace App\Services\Monitoring\Support;

/**
 * The one place monitoring data is stripped of things it must never keep.
 *
 * Monitoring reads live production traffic — request payloads, headers, exception messages, SQL,
 * outbound API calls. Every one of those routinely carries a password, a token or a card number,
 * and once written to a telemetry table it is a breach waiting to be exported. So nothing reaches
 * storage without passing through here.
 *
 * The built-in list cannot be switched off from configuration; `monitoring.privacy.extra_redacted_keys`
 * only ever adds to it. Matching is on the KEY, case-insensitively and by substring, because the
 * field that leaks is never named exactly what you expected — `password_confirmation`,
 * `stripe_secret_key`, `HTTP_AUTHORIZATION`, `card_cvv` all have to be caught by the same rule.
 */
class Redactor
{
    public const MASK = '[redacted]';

    /**
     * Key fragments that mean "this value never gets stored".
     *
     * @var array<int, string>
     */
    private const SECRET_KEY_FRAGMENTS = [
        'password', 'passwd', 'secret', 'token', 'authorization', 'auth_key', 'api_key', 'apikey',
        'private_key', 'app_key', 'access_key', 'refresh', 'session', 'cookie', 'csrf', '_token',
        'otp', 'pin', 'cvv', 'cvc', 'card_number', 'cardnumber', 'card_no', 'pan', 'iban',
        'credential', 'signature', 'client_secret', 'webhook_secret', 'salt', 'hash',
    ];

    /**
     * Header names that are dropped wholesale rather than kept with a masked value, because even
     * their presence and length are not worth recording.
     *
     * @var array<int, string>
     */
    private const DROP_HEADERS = ['authorization', 'cookie', 'set-cookie', 'proxy-authorization', 'x-api-key', 'x-csrf-token'];

    public function __construct(private readonly array $extraKeys = [])
    {
    }

    public static function make(): self
    {
        return new self((array) config('monitoring.privacy.extra_redacted_keys', []));
    }

    /**
     * Redact a structure of request/response data, recursively.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public function array(array $data, int $depth = 0): array
    {
        if ($depth > 6) {
            return ['__truncated__' => true];
        }

        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSecretKey($key)) {
                $clean[$key] = self::MASK;
                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) => $this->array($value, $depth + 1),
                is_string($value) => $this->text($value),
                is_scalar($value), is_null($value) => $value,
                default => '[' . get_debug_type($value) . ']',
            };
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public function headers(array $headers): array
    {
        $clean = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            if (in_array($lower, self::DROP_HEADERS, true)) {
                continue;
            }
            $clean[$lower] = $this->isSecretKey($lower)
                ? self::MASK
                : $this->text(is_array($value) ? implode(', ', $value) : (string) $value);
        }

        return $clean;
    }

    /**
     * Scrub free text — an exception message, a log line, a URL with a query string.
     *
     * Beyond key/value pairs this also catches the shapes that are secrets wherever they appear:
     * bearer tokens and long card-like digit runs.
     */
    public function text(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // key=secret, "key": "secret", and header-style `Key: secret` in one rule.
        //
        // Two details that are easy to get wrong and both leak:
        //  - the key may be quoted (`"access_token":`), so the closing quote is consumed between
        //    the key and the separator;
        //  - the whole match INCLUDING the value is replaced, so a scheme word like `Bearer` is
        //    swallowed with the token rather than masked in its place, leaving the token visible.
        $keys = implode('|', array_map('preg_quote', $this->allSecretFragments()));
        $value = (string) preg_replace(
            '/([A-Za-z0-9_\-\.]*(?:' . $keys . ')[A-Za-z0-9_\-\.]*)("?\s*[:=]\s*"?)(?:(?:bearer|basic|token)\s+)?[^",;&}\]\n]{3,}?(?="|[,;&}\]\n]|\s{2,}|$)/i',
            '$1$2' . self::MASK,
            $value,
        );

        // A scheme-prefixed credential that was not introduced by a recognisable key name.
        $value = (string) preg_replace('/\b(bearer|basic)\s+[A-Za-z0-9._\-\/+=]{6,}/i', '$1 ' . self::MASK, $value);

        // A 13-19 digit run is a payment card, whatever it is labelled. Keep the last four so a
        // support conversation is still possible. The trailing separator is looked at, not eaten,
        // so the surrounding sentence survives intact.
        $value = (string) preg_replace_callback(
            '/\b\d(?:[ \-]?\d){12,18}\b/',
            function (array $match): string {
                $digits = preg_replace('/\D/', '', $match[0]);

                return strlen((string) $digits) >= 13
                    ? '[card ****' . substr((string) $digits, -4) . ']'
                    : $match[0];
            },
            $value,
        );

        return $value;
    }

    /**
     * A URL safe to store: the path is kept, the query string keys are kept, secret values are not.
     */
    public function url(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $this->text($url);
        }

        $rebuilt = ($parts['scheme'] ?? '') !== '' ? $parts['scheme'] . '://' : '';
        $rebuilt .= $parts['host'] ?? '';
        $rebuilt .= isset($parts['port']) ? ':' . $parts['port'] : '';
        $rebuilt .= $parts['path'] ?? '';

        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            $safe = [];
            foreach ($this->array($query) as $key => $value) {
                $safe[] = $key . '=' . (is_scalar($value) ? $value : '[…]');
            }
            $rebuilt .= '?' . implode('&', $safe);
        }

        return $rebuilt;
    }

    /**
     * An IP recorded per the privacy setting: masked to its network by default, so traffic can
     * still be grouped and abused sources still spotted, without keeping a personal identifier.
     */
    public function ip(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        if (!config('monitoring.privacy.mask_ip', true)) {
            return $ip;
        }

        return self::networkOf($ip);
    }

    /**
     * The network an address belongs to: its last octet for IPv4, its last 64 bits for IPv6.
     *
     * Written against the address's BYTES rather than its text, because IPv6 text is not one
     * address per string. The previous version split on ':' and kept the first four groups, which
     * works only for a fully expanded address — and almost no IPv6 address is written that way.
     * Given 2001:db8::1 it produced "2001:db8::1::", an invalid address that still carried the
     * interface identifier the mask exists to remove; given ::1 it produced "::1::". Worse for
     * anything that groups by the result: 2001:db8::1 and 2001:0db8:0000:0000:0000:0000:0000:0001
     * are the same address and used to mask to two different strings, so one visitor counted twice.
     *
     * inet_pton normalises both to the same sixteen bytes, and inet_ntop writes the masked result
     * back in the one canonical form.
     */
    public static function networkOf(string $ip): ?string
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        $keep = strlen($packed) === 4 ? 3 : 8;
        $masked = @inet_ntop(substr($packed, 0, $keep) . str_repeat("\0", strlen($packed) - $keep));

        return $masked === false ? null : $masked;
    }

    /**
     * SQL with its literals removed — the shape of the query, never the customer's data in it.
     *
     * This doubles as the slow-query fingerprint: two executions of the same statement with
     * different parameters normalise to the same string, which is what makes "this query ran
     * 40,000 times" answerable at all.
     */
    public function sql(string $sql): string
    {
        $sql = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $sql) ?? $sql;
        $sql = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\b\d+\.\d+\b|\b\d+\b/', '?', $sql) ?? $sql;
        // IN (?, ?, ?, ...) of any length is the same query shape.
        $sql = preg_replace('/\bIN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN (?)', $sql) ?? $sql;
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;

        return trim($sql);
    }

    private function isSecretKey(string $key): bool
    {
        // The same field arrives spelled three ways depending on where it came from —
        // `api_key` in a form, `X-Api-Key` in a header, `api.key` in config — so separators are
        // flattened before matching rather than hoping the right spelling is on the list.
        $lower = str_replace([' ', '-', '.'], '_', strtolower($key));
        foreach (self::SECRET_KEY_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }
        foreach ($this->extraKeys as $extra) {
            if (is_string($extra) && $extra !== '' && str_contains($lower, str_replace([' ', '-', '.'], '_', strtolower($extra)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every fragment in every separator spelling, for the free-text regex.
     *
     * @return array<int, string>
     */
    private function allSecretFragments(): array
    {
        $fragments = array_merge(
            self::SECRET_KEY_FRAGMENTS,
            array_map('strtolower', array_filter($this->extraKeys, 'is_string')),
        );

        $variants = [];
        foreach ($fragments as $fragment) {
            $variants[] = $fragment;
            if (str_contains($fragment, '_')) {
                $variants[] = str_replace('_', '-', $fragment);
                $variants[] = str_replace('_', '', $fragment);
            }
        }

        // Longest first, so `card_number` wins over `card` and the whole name is kept in the match.
        $variants = array_values(array_unique($variants));
        usort($variants, static fn ($a, $b) => strlen($b) <=> strlen($a));

        return $variants;
    }
}
