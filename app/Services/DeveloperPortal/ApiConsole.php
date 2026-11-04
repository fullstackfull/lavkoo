<?php

namespace App\Services\DeveloperPortal;

use App\Services\AuditLogger;
use App\Services\Monitoring\Support\Redactor;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends one request at this application's own API and brings back what it answered.
 *
 * The request is built here and handed to this application's own HTTP kernel rather than put on the
 * network. That is not an optimisation:
 *
 *  - There is no URL to point anywhere. The target is a manifest entry and the path is assembled
 *    from it, so the console cannot be talked into fetching an internal address, a cloud metadata
 *    endpoint or anything else it was not meant to reach. A console that took a URL would be an
 *    SSRF hole with a documentation page around it.
 *  - It works on installations that cannot reach themselves — behind a proxy, on a private
 *    network, with TLS terminated elsewhere — which is most of them.
 *  - The real middleware runs. Authentication, throttling and the route's own guards behave exactly
 *    as they do for the mobile app, so what the console shows is what the app would get, rather
 *    than what the code would do if the middleware were skipped.
 *
 * Two things it never does: it never sends the administrator's own session, and it never writes the
 * token down. The token is typed into the form, used for one call, and redacted out of the
 * transcript on the way back.
 */
class ApiConsole
{
    /** A response body longer than this is shown truncated: a console is not a file viewer. */
    private const MAX_BODY_BYTES = 64_000;

    /** Marks the sub-request as the panel's own, so it is not counted as customer traffic. */
    public const MARKER_HEADER = 'X-Developer-Console';

    public function __construct(
        private readonly ConsoleGuard $guard,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $endpoint
     * @param  array<string, string>  $pathParameters
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function send(array $endpoint, string $method, array $pathParameters, array $payload, ?string $token): array
    {
        $method = strtoupper($method);
        $verdict = $this->guard->verdict($endpoint, $method);

        if (!$verdict['allowed']) {
            return ['ok' => false] + $verdict;
        }

        $path = $this->pathFor($endpoint, $pathParameters);

        if ($path === null) {
            return ['ok' => false, 'tier' => $verdict['tier'], 'reason_key' => 'a_path_parameter_is_missing_or_is_not_a_plain_value', 'remedy' => null];
        }

        $startedAt = microtime(true);
        $response = $this->dispatch($path, $method, $payload, $token);
        $duration = (int) round((microtime(true) - $startedAt) * 1000);

        $this->audit->record(
            action: 'developer.console.sent',
            subject: ['type' => 'api_endpoint', 'id' => (string) $endpoint['id']],
            context: [
                'method' => $method,
                'path' => $path,
                'status' => $response->getStatusCode(),
                'duration_ms' => $duration,
                'tier' => $verdict['tier'],
                // Whether a token was used, never which one.
                'authenticated' => $token !== null && $token !== '',
            ],
        );

        return [
            'ok' => true,
            'tier' => $verdict['tier'],
            'request' => [
                'method' => $method,
                'path' => '/' . $path,
                // Echoed back redacted, because the panel prints it and a token pasted into the
                // wrong field would otherwise end up on screen and in a screenshot.
                'body' => $method === 'GET' ? null : Redactor::make()->array($payload),
                'authenticated' => $token !== null && $token !== '',
            ],
            'response' => $this->describe($response, $duration),
        ];
    }

    // -------------------------------------------------------------------------------------------

    /**
     * The concrete path to call, with the route's placeholders filled in.
     *
     * A parameter may be a plain value and nothing else — no slash, so it cannot add a path
     * segment, and no percent sign, so it cannot smuggle one in encoded.
     *
     * @param  array<string, mixed>  $endpoint
     * @param  array<string, string>  $pathParameters
     */
    private function pathFor(array $endpoint, array $pathParameters): ?string
    {
        $path = ltrim((string) $endpoint['path'], '/');

        foreach ($endpoint['path_parameters'] ?? [] as $parameter) {
            $name = (string) $parameter['name'];
            $value = trim((string) ($pathParameters[$name] ?? ''));
            $optional = ($parameter['required'] ?? true) === false;

            if ($value === '') {
                if (!$optional) {
                    return null;
                }

                $path = str_replace(['/{' . $name . '?}', '{' . $name . '?}'], '', $path);

                continue;
            }

            if (preg_match('/^[A-Za-z0-9._~@:\- ]{1,120}$/', $value) !== 1) {
                return null;
            }

            $path = str_replace(
                ['{' . $name . '}', '{' . $name . '?}'],
                rawurlencode($value),
                $path,
            );
        }

        // Anything still wearing braces was never offered to the operator, so there is no value for
        // it and calling the route with a literal "{id}" would be a nonsense request.
        return str_contains($path, '{') ? null : $path;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(string $path, string $method, array $payload, ?string $token): Response
    {
        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_' . str_replace('-', '_', strtoupper(self::MARKER_HEADER)) => '1',
        ];

        if ($token !== null && $token !== '') {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        $isRead = in_array($method, ['GET', 'HEAD'], true);

        $subRequest = Request::create(
            uri: '/' . $path,
            method: $method,
            parameters: $isRead ? $payload : [],
            server: $server,
            content: $isRead ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
        );

        if (!$isRead) {
            $subRequest->headers->set('Content-Type', 'application/json');
        }

        $kernel = app(Kernel::class);
        $original = app('request');

        try {
            return $kernel->handle($subRequest);
        } catch (\Throwable $exception) {
            // A thrown exception IS the answer to "what does this endpoint do with that input", so
            // it is reported rather than swallowed — as the class name and message, never the
            // stack trace, which names paths on the server.
            return response()->json([
                'errors' => [[
                    'code' => class_basename($exception),
                    'message' => $exception->getMessage(),
                ]],
            ], 500);
        } finally {
            // The outer request goes back into the container before anything else touches it.
            // Without this the admin page finishes rendering as though it were the sub-request,
            // which is how a "try it" console starts returning JSON to the browser.
            app()->instance('request', $original);
        }
    }

    /** @return array<string, mixed> */
    private function describe(Response $response, int $duration): array
    {
        $body = (string) $response->getContent();
        $truncated = strlen($body) > self::MAX_BODY_BYTES;

        if ($truncated) {
            $body = substr($body, 0, self::MAX_BODY_BYTES);
        }

        $decoded = json_decode($body, true);

        return [
            'status' => $response->getStatusCode(),
            'duration_ms' => $duration,
            'headers' => Redactor::make()->headers($response->headers->all()),
            // Decoded and redacted when it is JSON, which is the only thing this API answers with.
            // Anything else is shown as text, still truncated, still without its headers.
            'json' => is_array($decoded) ? Redactor::make()->array($decoded) : null,
            'text' => is_array($decoded) ? null : $body,
            'truncated' => $truncated,
        ];
    }
}
