<?php

namespace App\Services\Fulfillment\Support;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderApiLog;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The only way this integration reaches the network.
 *
 * Responsibilities, in order of importance:
 *  1. Refuse to send anything while integrations are switched off (the global kill switch), and refuse every WRITE
 *     (an operation that creates or changes something at a provider) while external writes are off.
 *  2. Never retry a WRITE. A request that timed out after it was sent may have been accepted; whether it
 *     can be repeated is for the state machine to decide from provider references, not for a retry loop.
 *     Reads may be retried a couple of times.
 *  3. Classify the outcome by what it proves about whether the provider acted (see HttpResult).
 *  4. Leave a redacted audit row in `provider_api_logs` for every call.
 */
class ProviderHttp
{
    /** cURL errors that prove the request never reached the provider. */
    private const NOT_SENT_CURL_ERRORS = [3, 5, 6, 7, 35, 51, 53, 54, 58, 59, 60, 77, 83];

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $json
     * @param array<string, mixed> $query
     */
    public function send(
        ProviderCode $provider,
        string $operation,
        string $method,
        string $url,
        array $headers = [],
        ?array $json = null,
        array $query = [],
        ?int $fulfillmentId = null,
    ): HttpResult {
        if (!config('fulfillment.enabled', false)) {
            throw new ProviderUnavailableException('Fulfillment integrations are disabled (FULFILLMENT_INTEGRATIONS_ENABLED is off); nothing was sent.');
        }

        $method = strtoupper($method);

        // Below every other switch: while external writes are off, nothing that could create or change something at a
        // provider leaves this host. Unknown operations and unexpected methods count as writes (see ExternalOperations).
        if (!config('fulfillment.external_writes_enabled', false) && ExternalOperations::isWrite($operation, $method)) {
            throw new ProviderUnavailableException("External write operations are disabled on this host (FULFILLMENT_EXTERNAL_WRITES_ENABLED is off); '{$operation}' was not sent.");
        }

        $isRead = in_array($method, ['GET', 'HEAD'], true);
        $correlationId = (string) Str::uuid();
        $started = hrtime(true);

        try {
            $pending = Http::withHeaders($headers)
                ->acceptJson()
                ->connectTimeout((int) config('fulfillment.http.connect_timeout', 5))
                ->timeout((int) config('fulfillment.http.timeout', 20))
                ->withoutRedirecting();

            if ($isRead) {
                $pending = $pending->retry(
                    times: 1 + (int) config('fulfillment.http.read_retries', 2),
                    sleepMilliseconds: (int) config('fulfillment.http.read_retry_delay_ms', 400),
                    when: static fn (Throwable $exception): bool => $exception instanceof ConnectionException,
                    throw: false,
                );
            }

            $options = [];
            if ($json !== null) {
                $options['json'] = $json;
            }
            if ($query !== []) {
                $options['query'] = $query;
            }

            $response = $pending->send($method, $url, $options);
            $result = $this->fromResponse($response, $correlationId, $this->elapsedMs($started));
        } catch (ConnectionException $exception) {
            $result = $this->fromConnectionFailure($exception, $correlationId, $this->elapsedMs($started));
        }

        $this->log($provider, $operation, $method, $url, $headers, $json, $query, $fulfillmentId, $result);

        return $result;
    }

    private function fromResponse(Response $response, string $correlationId, int $durationMs): HttpResult
    {
        $status = $response->status();
        $body = (string) $response->body();
        $decoded = $response->json();

        $kind = match (true) {
            $response->successful() => HttpResult::SUCCESS,
            $status === 401 || $status === 403 => HttpResult::AUTH_ERROR,
            $status === 429 => HttpResult::RATE_LIMITED,
            in_array($status, [408, 409, 425], true) => HttpResult::AMBIGUOUS,
            $status >= 500 => HttpResult::SERVER_ERROR,
            default => HttpResult::REJECTED,
        };

        return new HttpResult(
            kind: $kind,
            status: $status,
            json: is_array($decoded) ? $decoded : null,
            body: $body,
            error: null,
            durationMs: $durationMs,
            correlationId: $correlationId,
        );
    }

    private function fromConnectionFailure(ConnectionException $exception, string $correlationId, int $durationMs): HttpResult
    {
        $message = $exception->getMessage();
        $curlError = preg_match('/cURL error (\d+)/', $message, $matches) === 1 ? (int) $matches[1] : null;

        // Error 28 is both "connection timed out" (never sent) and "operation timed out" (sent, no
        // answer). Only the second leaves us unable to tell whether the provider acted.
        $connectPhase = $curlError === 28 && (stripos($message, 'connection timed out') !== false || stripos($message, 'connect timeout') !== false);
        $neverSent = $connectPhase || ($curlError !== null && in_array($curlError, self::NOT_SENT_CURL_ERRORS, true));

        return new HttpResult(
            kind: $neverSent ? HttpResult::NOT_SENT : HttpResult::AMBIGUOUS,
            status: null,
            json: null,
            body: '',
            error: SecretRedactor::redactString($message),
            durationMs: $durationMs,
            correlationId: $correlationId,
        );
    }

    private function elapsedMs(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $json
     * @param array<string, mixed> $query
     */
    private function log(
        ProviderCode $provider,
        string $operation,
        string $method,
        string $url,
        array $headers,
        ?array $json,
        array $query,
        ?int $fulfillmentId,
        HttpResult $result,
    ): void {
        try {
            $maxBytes = (int) config('fulfillment.logs.max_body_bytes', 8192);
            $strict = ExternalOperations::describesPeople($operation);
            $request = PiiRedactor::redact(SecretRedactor::redact(array_filter([
                'headers' => $headers,
                'query' => $query,
                'body' => $json,
            ], static fn ($value): bool => $value !== [] && $value !== null)), $strict);

            if (strlen((string) json_encode($request)) > $maxBytes * 2) {
                $request = ['_truncated' => SecretRedactor::truncate((string) json_encode($request), $maxBytes)];
            }

            ProviderApiLog::create([
                'provider' => $provider->value,
                'operation' => $operation,
                'provider_fulfillment_id' => $fulfillmentId,
                'correlation_id' => $result->correlationId,
                'method' => $method,
                'url' => SecretRedactor::truncate(SecretRedactor::redactString($url), 500),
                'request_summary' => $request === [] ? null : $request,
                'response_status' => $result->status,
                'response_summary' => SecretRedactor::truncate(
                    SecretRedactor::redactString(json_encode(PiiRedactor::redact(SecretRedactor::redact($result->json ?? PiiRedactor::redactText($result->body)), $strict), JSON_INVALID_UTF8_SUBSTITUTE) ?: ''),
                    $maxBytes,
                ),
                'success' => $result->ok(),
                'error_class' => $result->ok() ? null : $result->kind,
                'error_message' => $result->ok() ? null : SecretRedactor::truncate(PiiRedactor::redactText($result->message()), 500),
                'duration_ms' => $result->durationMs,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Provider API call could not be logged.', ['operation' => $operation, 'error' => PiiRedactor::scrub($exception->getMessage())]);
        }
    }
}
