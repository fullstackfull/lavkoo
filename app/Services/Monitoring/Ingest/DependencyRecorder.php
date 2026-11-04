<?php

namespace App\Services\Monitoring\Ingest;

use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Redactor;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * Turns one outbound call into a row, for the calls this build can see.
 *
 * Registered once as a global middleware on Laravel's HTTP client rather than at each call site,
 * for the same reason the rest of this system listens to framework events: instrumentation that has
 * to be remembered is instrumentation that is missing from the fifteenth payment gateway. No caller
 * changes, and no caller can quietly stop being measured.
 *
 * WHAT IT CANNOT SEE, WHICH IS MOST OF THIS SHOP. It sees the 23 `Http::` call sites in 14 files
 * and nothing else: not the 41 raw `curl_exec` sites in 16 files, not the vendor SDKs that carry
 * their own transport (Stripe, RazorPay, MercadoPago, Twilio, openai-php), not the `GuzzleHttp\Client`
 * instances built by hand in SocialAuthController, CustomerAPIAuthController and SmsGateway, and
 * not `Mail::`. By domain that is one of the fourteen payment gateways (Paymera), one of the twenty
 * SMS send paths (global_sms), and the one courier. So a service with no row here means "nothing
 * called it through the HTTP client" and never "the integration is healthy" — a page that read the
 * absence of rows as the absence of trouble would be describing the instrumentation and calling it
 * the shop.
 *
 * That is why the recorder announces its TRANSPORT the moment it is registered, before it has
 * anything to report. Otherwise an empty dependency table has two readings — nothing records
 * outbound calls, and nothing was called — and no reader can tell them apart.
 *
 * Naming is deliberately coarse. The service is the host and the operation is the shape of the
 * path, because those two strings are half the bucket's primary key: a full URL would write a row
 * per order id and turn a bounded table into a log.
 */
class DependencyRecorder
{
    /**
     * The transport this recorder measures, and the label it announces itself under.
     *
     * One label per instrumented transport, so the set of labels present in the series IS the set
     * of transports somebody is measuring. A curl or SDK wrapper written later adds its own label
     * beside this one, and the page's statement of its own coverage follows the data instead of a
     * sentence somebody has to remember to update.
     */
    public const TRANSPORT = 'http_client';

    /** Says a recorder exists in this deployment, whether or not a call has ever reached it. */
    public const RECORDER_SERIES = 'dependency.recorder';

    /** Path segments kept in an operation. Four separates /maps/api/place from /maps/api/geocode. */
    private const MAX_PATH_SEGMENTS = 4;

    /** A segment longer than this is a token, not a route name. */
    private const MAX_SEGMENT_LENGTH = 32;

    /** The width of monitoring_dependency_buckets.last_error. */
    private const MAX_ERROR_LENGTH = 191;

    /** The user agent app/Services/Monitoring/Checks/SyntheticCheck.php sends. */
    private const PROBE_USER_AGENT = 'PharmacyMonitoring/';

    public function __construct(
        private readonly MetricSink $sink,
        private readonly BucketWriter $writer,
        private readonly Redactor $redactor,
    ) {
    }

    /**
     * Publish the fact that this transport is instrumented, independently of any call.
     *
     * A counter of processes rather than of calls, which is the point: it stays true through an
     * hour in which the shop called nobody, and that is the hour in which "no outbound call was
     * recorded" is otherwise indistinguishable from "nothing here records outbound calls".
     */
    public function announceTransport(): void
    {
        $this->sink->increment(BucketWriter::SERIES_PREFIX . self::RECORDER_SERIES . '|' . self::TRANSPORT, 'n');
    }

    /**
     * Fold one finished call into the minute it belongs to.
     *
     * @param  float  $startedAt  microtime(true) taken before the request left
     */
    public function record(RequestInterface $request, ?ResponseInterface $response, ?\Throwable $failure, float $startedAt): void
    {
        try {
            if ($this->sink->driver() === MetricSink::DRIVER_NONE || $this->isOwnProbe($request)) {
                return;
            }

            $durationMs = (microtime(true) - $startedAt) * 1000;
            $status = $response?->getStatusCode();
            $now = Clock::now()->getTimestamp();
            $bucket = BucketWriter::DEPENDENCY_PREFIX . $this->serviceOf($request->getUri()) . '|' . $this->operationOf($request);

            $this->sink->increment($bucket, 'calls');
            $this->sink->increment($bucket, 'dur_sum', (int) round($durationMs));
            $this->sink->increment($bucket, 'hist.' . $this->histogramIndex($durationMs));
            $this->sink->observeExtremes($bucket, 'dur', (int) round($durationMs));

            if ($status !== null && $status < 400) {
                // Epoch seconds down the extremes channel, because the buffer carries numbers and
                // the latest of a set of numbers is a max. That also settles the race two web
                // servers writing the same minute would otherwise have: the later stamp wins,
                // rather than the one that happened to be written last.
                $this->sink->observeExtremes($bucket, 'ok_at', $now);
            } else {
                $this->sink->increment($bucket, 'failures');
                $this->sink->observeExtremes($bucket, 'bad_at', $now);
            }

            match (true) {
                $status === null => null,
                $status >= 500 => $this->sink->increment($bucket, 'server_errors'),
                $status >= 400 => $this->sink->increment($bucket, 'client_errors'),
                default => null,
            };

            if ($status === 429) {
                $this->sink->increment($bucket, 'rate_limited');
            }

            // Only a call that ran out of time counts as a timeout. A request bucket infers one
            // from its duration because nothing else can; here the client says so itself, and a
            // slow answer that arrived is a different fault from an answer that never came.
            if ($failure !== null && $this->timedOut($failure)) {
                $this->sink->increment($bucket, 'timeouts');
            }

            $this->addToRequestBreakdown($durationMs);

            // Flushed here rather than left to the request's terminate(): an outbound call made by
            // a queue worker, a scheduled command or an artisan run would otherwise die with the
            // process, and calls made outside a web request are exactly the ones nobody watches.
            $minute = intdiv($now, 60) * 60;
            $this->sink->flush($minute);

            $error = $this->errorText($response, $failure);
            if ($error === null) {
                return;
            }

            // The one reading the buffer cannot carry — it stores numbers and this is text — so it
            // goes straight to the bucket, carrying no counter at all so it adds nothing to the
            // measurement the flush above just made. Only a failed call pays for the second write.
            $this->writer->apply([$minute => [$bucket => ['err' => $error, 'bad_at:max' => $now]]]);
        } catch (\Throwable) {
            // Monitoring never fails the call it is measuring. A lost reading is a gap in a chart;
            // an exception thrown from here is a failed payment.
        }
    }

    /**
     * The stable key a call is filed under: the host, and nothing from the path.
     *
     * Hosts are bounded by what this deployment is configured to talk to — no `Http::` call site in
     * this codebase takes its host from a customer — so the vocabulary is small and stays
     * comparable across deploys, which a URL never is.
     */
    private function serviceOf(UriInterface $uri): string
    {
        $host = strtolower($uri->getHost());
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        return $host === '' ? 'unknown' : Str::limit($host, 64, '');
    }

    /**
     * The operation inside that service: the verb, and the shape of the path.
     *
     * Identifier-looking segments become {id} and the tail is dropped after four, because the
     * operation is the other half of the bucket's key: /v1/charges/ch_3Oa.../capture has to be one
     * row however many charges the shop makes, or the monitoring table grows with the orders.
     *
     * The query string is dropped rather than redacted. It is where the credentials are — `?key=`
     * on every Maps call, `?access_token=` on the gateways — and a value that is never read cannot
     * be the one that leaks.
     */
    private function operationOf(RequestInterface $request): string
    {
        $path = array_values(array_filter(explode('/', $request->getUri()->getPath()), 'strlen'));
        $segments = [];

        foreach (array_slice($path, 0, self::MAX_PATH_SEGMENTS) as $segment) {
            $segments[] = $this->isIdentifier($segment) ? '{id}' : $segment;
        }

        return Str::limit(strtoupper($request->getMethod()) . ' /' . implode('/', $segments), 96, '');
    }

    /**
     * Whether a path segment names a thing rather than a route.
     *
     * Anything carrying a digit or long enough to be a token is an id — an order, a charge, a
     * session — and keeping it would put the shop's row count into the monitoring table. Version
     * prefixes are the exception: v1 and v3 are part of the endpoint's name, not of its arguments.
     */
    private function isIdentifier(string $segment): bool
    {
        if (preg_match('/^v\d{1,3}$/i', $segment) === 1) {
            return false;
        }

        return strlen($segment) > self::MAX_SEGMENT_LENGTH || preg_match('/\d/', $segment) === 1;
    }

    /**
     * Whether a failed call ran out of time rather than being refused.
     *
     * Guzzle reports a refused connection and an expired timeout as the same exception class, so
     * the message is the only thing that separates "the gateway is down" from "the gateway is slow
     * enough to be down" — and those two send an operator to different people.
     */
    private function timedOut(\Throwable $failure): bool
    {
        return preg_match('/timed out|timeout/i', $failure->getMessage()) === 1;
    }

    /**
     * What went wrong, in a form that is safe to store.
     *
     * All of it is remote text: an exception message routinely carries the URL it was fetching, and
     * that URL routinely carries a key. It is redacted before it is truncated, so a cut can never
     * leave the front half of a token behind.
     */
    private function errorText(?ResponseInterface $response, ?\Throwable $failure): ?string
    {
        $message = match (true) {
            $failure !== null => class_basename($failure) . ': ' . $failure->getMessage(),
            $response !== null && $response->getStatusCode() >= 400 => 'HTTP ' . $response->getStatusCode() . ' ' . $response->getReasonPhrase(),
            default => null,
        };

        return $message === null
            ? null
            : Str::limit($this->redactor->text($message), self::MAX_ERROR_LENGTH, '');
    }

    /**
     * Count the call against the request that made it, where a request's time is broken down.
     *
     * monitoring_request_buckets already has the columns and TraceRecorder already reads the
     * fields; nothing incremented them, so every external_ms_sum was a structural zero and "where
     * did this request's second go" had no answer for the part spent waiting on somebody else.
     */
    private function addToRequestBreakdown(float $durationMs): void
    {
        if (!app()->bound(RequestContext::class)) {
            return;
        }

        $context = app(RequestContext::class);
        $context->externalCalls++;
        $context->externalMs += $durationMs;
    }

    /**
     * The dashboard's own synthetic checks, which are recorded in monitoring_check_results and
     * already reported from there. Counting them again here would file the monitor's traffic as the
     * shop's integrations, and make the busiest dependency on the page the page itself.
     */
    private function isOwnProbe(RequestInterface $request): bool
    {
        return str_starts_with($request->getHeaderLine('User-Agent'), self::PROBE_USER_AGENT);
    }

    /** Which latency bucket a duration falls into; the last index is the overflow slot. */
    private function histogramIndex(float $durationMs): int
    {
        $bounds = (array) config('monitoring.latency_buckets_ms', []);

        foreach ($bounds as $index => $bound) {
            if ($durationMs <= $bound) {
                return $index;
            }
        }

        return count($bounds);
    }
}
