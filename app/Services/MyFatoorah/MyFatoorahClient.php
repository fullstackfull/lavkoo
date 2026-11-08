<?php

namespace App\Services\MyFatoorah;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The only place that talks to MyFatoorah over the network.
 *
 * Rules this class enforces so no caller has to remember them:
 *
 *  - HTTPS to an allow-listed host only. The base URL already comes from GatewayConfigRepository, which
 *    pins it, and it is re-checked here because this is the last point before the API key goes out.
 *  - A write is never retried. `POST /v3/payments` creates an invoice; a retry after a timeout could
 *    create a second one and charge the customer twice. Only idempotent reads (`GET`) retry, with
 *    exponential backoff, and only on a connection failure, a 429 or a 5xx.
 *  - `Retry-After` is honoured when MyFatoorah sends it; the documentation states no rate limits, so
 *    the header is the only signal there is.
 *  - Nothing sensitive is logged. The API key never appears, and neither does a response body: a
 *    payment-details body contains a cardholder name and a masked PAN. Logs get the method, the path,
 *    the status, the duration and MyFatoorah's own error code.
 *
 * Every method returns a MyFatoorahResponse rather than throwing, because every caller has to make a
 * decision about a failure (a timeout during verification means "ask again later", never "not paid").
 */
class MyFatoorahClient
{
    public function __construct(private readonly GatewayConfigRepository $configRepo)
    {
    }

    public function post(GatewayConfig $config, string $endpointKey, array $body, array $pathParams = []): MyFatoorahResponse
    {
        return $this->send($config, 'POST', $endpointKey, $pathParams, $body, retry: false);
    }

    public function get(GatewayConfig $config, string $endpointKey, array $pathParams = []): MyFatoorahResponse
    {
        return $this->send($config, 'GET', $endpointKey, $pathParams, null, retry: true);
    }

    private function send(
        GatewayConfig $config,
        string $method,
        string $endpointKey,
        array $pathParams,
        ?array $body,
        bool $retry,
    ): MyFatoorahResponse {
        if (!$config->hasApiKey()) {
            return MyFatoorahResponse::localFailure('no_api_key', 'No MyFatoorah API key is configured for this mode.');
        }

        $path = (string) config('myfatoorah.endpoints.' . $endpointKey, '');
        if ($path === '') {
            return MyFatoorahResponse::localFailure('no_endpoint', 'Endpoint ' . $endpointKey . ' is not configured.');
        }

        foreach ($pathParams as $name => $value) {
            $path = str_replace('{' . $name . '}', rawurlencode((string) $value), $path);
        }

        $url = $config->baseUrl . '/' . ltrim($path, '/');

        if (!$this->urlIsAllowed($url)) {
            // Refusing rather than calling: this is the check that stops a tampered settings row from
            // posting a live API key to somebody else's server.
            Log::warning('myfatoorah.blocked_host', ['endpoint' => $endpointKey]);

            return MyFatoorahResponse::localFailure('blocked_host', 'Refused to call a host that is not on the MyFatoorah allow-list.');
        }

        $attempts = $retry ? max(1, (int) config('myfatoorah.http.max_retries', 2) + 1) : 1;
        $baseDelayMs = max(50, (int) config('myfatoorah.http.retry_base_ms', 400));
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $startedAt = microtime(true);

            try {
                $request = Http::withHeaders([
                        'Authorization' => 'Bearer ' . $config->apiKey,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ])
                    ->connectTimeout((int) config('myfatoorah.http.connect_timeout', 10))
                    ->timeout((int) config('myfatoorah.http.timeout', 30))
                    // A redirect would re-send the Authorization header to wherever it points.
                    ->withoutRedirecting()
                    ->asJson();

                $response = $method === 'POST'
                    ? $request->post($url, $body ?? [])
                    : $request->get($url);

                $parsed = MyFatoorahResponse::fromHttp($response);
                $this->logCall($endpointKey, $method, $response->status(), $startedAt, $attempt, $parsed->errorCode);

                if (!$this->shouldRetry($response) || $attempt === $attempts) {
                    return $parsed;
                }

                $last = $parsed;
                $this->wait($this->delayMs($response, $attempt, $baseDelayMs));
            } catch (ConnectionException $e) {
                // A timeout tells us nothing about whether the money moved. Callers must treat this as
                // "unknown", which is what a transport failure in MyFatoorahResponse means.
                $this->logCall($endpointKey, $method, 0, $startedAt, $attempt, 'connection');
                $last = MyFatoorahResponse::transportFailure('connection', $this->safeMessage($e->getMessage()));

                if ($attempt === $attempts) {
                    return $last;
                }

                $this->wait($baseDelayMs * (2 ** ($attempt - 1)));
            } catch (\Throwable $e) {
                $this->logCall($endpointKey, $method, 0, $startedAt, $attempt, 'exception');

                return MyFatoorahResponse::transportFailure('exception', $this->safeMessage($e->getMessage()));
            }
        }

        return $last ?? MyFatoorahResponse::transportFailure('unknown', 'No response from MyFatoorah.');
    }

    /** Only a connection-level problem, a rate limit or a server fault is worth asking again about. */
    private function shouldRetry(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    private function delayMs(Response $response, int $attempt, int $baseDelayMs): int
    {
        $retryAfter = $response->header('Retry-After');

        if (is_numeric($retryAfter)) {
            // Cap it: a provider asking us to sleep for minutes would hold a web request open.
            return (int) min(5000, max(0, (int) $retryAfter * 1000));
        }

        return (int) min(5000, $baseDelayMs * (2 ** ($attempt - 1)));
    }

    private function wait(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    private function urlIsAllowed(string $url): bool
    {
        if (!str_starts_with($url, 'https://')) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && in_array($host, (array) config('myfatoorah.allowed_hosts', []), true);
    }

    /** A message fit for a log or an admin screen: no URLs, no headers, no payload, bounded length. */
    private function safeMessage(string $message): string
    {
        $message = preg_replace('/https?:\/\/\S+/', '[url]', $message) ?? $message;
        $message = preg_replace('/[A-Za-z0-9_\-]{24,}/', '[redacted]', $message) ?? $message;

        return Str::limit(trim($message), 200, '');
    }

    private function logCall(string $endpoint, string $method, int $status, float $startedAt, int $attempt, ?string $errorCode): void
    {
        Log::info('myfatoorah.call', [
            'endpoint' => $endpoint,
            'method' => $method,
            'status' => $status,
            'attempt' => $attempt,
            'ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'error_code' => $errorCode,
        ]);
    }
}
