<?php

namespace App\Services\SalesChannels\Support;

use App\Enums\SalesChannels\IntegrationCapability;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelApiLog;
use App\Services\Fulfillment\Support\PiiRedactor;
use App\Services\Fulfillment\Support\SecretRedactor;
use App\Services\SalesChannels\Data\ChannelResponse;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * The only way this module reaches a sales channel.
 *
 * Before ANYTHING leaves the host it checks, in this order, that the call is one we know (registered, with an allowed method), that the global switch
 * is on, that the account (as it is stored at that moment, not as the run found it) is switched on and holds a capability the call belongs to, and, for a call that changes something at the channel: if the
 * account is in dry run the call is logged and nothing is sent; otherwise the environment must allow writes, the account must allow writes and a
 * person must have approved this kind of write. Each refusal is a ChannelUnavailableException with a stable reason.
 *
 * Then it keeps to the channel's rate limit, sends, retries a call that is safe to repeat (reads, and writes that set an absolute value) on a
 * 429, a 5xx or a dropped connection with exponential back-off (the channel's own Retry-After when it sends one), never retries any other write,
 * classifies the outcome by what it proves about whether the channel acted, and leaves a redacted row in `sales_channel_api_logs`.
 */
class ChannelHttp
{
    /** cURL errors that prove the request never reached the channel. */
    private const NOT_SENT_CURL_ERRORS = [3, 5, 6, 7, 35, 51, 53, 54, 58, 59, 60, 77, 83];

    public function __construct(private readonly ChannelRateLimiter $limiter)
    {
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $json
     * @param array<string, mixed> $query
     * @param array<string, string>|null $form an application/x-www-form-urlencoded body
     * @param IntegrationCapability|null $capability the capability the caller is acting under (defaults to the first one the account holds)
     * @param string|null $scope for a write: which approval it relies on
     * @throws ChannelUnavailableException
     */
    public function send(
        SalesChannelAccount $account,
        string $operation,
        string $method,
        string $url,
        array $headers = [],
        ?array $json = null,
        array $query = [],
        ?array $form = null,
        ?IntegrationCapability $capability = null,
        ?string $scope = null,
    ): ChannelResponse {
        $method = strtoupper($method);
        $correlationId = (string) Str::uuid();
        $dryRun = $this->assertAllowed($account, $operation, $method, $capability, $scope, $query);

        if ($dryRun) {
            $response = ChannelResponse::dryRun($correlationId);
            $this->log($account, $operation, $method, $url, $headers, $json ?? $form, $query, $response);

            return $response;
        }

        [$max, $perSeconds, $bucket] = $this->rateLimitFor($account, $operation);
        $retries = ChannelOperations::isRetrySafe($operation) ? (int) config('sales_channels.http.retries', 3) : 0;

        $attempt = 0;
        while (true) {
            $attempt++;
            $this->limiter->acquire($account->id . ':' . $bucket, $max, $perSeconds);
            $response = $this->attempt($method, $url, $headers, $json, $query, $form, $correlationId);

            $retryable = in_array($response->kind, [ChannelResponse::RATE_LIMITED, ChannelResponse::SERVER_ERROR, ChannelResponse::NOT_SENT, ChannelResponse::AMBIGUOUS], true);
            if (!$retryable || $attempt > $retries) {
                break;
            }
            Sleep::for($this->backoffMilliseconds($attempt, $response))->milliseconds();
        }

        $response = new ChannelResponse(
            $response->kind, $response->status, $response->json, $response->body, $response->error, $response->durationMs, $correlationId, $response->headers, $attempt,
        );
        $this->log($account, $operation, $method, $url, $headers, $json ?? $form, $query, $response);

        return $response;
    }

    /**
     * Whether a call may be made, and whether it is only to be rehearsed.
     *
     * @return bool true when the account is in dry run and the call would change something: it is to be logged, not sent
     * @throws ChannelUnavailableException
     */
    public function assertAllowed(SalesChannelAccount $account, string $operation, string $method, ?IntegrationCapability $capability = null, ?string $scope = null, array $query = []): bool
    {
        if (!ChannelOperations::isRegistered($operation) || !ChannelOperations::methodAllowed($operation, $method)) {
            throw new ChannelUnavailableException(ChannelUnavailableException::UNREGISTERED_OPERATION, "'{$operation}' with {$method} is not a registered channel call; nothing was sent.");
        }
        foreach (ChannelOperations::requiredQuery($operation) as $name => $value) {
            if (($query[$name] ?? null) !== $value) {
                throw new ChannelUnavailableException(ChannelUnavailableException::UNREGISTERED_OPERATION, "'{$operation}' is only allowed with {$name}={$value}; nothing was sent.");
            }
        }
        if (!config('sales_channels.enabled', false)) {
            throw new ChannelUnavailableException(ChannelUnavailableException::GLOBAL_OFF, 'Sales channel integrations are switched off (SALES_CHANNELS_ENABLED); nothing was sent.');
        }
        // The caller may have been holding this object for a whole run: what a person switched off since then must stop the very next call.
        if (!$account->refreshGates()) {
            throw new ChannelUnavailableException(ChannelUnavailableException::ACCOUNT_OFF, 'This channel account no longer exists; nothing was sent.');
        }
        if (!$account->enabled) {
            throw new ChannelUnavailableException(ChannelUnavailableException::ACCOUNT_OFF, 'This channel account is disabled; nothing was sent.');
        }

        $kind = ChannelOperations::kindOf($operation, $method);
        $held = array_map(static fn (string $value): ?IntegrationCapability => IntegrationCapability::tryFrom($value), (array) ($account->capabilities ?? []));
        $held = array_values(array_filter($held));
        $allowed = ChannelOperations::capabilities($operation);
        $usable = $kind === ChannelOperations::AUTH ? $held : array_values(array_filter($held, static fn (IntegrationCapability $held): bool => in_array($held, $allowed, true)));
        if ($usable === [] || ($capability !== null && !in_array($capability, $usable, true))) {
            throw new ChannelUnavailableException(ChannelUnavailableException::CAPABILITY_OFF, "'{$operation}' needs one of: " . implode(', ', array_map(static fn (IntegrationCapability $item): string => $item->value, $allowed)) . ' switched on for this account; nothing was sent.');
        }

        if (ChannelOperations::readsBuyerData($operation)) {
            $this->assertBuyerDataMayBeRead($account, $operation);
        }

        if ($kind !== ChannelOperations::WRITE) {
            return false;
        }

        // A rehearsal changes nothing, so it needs no environment switch and no approval: it is how a person sees the exact request before approving anything.
        if ($account->dry_run) {
            return true;
        }
        if (!config('sales_channels.external_writes_enabled', false)) {
            throw new ChannelUnavailableException(ChannelUnavailableException::WRITES_OFF_ENVIRONMENT, 'External writes are off on this host (SALES_CHANNELS_EXTERNAL_WRITES_ENABLED); this integration is read-only and nothing was sent.');
        }
        if (!$account->write_enabled) {
            throw new ChannelUnavailableException(ChannelUnavailableException::WRITES_OFF_ACCOUNT, 'Writing is off for this account; nothing was sent.');
        }
        $scopes = ChannelOperations::scopes($operation);
        $scope ??= $scopes[0] ?? null;
        if ($scope === null || !in_array($scope, $scopes, true) || empty(($account->capability_approvals ?? [])[$scope])) {
            throw new ChannelUnavailableException(ChannelUnavailableException::NOT_APPROVED, "No one has approved this kind of write ({$scope}) for this account; nothing was sent.");
        }

        return false;
    }

    /** @throws ChannelUnavailableException */
    private function assertBuyerDataMayBeRead(SalesChannelAccount $account, string $operation): void
    {
        if (!config('sales_channels.buyer_data_transfer_enabled', false)) {
            throw new ChannelUnavailableException(ChannelUnavailableException::BUYER_DATA_OFF, "Reading a buyer's details is switched off on this host (SALES_CHANNELS_BUYER_DATA_TRANSFER_ENABLED); '{$operation}' was not sent.");
        }
        if (empty(($account->capability_approvals ?? [])[ChannelOperations::SCOPE_BUYER_DATA_TRANSFER])) {
            throw new ChannelUnavailableException(ChannelUnavailableException::NOT_APPROVED, "No master admin has approved reading buyer details for this account; '{$operation}' was not sent.");
        }
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $json
     * @param array<string, mixed> $query
     * @param array<string, string>|null $form
     */
    private function attempt(string $method, string $url, array $headers, ?array $json, array $query, ?array $form, string $correlationId): ChannelResponse
    {
        $started = hrtime(true);

        try {
            $pending = Http::withHeaders($headers)
                ->acceptJson()
                ->connectTimeout((int) config('sales_channels.http.connect_timeout', 5))
                ->timeout((int) config('sales_channels.http.timeout', 30))
                ->withoutRedirecting();
            $options = [];
            if ($form !== null) {
                $pending = $pending->asForm();
                $options['form_params'] = $form;
            } elseif ($json !== null) {
                $options['json'] = $json;
            }
            if ($query !== []) {
                $options['query'] = $query;
            }

            return $this->fromResponse($pending->send($method, $url, $options), $correlationId, $this->elapsedMs($started));
        } catch (ConnectionException $exception) {
            return $this->fromConnectionFailure($exception, $correlationId, $this->elapsedMs($started));
        }
    }

    private function fromResponse(Response $response, string $correlationId, int $durationMs): ChannelResponse
    {
        $status = $response->status();
        $kind = match (true) {
            $response->successful() => ChannelResponse::SUCCESS,
            $status === 401 || $status === 403 => ChannelResponse::AUTH_ERROR,
            $status === 429 => ChannelResponse::RATE_LIMITED,
            in_array($status, [408, 409, 425], true) => ChannelResponse::AMBIGUOUS,
            $status >= 500 => ChannelResponse::SERVER_ERROR,
            // Redirects are not followed; a write that ends below 400 without succeeding proves nothing about what happened.
            $status < 400 => ChannelResponse::AMBIGUOUS,
            default => ChannelResponse::REJECTED,
        };
        $decoded = $response->json();
        $headers = [];
        foreach ($response->headers() as $name => $values) {
            $name = strtolower((string) $name);
            // One value per header is enough for everything this module reads EXCEPT `Set-Cookie`, which HTTP allows a server to repeat and which a session is built out of:
            // a channel that sets a session cookie and a CSRF cookie sends two, and keeping only the first would hand back half a session. So that one keeps every value.
            $headers[$name] = $name === 'set-cookie'
                ? array_values(array_map('strval', is_array($values) ? $values : [$values]))
                : (is_array($values) ? (string) ($values[0] ?? '') : (string) $values);
        }

        return new ChannelResponse($kind, $status, is_array($decoded) ? $decoded : null, (string) $response->body(), null, $durationMs, $correlationId, $headers);
    }

    private function fromConnectionFailure(ConnectionException $exception, string $correlationId, int $durationMs): ChannelResponse
    {
        $message = $exception->getMessage();
        $curlError = preg_match('/cURL error (\d+)/', $message, $matches) === 1 ? (int) $matches[1] : null;
        // Error 28 is both "connection timed out" (never sent) and "operation timed out" (sent, no answer). Only the second leaves us unable to tell whether the channel acted.
        $connectPhase = $curlError === 28 && (stripos($message, 'connection timed out') !== false || stripos($message, 'connect timeout') !== false);
        $neverSent = $connectPhase || ($curlError !== null && in_array($curlError, self::NOT_SENT_CURL_ERRORS, true));

        return new ChannelResponse($neverSent ? ChannelResponse::NOT_SENT : ChannelResponse::AMBIGUOUS, null, null, '', SecretRedactor::redactString($message), $durationMs, $correlationId);
    }

    private function backoffMilliseconds(int $attempt, ChannelResponse $response): int
    {
        $cap = (int) config('sales_channels.http.retry_max_seconds', 30) * 1000;
        $told = $response->retryAfterSeconds();
        if ($told !== null) {
            return min($cap, max(0, $told) * 1000);
        }
        $base = (int) config('sales_channels.http.retry_base_milliseconds', 500);

        return min($cap, $base * (2 ** ($attempt - 1)) + random_int(0, 250));
    }

    /** @return array{0: int, 1: int, 2: string} calls allowed, per this many seconds, and the allowance they draw on */
    private function rateLimitFor(SalesChannelAccount $account, string $operation): array
    {
        $limits = (array) config('sales_channels.rate_limits.' . $account->channel, []);
        $own = (array) ($limits['operations'][$operation] ?? $limits['default'] ?? ['max' => 30, 'per_seconds' => 10]);

        return [(int) ($own['max'] ?? 30), (int) ($own['per_seconds'] ?? 10), (string) ($own['bucket'] ?? $operation)];
    }

    private function elapsedMs(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $query
     */
    /** A text cut to fit a column of that many characters, the mark that says it was cut included (the redactor's mark is added after its cut). */
    private function fit(?string $text, int $limit): ?string
    {
        return SecretRedactor::truncate($text, $limit - 16);
    }

    private function log(SalesChannelAccount $account, string $operation, string $method, string $url, array $headers, ?array $body, array $query, ChannelResponse $response): void
    {
        try {
            $maxBytes = (int) config('sales_channels.logs.max_body_bytes', 8192);
            $strict = ChannelOperations::describesPeople($operation);
            $request = PiiRedactor::redact(SecretRedactor::redact(array_filter(['headers' => $headers, 'query' => $query, 'body' => $body], static fn ($value): bool => $value !== [] && $value !== null)), $strict);
            if (strlen((string) json_encode($request)) > $maxBytes * 2) {
                $request = ['_truncated' => SecretRedactor::truncate((string) json_encode($request), $maxBytes)];
            }

            SalesChannelApiLog::create([
                'account_id' => $account->id,
                'channel' => $account->channel,
                'operation' => $operation,
                'correlation_id' => $response->correlationId,
                'method' => $method,
                'url' => $this->fit(SecretRedactor::redactString($url), 500),
                'request_summary' => $request === [] ? null : $request,
                'response_status' => $response->status,
                'response_summary' => SecretRedactor::truncate(
                    json_encode(PiiRedactor::redact(SecretRedactor::redact($response->json ?? PiiRedactor::redactText($response->body)), $strict), JSON_INVALID_UTF8_SUBSTITUTE) ?: '',
                    $maxBytes,
                ),
                'request_id' => $this->fit($response->header('x-amzn-requestid') ?? $response->header('x-request-id'), 120),
                'success' => $response->ok() || $response->isDryRun(),
                'dry_run' => $response->isDryRun(),
                'error_class' => $response->ok() || $response->isDryRun() ? null : $response->kind,
                'error_message' => $response->ok() || $response->isDryRun() ? null : $this->fit(PiiRedactor::redactText($response->message()), 500),
                'duration_ms' => $response->durationMs,
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Sales channel API call could not be logged.', ['operation' => $operation, 'error' => PiiRedactor::scrub($exception->getMessage())]);
        }
    }
}
