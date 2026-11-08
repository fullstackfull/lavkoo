<?php

namespace App\Services\MyFatoorah;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * One answer from MyFatoorah, reduced to the three things a caller has to tell apart.
 *
 *   succeeded  — MyFatoorah answered and said IsSuccess true. The payload can be trusted.
 *   refused    — MyFatoorah answered and said no (bad key, validation error, unknown payment). A
 *                definite answer: retrying it unchanged will say no again.
 *   unknown    — we never got an answer (timeout, DNS, TLS, 5xx after retries). NOT a failure of the
 *                payment. Code that treats this as "not paid" is how a paying customer loses an order,
 *                so `isUnknown()` exists to make the distinction impossible to skip.
 *
 * The constructor is private: a response may only come from a real HTTP exchange or from one of the
 * named local-failure helpers, so an "unknown" can never be fabricated as a success.
 */
final class MyFatoorahResponse
{
    private function __construct(
        public readonly bool $succeeded,
        public readonly bool $transportFailed,
        public readonly int $httpStatus,
        public readonly array $data,
        public readonly ?string $errorCode,
        public readonly string $message,
        public readonly array $validationErrors = [],
    ) {
    }

    public static function fromHttp(Response $response): self
    {
        $json = $response->json();
        $json = is_array($json) ? $json : [];

        // The docs declare IsSuccess as a boolean in the OpenAPI schemas and as the string "true" in the
        // prose response-model page. Both are accepted rather than trusting either.
        $isSuccess = $json['IsSuccess'] ?? null;
        $flag = match (true) {
            is_bool($isSuccess) => $isSuccess,
            is_string($isSuccess) => strtolower($isSuccess) === 'true',
            default => false,
        };

        $data = is_array($json['Data'] ?? null) ? $json['Data'] : [];
        $message = (string) ($json['Message'] ?? '');

        $validation = [];
        foreach ((array) ($json['ValidationErrors'] ?? $json['FieldsErrors'] ?? []) as $item) {
            if (is_array($item)) {
                $validation[] = [
                    'name' => (string) ($item['Name'] ?? ''),
                    'error' => (string) ($item['Error'] ?? ''),
                ];
            }
        }

        // A 2xx with IsSuccess true is the only success. Anything else is a definite refusal unless the
        // transport itself failed, which is handled by transportFailure().
        $succeeded = $response->successful() && $flag;

        return new self(
            succeeded: $succeeded,
            transportFailed: false,
            httpStatus: $response->status(),
            data: $data,
            errorCode: $succeeded ? null : self::classify($response->status(), $message),
            message: Str::limit($message !== '' ? $message : self::statusText($response->status()), 200, ''),
            validationErrors: $validation,
        );
    }

    /** We never reached MyFatoorah, or could not understand it. The payment's state is unknown. */
    public static function transportFailure(string $code, string $message): self
    {
        return new self(
            succeeded: false,
            transportFailed: true,
            httpStatus: 0,
            data: [],
            errorCode: $code,
            message: Str::limit($message, 200, ''),
        );
    }

    /** A call we refused to make (no key, no endpoint, host not allow-listed). Definite, and our fault. */
    public static function localFailure(string $code, string $message): self
    {
        return new self(
            succeeded: false,
            transportFailed: false,
            httpStatus: 0,
            data: [],
            errorCode: $code,
            message: Str::limit($message, 200, ''),
        );
    }

    /**
     * True when the outcome is genuinely unknown and the caller must ask again later rather than decide.
     */
    public function isUnknown(): bool
    {
        return $this->transportFailed;
    }

    /** True when MyFatoorah gave a definite no. Safe to show the customer a failure for. */
    public function isRefusal(): bool
    {
        return !$this->succeeded && !$this->transportFailed;
    }

    /** Read a nested value, e.g. get('Invoice.Status'). */
    public function get(string $path, mixed $default = null): mixed
    {
        $value = $this->data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /** Authentication or permission problem — the key is wrong, revoked, or for another country. */
    public function isAuthProblem(): bool
    {
        return in_array($this->httpStatus, [401, 403], true);
    }

    private static function classify(int $status, string $message): string
    {
        return match (true) {
            $status === 401 || $status === 403 => 'unauthorized',
            $status === 404 => 'not_found',
            $status === 422 || $status === 400 => 'validation',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'provider_error',
            $message !== '' => 'refused',
            default => 'unknown',
        };
    }

    private static function statusText(int $status): string
    {
        return $status > 0
            ? 'MyFatoorah answered with HTTP ' . $status . '.'
            : 'MyFatoorah could not be reached.';
    }
}
