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
     * @param array<string, mixed>|object|null $json an object where the service insists on a JSON object: an empty PHP array would encode to `[]`, which a gRPC gateway refuses
     * @param array<string, mixed> $query
     * @param array<string, string>|null $form an application/x-www-form-urlencoded body
     * @param array<string, string>|null $multipart a multipart/form-data body of PLAIN FIELDS ONLY. Some channels document
     *                                              endpoints that take no other encoding (Trendyol's return rejection, its
     *                                              invoice file). **No file bytes pass through here**: a document is never put
     *                                              in a field a logger would then have to be trusted to leave out.
     * @param IntegrationCapability|null $capability the capability the caller is acting under (defaults to the first one the account holds)
     * @param string|null $scope for a write: which approval it relies on
     * @param string|null $rawBodyForLog a copy of $rawBody with its secrets masked by the caller that built it, for the audit log.
     *                                    Without it the log records only the body's SIZE: this layer cannot parse a raw body, so it
     *                                    cannot find a positional credential inside one, and guessing would write it down.
     * @param string|null $rawBody a body that is already encoded, for a protocol that is not JSON or a form -- Odoo's XML-RPC is the one caller.
     *                             With it the caller's own `Content-Type` and `Accept` headers stand: nothing here adds `Accept: application/json` over them.
     * @throws ChannelUnavailableException
     */
    public function send(
        SalesChannelAccount $account,
        string $operation,
        string $method,
        string $url,
        array $headers = [],
        array|object|null $json = null,
        array $query = [],
        ?array $form = null,
        ?IntegrationCapability $capability = null,
        ?string $scope = null,
        ?string $rawBody = null,
        ?array $multipart = null,
        ?string $rawBodyForLog = null,
    ): ChannelResponse {
        $method = strtoupper($method);
        $correlationId = (string) Str::uuid();
        $dryRun = $this->assertAllowed($account, $operation, $method, $capability, $scope, $query);

        if ($dryRun) {
            $response = ChannelResponse::dryRun($correlationId);
            $this->log($account, $operation, $method, $url, $headers, $this->loggableBody($json, $form, $rawBody, $multipart, $rawBodyForLog), $query, $response);

            return $response;
        }

        // THE TRANSPORT'S OWN SETTINGS COME FROM THE SAME FAMILY AS ITS SWITCHES. A channel call reads `sales_channels.http.*`
        // exactly as before; an accounting call reads Odoo's, because a thirty-second ceiling that suits a marketplace is not
        // somebody else's to set for a ledger. One mechanism, each family's own numbers.
        $http = $this->httpSettingsFor($operation);
        [$max, $perSeconds, $bucket] = $this->rateLimitFor($account, $operation);
        // A write is never retried by the transport, whatever the family allows: that decision belongs to the caller that knows
        // whether a repeat is safe. For a journal entry it never is without asking Odoo first.
        $retries = ChannelOperations::isRetrySafe($operation) ? $http['retries'] : 0;

        $attempt = 0;
        while (true) {
            $attempt++;
            $this->limiter->acquire($account->id . ':' . $bucket, $max, $perSeconds);
            $response = $this->attempt($method, $url, $headers, $json, $query, $form, $correlationId, $rawBody, $http, $multipart);

            $retryable = in_array($response->kind, [ChannelResponse::RATE_LIMITED, ChannelResponse::SERVER_ERROR, ChannelResponse::NOT_SENT, ChannelResponse::AMBIGUOUS], true);
            if (!$retryable || $attempt > $retries) {
                break;
            }
            Sleep::for($this->backoffMilliseconds($attempt, $response))->milliseconds();
        }

        $response = new ChannelResponse(
            $response->kind, $response->status, $response->json, $response->body, $response->error, $response->durationMs, $correlationId, $response->headers, $attempt,
        );
        $this->log($account, $operation, $method, $url, $headers, $this->loggableBody($json, $form, $rawBody, $multipart, $rawBodyForLog), $query, $response);

        return $response;
    }

    /**
     * A GET whose body is read in pieces and never held whole.
     *
     * **Why the transport grew a second door rather than the caller opening its own.** Everything that makes this class worth
     * having happens before the request: the environment and account switches, the capability and approval gates, the rate
     * limiter, and the log line afterwards. A caller that reached for `Http::` directly to stream would be a call that
     * answers to none of them -- which is the shape of the defect this module's own guards exist to catch. So streaming is
     * here, behind the same gates, and the only thing that differs is what comes back.
     *
     * The body is handed to `$consume` in chunks, in order, and this method never keeps more than one chunk. **Nothing is
     * written to disk**: Amazon's own Reports page states that "Unencrypted report content must never be stored on disk, even
     * temporarily", so a `sink` to a temporary file is not an option here however convenient it would be.
     *
     * Retries are NOT attempted. A stream half-consumed cannot be replayed -- the consumer has already been handed the first
     * part of a body that would arrive again from the beginning -- so a failure mid-stream is reported as itself and the
     * caller asks for a fresh document. That is also why this is GET-only: there is no safe way to stream a write.
     *
     * @param callable(string): void $consume called with each chunk, in order
     * @param int $ceiling the most bytes this will read before refusing; 0 for no ceiling
     * @return array{ok: bool, status: int|null, bytes: int, refusal: string|null}
     */
    public function stream(
        SalesChannelAccount $account,
        string $operation,
        string $url,
        callable $consume,
        array $headers = [],
        array $query = [],
        ?IntegrationCapability $capability = null,
        ?string $scope = null,
        int $ceiling = 0,
        int $chunkBytes = 65536,
    ): array {
        $correlationId = (string) Str::uuid();
        $dryRun = $this->assertAllowed($account, $operation, 'GET', $capability, $scope, $query);
        if ($dryRun) {
            $response = ChannelResponse::dryRun($correlationId);
            $this->log($account, $operation, 'GET', $url, $headers, null, $query, $response);

            return ['ok' => false, 'status' => null, 'bytes' => 0, 'refusal' => 'rehearsal_streamed_nothing'];
        }

        $http = $this->httpSettingsFor($operation);
        [$max, $perSeconds, $bucket] = $this->rateLimitFor($account, $operation);
        $this->limiter->acquire($account->id . ':' . $bucket, $max, $perSeconds);

        $started = hrtime(true);
        $bytes = 0;
        $status = null;
        $refusal = null;

        try {
            $response = Http::withHeaders($headers)
                ->connectTimeout($http['connect'])
                ->timeout($http['timeout'])
                ->withoutRedirecting()
                ->withOptions(['stream' => true])
                ->send('GET', $url, $query === [] ? [] : ['query' => $query]);
            $status = $response->status();

            if ($response->successful()) {
                $body = $response->toPsrResponse()->getBody();
                while (!$body->eof()) {
                    $chunk = $body->read(max(1024, $chunkBytes));
                    if ($chunk === '') {
                        // A stream that returns nothing without reporting eof would spin here. Treated as the end, because
                        // the alternative is a worker that never stops reading.
                        break;
                    }
                    $bytes += strlen($chunk);
                    if ($ceiling > 0 && $bytes > $ceiling) {
                        // Refused mid-flight, which is the whole point: the old reader could only refuse a document AFTER it
                        // had been held whole in memory, so the ceiling bounded what was parsed and not what was received.
                        $refusal = 'stream_is_larger_than_we_will_read:' . $bytes;
                        break;
                    }
                    $consume($chunk);
                }
                $body->close();
            } else {
                $refusal = 'stream_refused:' . $status;
            }
        } catch (ConnectionException $exception) {
            $refusal = 'stream_failed:' . SecretRedactor::redactString($exception->getMessage());
        } catch (Throwable $exception) {
            // A consumer that threw, or a decoder that could not go on. The bytes read so far are reported, because a caller
            // that was handed part of a document must know it was part.
            $refusal = 'stream_could_not_be_read:' . SecretRedactor::redactString($exception->getMessage());
        }

        // Logged like any other call, with the SIZE of what came back and never its content: a report body is a seller's own
        // catalogue or settlement data, and this one is too big to belong in a log whatever it held.
        $outcome = new ChannelResponse(
            $refusal === null ? ChannelResponse::SUCCESS : ($status !== null && $status >= 400 ? ChannelResponse::REJECTED : ChannelResponse::AMBIGUOUS),
            $status,
            null,
            'streamed ' . $bytes . ' bytes',
            $refusal,
            $this->elapsedMs($started),
            $correlationId,
        );
        $this->log($account, $operation, 'GET', $url, $headers, null, $query, $outcome);

        return ['ok' => $refusal === null, 'status' => $status, 'bytes' => $bytes, 'refusal' => $refusal];
    }

    /**
     * What goes in the log for this body.
     *
     * A raw body is already-encoded text, and the logger takes an array, so it is wrapped under one key rather than left out:
     * a call whose body never appears in the log is a call nobody can reconstruct afterwards. The existing redaction still runs
     * over it.
     *
     * @param array<string, mixed>|null $json
     * @param array<string, string>|null $form
     * @return array<string, mixed>|null
     */
    private function loggableBody(array|object|null $json, ?array $form, ?string $rawBody, ?array $multipart = null, ?string $rawBodyForLog = null): ?array
    {
        if ($rawBody === null) {
            // A multipart body here is plain fields only -- no document's bytes ever travel through it -- so it is logged as
            // itself, redacted by the same rules as any other body.
            return $multipart ?? (is_object($json) ? ['json' => (array) $json] : $json) ?? $form;
        }

        // **A RAW BODY IS NEVER LOGGED AS ITSELF, and logging it was a plain-text credential in the audit trail.**
        //
        // The redactors work by KEY: `password`, `authorization`, `api_key`. A raw body has no keys this layer can see -- it is
        // a payload in a protocol `ChannelHttp` does not parse -- so `['raw' => $body]` handed the whole document to a
        // redactor that could only scan it as free text. That catches a LABELLED secret (`password=hunter2`) and cannot catch
        // a POSITIONAL one. Odoo's XML-RPC is positional: `execute_kw(db, uid, key, ...)` carries the API key as its third
        // unnamed `<string>`, so every single Odoo call wrote that key in clear text into `sales_channel_api_logs`.
        //
        // So the default is the byte count and nothing else. A caller that knows the layout may hand over a copy it has masked
        // itself -- the knowledge of which field is a secret belongs where the body is built, not here -- and a caller that
        // forgets gets no body in its log rather than a leak.
        return $rawBodyForLog !== null ? ['raw' => $rawBodyForLog] : ['raw_bytes' => strlen($rawBody)];
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
        // WHICH MASTER SWITCH GOVERNS THIS CALL IS THE OPERATION'S OWN BUSINESS. Nearly every operation here belongs to the
        // sales-channel family and reads `sales_channels.enabled`, exactly as before. Odoo's accounting operations name their own
        // family, so switching the channels on does not, by itself, permit a line to be written into somebody's books -- and
        // Odoo being on does not switch any channel on either. One mechanism, two policies.
        $switches = ChannelOperations::switchesOf($operation);
        if (!config($switches . '.enabled', false)) {
            throw new ChannelUnavailableException(
                ChannelUnavailableException::GLOBAL_OFF,
                $switches === ChannelOperations::SWITCHES_SALES_CHANNELS
                    ? 'Sales channel integrations are switched off (SALES_CHANNELS_ENABLED); nothing was sent.'
                    : 'The ' . $switches . ' integration is switched off (' . strtoupper($switches) . '_ENABLED); nothing was sent.',
            );
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
        if (!config($switches . '.external_writes_enabled', false)) {
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
     * @param array<string, string>|null $multipart plain fields only; see `send()`
     */
    private function attempt(string $method, string $url, array $headers, array|object|null $json, array $query, ?array $form, string $correlationId, ?string $rawBody = null, ?array $http = null, ?array $multipart = null): ChannelResponse
    {
        $started = hrtime(true);
        $http ??= ['connect' => (int) config('sales_channels.http.connect_timeout', 5), 'timeout' => (int) config('sales_channels.http.timeout', 30), 'retries' => 3];

        try {
            $pending = Http::withHeaders($headers)
                ->connectTimeout($http['connect'])
                ->timeout($http['timeout'])
                ->withoutRedirecting();
            // Every channel here speaks JSON, so that header stays the default. A caller with an already-encoded body has said
            // what it is sending in its own headers, and overwriting its `Accept` would ask an XML-RPC endpoint for JSON.
            if ($rawBody === null) {
                $pending = $pending->acceptJson();
            }
            $options = [];
            // Three encodings, and they are never set together: a raw body (Odoo's XML-RPC), a multipart body of plain fields
            // (Trendyol's return rejection, the only encoding its page documents), or the form and JSON the channels use.
            if ($rawBody !== null) {
                $options['body'] = $rawBody;
            } elseif ($multipart !== null) {
                // `asMultipart()` and Guzzle's own `multipart` option: the boundary is Guzzle's to choose, and setting
                // Content-Type by hand here would override it and produce a body no server can parse.
                $pending = $pending->asMultipart();
                $options['multipart'] = array_map(
                    static fn (string $name, string $value): array => ['name' => $name, 'contents' => $value],
                    array_keys($multipart),
                    array_values($multipart),
                );
            } elseif ($form !== null) {
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
    /**
     * The timeouts and retry ceiling of the family this operation belongs to.
     *
     * @return array{connect: int, timeout: int, retries: int}
     */
    private function httpSettingsFor(string $operation): array
    {
        $family = ChannelOperations::switchesOf($operation);
        $key = $family === ChannelOperations::SWITCHES_SALES_CHANNELS ? 'sales_channels.http' : $family;

        return [
            'connect' => (int) config($key . '.connect_timeout', 5),
            'timeout' => (int) config($key . '.timeout', 30),
            'retries' => (int) config($key . '.retries', 3),
        ];
    }

    /** @return array{0: int, 1: int, 2: string} */
    private function rateLimitFor(SalesChannelAccount $account, string $operation): array
    {
        $family = ChannelOperations::switchesOf($operation);
        // A channel's limits are per channel, because they are the channel's own published quota. A family that is not the sales
        // channels keeps its limits in its own file, under its own name.
        $limits = $family === ChannelOperations::SWITCHES_SALES_CHANNELS
            ? (array) config('sales_channels.rate_limits.' . $account->channel, [])
            : (array) config($family . '.rate_limits', []);
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

    private function log(SalesChannelAccount $account, string $operation, string $method, string $url, array $headers, array|object|null $body, array $query, ChannelResponse $response): void
    {
        try {
            // An object body (a service that insists on `{}`) is logged as the array it stands for; an empty one logs as nothing, which is what it is.
            $body = is_object($body) ? (array) $body : $body;
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
