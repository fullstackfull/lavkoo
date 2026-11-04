<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Metric;
use Illuminate\Support\Facades\Schema;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Histogram;
use App\Services\Monitoring\Support\Redactor;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Integrations: every service this shop calls out to, and which of them anybody is watching.
 *
 * The page is built around one asymmetry. A dependency that is instrumented has a row per minute in
 * monitoring_dependency_buckets and can be reported honestly — volume, failures, latency, silence.
 * A dependency that is NOT instrumented has nothing, and the tempting thing to do with nothing is to
 * leave it off the page. That is the failure this section exists to avoid: a screen listing three
 * integrations for a shop that calls out to thirteen reads as a complete picture, and the ten it
 * omits are exactly the ten nobody would think to check. So the catalogue below names every outbound
 * seam in the codebase whether or not a recorder exists behind it, and says which.
 *
 * The reading that matters most is the last successful call, not the failure rate. A gateway with a
 * 0% failure rate over an hour and a gateway that stopped being called an hour ago produce the same
 * two numbers — zero failures out of zero calls — and only one of them is a working checkout. That
 * is why every service carries when it last succeeded, why the window's services are diffed against
 * the previous window to name the ones that went quiet, and why a service with no calls is never
 * rendered as a healthy 0%.
 *
 * Two facts about this build shape everything above:
 *
 * 1. NOTHING WRITES THIS TABLE. BucketWriter::DEPENDENCY_PREFIX ('dep|') is referenced only inside
 *    BucketWriter itself; no middleware, listener or client wrapper ever increments MetricSink under
 *    it. The table, its writer, its rollup, its retention plan and the Overview cards that read it
 *    are all built and waiting for a producer that was never written. An empty table here is
 *    therefore a statement about the instrumentation and not about the integrations, which is why
 *    the recorder banner is assembled first and drawn above every count.
 * 2. EVEN WITH A PRODUCER, three columns would stay empty. BucketWriter::writeDependencies() builds
 *    its row without last_success_at, last_failure_at or last_error, so the single most useful
 *    reading on this page has no writer at either end. It is published as an unconfigured reading
 *    with the file that would have to set it — and a bucket-granular substitute is derived from the
 *    buckets that do exist, labelled as the coarser thing it is rather than passed off as the column.
 */
class IntegrationsPanel implements Panel
{
    private const SOURCE = 'monitoring_dependency_buckets';

    private const PROBE_SOURCE = 'monitoring_check_results';

    private const REQUEST_SOURCE = 'monitoring_request_buckets';

    /** The only code that writes a dependency bucket — named on the page so the gap is a task. */
    private const WRITER = 'app/Services/Monitoring/Ingest/BucketWriter.php::writeDependencies()';

    /** Where the missing producer would go. One registration covers every Http:: caller at once. */
    private const PRODUCER_SEAM = 'app/Providers/MonitoringServiceProvider.php::boot()';

    /** The remedy that fills this whole page, repeated wherever the emptiness is explained. */
    private const PRODUCER_REMEDY = 'Register one Http::globalMiddleware in '
        . self::PRODUCER_SEAM . ' and increment MetricSink under BucketWriter::DEPENDENCY_PREFIX'
        . " . \$service . '|' . \$operation with calls/failures/timeouts/client_errors/server_errors/rate_limited"
        . ' and the latency histogram from config(\'monitoring.latency_buckets_ms\').';

    /** The three resolutions the writer and the rollup between them can produce. */
    private const RESOLUTIONS = ['minute', 'hour', 'day'];

    /**
     * Distinct services listed. The service column is a stable key (stripe, twilio, fcm), so this is
     * a generous ceiling on a bounded vocabulary rather than a cut through a long tail.
     */
    private const MAX_SERVICES = 40;

    /** Service × operation rows. Operations are normalised paths, so this one can genuinely run out. */
    private const MAX_OPERATIONS = 120;

    /**
     * Raw buckets folded for the latency distribution.
     *
     * Percentiles need the per-bucket histogram JSON, which no SQL function can sum, so the rows
     * have to come back and be merged in PHP. The totals beside them come from the grouped query
     * instead and stay exact however many rows this cap drops.
     */
    private const MAX_LATENCY_ROWS = 2000;

    /** Backstop on the chart read; the real bound is the number of buckets the window can hold. */
    private const MAX_TIMELINE_BUCKETS = 1500;

    /** Check keys folded into the "outbound calls that ARE recorded, elsewhere" block. */
    private const MAX_PROBE_GROUPS = 40;

    /** Rows read from the two shop settings tables, both of which are bounded by shipped features. */
    private const MAX_SETTING_ROWS = 400;

    /** The business_settings keys that say whether a non-gateway integration is switched on. */
    private const SETTING_KEYS = [
        'delivery_syria', 'firebase_otp_verification', 'push_notification_key', 'fcm_project_id',
        'map_api_status', 'recaptcha', 'mail_config',
    ];

    /**
     * Every outbound seam in this codebase, instrumented or not.
     *
     * Hand-maintained, and that is the point: it is the list of things that WOULD be measured, so it
     * cannot be derived from the measurements. Each entry names the file the call is made from and
     * the client it uses, because the client decides whether the one missing middleware would catch
     * it — Http:: calls would, raw curl_exec and vendor SDKs would not, and a page that said "not
     * instrumented" without that distinction would make thirteen jobs look like one.
     *
     * `service_keys` are the values a future recorder would plausibly write into the service column;
     * they are matched case-insensitively against what is actually in the table so an entry lights
     * up the moment somebody starts recording it, without this list being edited again.
     */
    private const CATALOGUE = [
        [
            'key' => 'payment_gateways',
            'name' => 'Payment gateways',
            'category' => 'payments',
            'detail' => '14 gateway controllers: Bkash, FlutterwaveV3, LiqPay, MercadoPago, Paymera, Paymob, PayPal, Paystack, PayTabs, Paytm, RazorPay, SenangPay, SslCommerz, Stripe. A fifteenth copy of the Paystack flow sits in app/Packages/PaystackGateway/NewPaystackController.php with two more curl_exec, unrouted and referenced by nothing, so it is not counted here.',
            'client' => 'Nine raw curl_exec (Bkash, FlutterwaveV3, LiqPay, Paymob, PayPal, Paystack, PayTabs, Paytm, SslCommerz), three vendor SDKs (Stripe, RazorPay, MercadoPago), Http::withBasicAuth in PaymeraController, and no outbound call at all in SenangPay',
            'seam' => 'app/Http/Controllers/Payment_Methods/*Controller.php (PaymeraController.php:88,129 is the one Http:: caller; SenangPayController.php only renders a signed redirect form)',
            'recorder' => 'Thirteen of the fourteen are invisible to an Http:: middleware, and not for one reason: the nine curl gateways need each curl_exec wrapped in a DependencyRecorder::measure(service, operation, callable) helper, and the three SDK gateways need that wrapper around the SDK call instead, which is a different edit in a different place. Twelve jobs after the middleware exists, not one — SenangPay needs nothing because it never calls out.',
            'service_keys' => ['stripe', 'paypal', 'razorpay', 'razor_pay', 'sslcommerz', 'ssl_commerz', 'paystack', 'flutterwave', 'mercadopago', 'paytabs', 'paytm', 'paymob', 'paymera', 'senangpay', 'senang_pay', 'bkash', 'liqpay', 'payment'],
            'settings' => ['table' => 'addon_settings', 'type' => 'payment_config'],
        ],
        [
            'key' => 'sslcommerz_library',
            'name' => 'SslCommerz (bundled library path)',
            'category' => 'payments',
            'detail' => 'A second, older code path to the same gateway, separate from the controller above. Nothing outside these files references App\\Library\\sslcommerz, so no traffic is going missing here today — it is listed because the files ship and the first caller would be unrecorded.',
            'client' => 'raw curl_exec',
            'seam' => 'app/Library/sslcommerz/AbstractSslCommerz.php:47,66 and SslCommerzNotification.php:60,73',
            'recorder' => 'Same wrapper as the curl gateways. Listed separately because porting SslCommerzPaymentController would not cover it.',
            'service_keys' => ['sslcommerz', 'ssl_commerz'],
            'settings' => ['table' => 'addon_settings', 'type' => 'payment_config', 'key' => 'ssl_commerz'],
        ],
        [
            'key' => 'delivery_syria',
            'name' => 'DeliverySyria courier',
            'category' => 'shipping',
            'detail' => 'Rate sync, parcel creation and parcel listing. Fails soft: the client returns ["success" => false] and the caller carries on, so a courier outage leaves no trace beyond a missing delivery_syria_parcels row.',
            'client' => 'Http:: (timeout 20s, connect timeout 10s)',
            'seam' => 'app/Services/DeliverySyria/DeliverySyriaClient.php:51,155',
            'recorder' => 'Caught by one Http::globalMiddleware with no change to this class.',
            'service_keys' => ['deliverysyria', 'delivery_syria', 'courier', 'shipping'],
            'settings' => ['table' => 'business_settings', 'key' => 'delivery_syria', 'flag' => 'status'],
        ],
        [
            'key' => 'sms',
            'name' => 'SMS providers',
            'category' => 'messaging',
            'detail' => 'Fourteen providers have an addon_settings row. app/Traits/SmsGateway.php implements all fourteen and app/Utils/SMSModule.php reimplements the first six — Twilio, Nexmo, 2Factor, MSG91, Releans, AlphanetSMS. Every one returns the literal string "error" or "not_found" on failure and persists nothing.',
            'client' => 'Twilio SDK (new Client), raw curl_exec for twelve providers, Guzzle for the 019_sms token fetch, and Http::get for global_sms alone',
            'seam' => 'app/Traits/SmsGateway.php:99 (Twilio SDK), :418 (Guzzle), :488 (Http::get) and twelve curl_exec sites; app/Utils/SMSModule.php:62, :95, :128, :161, :198, :227 duplicates six of them',
            'recorder' => 'Two send() methods reach these providers and different callers reach different ones, so wrapping one leaves the other blind. Only global_sms goes through Laravel\'s HTTP client; the Twilio SDK, the Guzzle client and every curl_exec miss the middleware.',
            'service_keys' => ['sms', 'twilio', 'nexmo', 'vonage', 'msg91', 'releans', 'alphanet'],
            'settings' => ['table' => 'addon_settings', 'type' => 'sms_config'],
        ],
        [
            'key' => 'firebase_identity',
            'name' => 'Firebase Identity Toolkit (phone OTP)',
            'category' => 'auth',
            'detail' => 'Sends and verifies the phone verification code used by customer and seller sign-in.',
            'client' => 'Http::post',
            'seam' => 'app/Services/FirebaseService.php:21,41',
            'recorder' => 'Caught by one Http::globalMiddleware.',
            'service_keys' => ['firebase', 'identitytoolkit', 'firebase_auth'],
            'settings' => ['table' => 'business_settings', 'key' => 'firebase_otp_verification'],
        ],
        [
            'key' => 'fcm_push',
            'name' => 'Firebase Cloud Messaging (push)',
            'category' => 'messaging',
            'detail' => 'Every order, chat and status push, plus the OAuth token exchange that authorises them. A second, legacy path posts to the deprecated fcm/send endpoint for three delivery-man notifications.',
            'client' => 'Http::withHeaders / Http::asForm in the trait; raw curl_exec in the legacy helper',
            'seam' => 'app/Traits/PushNotificationTrait.php:495 (send) and :523 (token); app/Utils/Helpers.php:500 (legacy fcm/send, called from three delivery-man controllers)',
            'recorder' => 'The trait is caught by one Http::globalMiddleware; the legacy helper is not and needs the curl wrapper. Worth its own service key rather than folding into "google", because a token failure and a send failure are different outages.',
            'service_keys' => ['fcm', 'push'],
            'settings' => ['table' => 'business_settings', 'key' => 'push_notification_key'],
        ],
        [
            'key' => 'google_maps',
            'name' => 'Google Maps (places, distance, geocode)',
            'category' => 'maps',
            'detail' => 'Address autocomplete, place details, delivery distance and reverse geocoding — on the checkout path, so its latency is customer-visible.',
            'client' => 'Http::',
            'seam' => 'app/Http/Controllers/RestAPI/v1/MapApiController.php:24,70,94,115 and v2/delivery_man/DeliveryManController.php:705',
            'recorder' => 'Caught by one Http::globalMiddleware; operation should come from the normalised path so places, distancematrix and geocode stay separable.',
            'service_keys' => ['maps', 'google_maps', 'googlemaps', 'places', 'geocode'],
            'settings' => ['table' => 'business_settings', 'key' => 'map_api_status'],
        ],
        [
            'key' => 'recaptcha',
            'name' => 'Google reCAPTCHA',
            'category' => 'auth',
            'detail' => 'Verifies the token on registration and login. A failure here locks customers out rather than letting bots in.',
            'client' => 'Http::asForm',
            'seam' => 'app/Services/RecaptchaService.php:15',
            'recorder' => 'Caught by one Http::globalMiddleware.',
            'service_keys' => ['recaptcha', 'captcha'],
            'settings' => ['table' => 'business_settings', 'key' => 'recaptcha'],
        ],
        [
            'key' => 'mail',
            'name' => 'Outbound mail (SMTP)',
            'category' => 'messaging',
            'detail' => 'Order confirmations, verification mails, seller notifications. Sent through the mailer, not through an HTTP client.',
            'client' => 'Mail::to()->send()',
            'seam' => 'app/Services/MailService.php:51 and every Mail:: call site',
            'recorder' => 'Invisible to an HTTP middleware. Listen on Illuminate\\Mail\\Events\\MessageSending / MessageSent and record the pair as one dependency call.',
            'service_keys' => ['mail', 'smtp', 'email'],
            'settings' => ['table' => 'business_settings', 'key' => 'mail_config', 'flag' => 'status'],
        ],
        [
            'key' => 'social_login',
            'name' => 'Apple and social sign-in',
            'category' => 'auth',
            'detail' => 'Apple token exchange plus the provider profile fetches behind social login.',
            'client' => 'Guzzle (new Client) and Http::asForm',
            'seam' => 'app/Http/Controllers/RestAPI/v1/auth/SocialAuthController.php:44,77,228,263',
            'recorder' => 'The Http:: half is caught by the middleware; the two `new Client()` call sites are not and need porting or wrapping.',
            'service_keys' => ['apple', 'social', 'oauth'],
            'settings' => null,
        ],
        [
            'key' => 'ai_providers',
            'name' => 'AI providers',
            'category' => 'ai',
            'detail' => 'OpenAI is called through the openai-php SDK. ClaudeProvider::generate() has an empty body and makes no call at all, so "no AI traffic" is the correct reading for it rather than a gap.',
            'client' => 'openai-php SDK (OpenAI), none (Claude)',
            'seam' => 'Modules/AI/AIProviders/OpenAIProvider.php:28 and ClaudeProvider.php:12',
            'recorder' => 'The SDK does not use Laravel\'s HTTP client. Wrap AIProviderManager\'s call to generate() so both providers are measured at one seam.',
            'service_keys' => ['openai', 'anthropic', 'claude', 'ai'],
            'settings' => null,
        ],
        [
            'key' => 'licence_check',
            'name' => '6amTech licence activation check',
            'category' => 'platform',
            'detail' => 'Called when an add-on is activated. Rare, but it blocks the admin request while it runs.',
            'client' => 'Http::post',
            'seam' => 'app/Services/AddonService.php:151',
            'recorder' => 'Caught by one Http::globalMiddleware.',
            'service_keys' => ['6amtech', 'licence', 'license', 'activation'],
            'settings' => null,
        ],
        [
            'key' => 'remote_files',
            'name' => 'Remote file fetch',
            'category' => 'platform',
            'detail' => 'A header-only probe for the size of a file held on another host, from the file manager. It never downloads the body.',
            'client' => 'raw curl_exec',
            'seam' => 'app/Utils/FileManagerLogic.php:23',
            'recorder' => 'Not caught by the middleware, and the lowest priority in this catalogue: one call site, on neither the checkout path nor a customer-visible one.',
            'service_keys' => ['remote_file', 'files'],
            'settings' => null,
        ],
    ];

    /**
     * The fold seam per resolution, resolved once.
     *
     * Four reads on this page cross the same seam, and asking the table for it four times is three
     * index lookups nobody needs on a page an admin can refresh as fast as they like.
     *
     * @var array<string, \Illuminate\Support\Carbon>
     */
    private array $seams = [];

    public function __construct(
        private readonly SeriesReader $reader,
        private readonly Redactor $redactor,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $recorder = $this->recorder();
        $totals = $this->totals($range, $window);
        $latency = $this->latency($range, $window);
        $services = $this->services($range, $window, $totals, $latency, $recorder);
        $filters = $this->filters($request, $services);
        $catalogue = $this->catalogue($services);

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'recorder' => $recorder,
            'headline' => $this->headline($services, $latency, $recorder, $catalogue),
            'services' => $services,
            'filters' => $filters,
            'operations' => $this->operations($range, $window, $filters),
            'timeline' => $this->timeline($range, $window),
            'catalogue' => $catalogue,
            'probes' => $this->probes($range),
            // The one outbound integration this shop OWNS rather than merely calls, and the only
            // one with a retry ledger behind it. It had no panel, no check, no series and no rule
            // anywhere under app/Services/Monitoring — so a seller whose ERP had been unreachable
            // for a day, and whose endpoint the platform had then switched off, was visible only if
            // an admin happened to open the seller operations page.
            'seller_webhooks' => $this->sellerWebhooks(),
            'unmeasured' => $this->unmeasured(),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Is anything recording outbound calls at all

    /**
     * Whether a dependency bucket can be written on this deployment.
     *
     * Every count below is a true reading of a table and every one of them is meaningless as a
     * statement about the integrations while this block is not ok: zero calls is then a fact about
     * the recorder. It is separated from the pipeline reading underneath it on purpose — "monitoring
     * is not running" and "monitoring is running and nobody feeds it outbound calls" send an
     * operator to two different files, and only the second is the case on this build.
     *
     * @return array<string, mixed>
     */
    private function recorder(): array
    {
        $base = [
            'source' => self::SOURCE,
            'writer' => self::WRITER,
            'producer_seam' => self::PRODUCER_SEAM,
            // The one-line form of `note`, for the cards. The banner carries the full reason once;
            // repeating three sentences under eight readings turns one fault into eight.
            'short_note' => null,
            'ever_recorded' => null,
            'newest_bucket_at' => null,
            'newest_bucket_age_seconds' => null,
            'resolutions' => [],
            'pipeline' => $this->pipeline(),
        ];

        if (!config('monitoring.enabled', true)) {
            return array_merge($base, [
                'state' => 'not_configured',
                'short_note' => 'Monitoring collection is switched off — see the banner above.',
                'note' => 'Monitoring collection is switched off, so nothing is buffered and nothing is flushed. Every empty reading on this page is a consequence of that setting.',
                'remedy' => 'Set MONITORING_ENABLED=true in .env, then run `php artisan optimize:clear`.',
            ]);
        }

        try {
            // Rides monitoring_dependency_unique (resolution, bucket_at, service, operation): the
            // group is the leading column and the aggregate is the second, so this is an index read
            // of at most three rows however large the table gets.
            $rows = $this->reader->connection()->table(self::SOURCE)
                ->groupBy('resolution')
                ->limit(count(self::RESOLUTIONS) + 1)
                ->get(['resolution', $this->reader->connection()->raw('MAX(bucket_at) AS newest')]);
        } catch (\Throwable $exception) {
            // Caught here rather than left to PanelRegistry: losing this read costs the banner,
            // while letting it escape would blank a catalogue that reads perfectly well without it.
            return array_merge($base, [
                'state' => 'failed',
                'short_note' => 'Whether anything records outbound calls could not be read — see the banner above.',
                'note' => $this->failureNote($exception),
                'remedy' => 'The dependency table is created by `php artisan migrate`. Check the monitoring connection is reachable and migrated.',
            ]);
        }

        $newest = null;
        $resolutions = [];
        foreach ($rows as $row) {
            $resolution = (string) $row->resolution;
            $resolutions[$resolution] = $this->displayStamp($row->newest);
            $newest = $this->later($newest, $row->newest);
        }

        $base = array_merge($base, [
            'ever_recorded' => $newest !== null,
            'newest_bucket_at' => $this->displayStamp($newest),
            'newest_bucket_age_seconds' => $this->secondsSince($newest),
            'resolutions' => $resolutions,
        ]);

        if ($newest === null) {
            return array_merge($base, [
                'state' => 'not_configured',
                'short_note' => 'Nothing on this deployment records outbound calls — see the banner above.',
                'note' => 'No outbound call has ever been recorded. BucketWriter::DEPENDENCY_PREFIX is referenced only inside BucketWriter itself — no middleware, listener or client wrapper increments it — so this table cannot fill however much the shop calls out. Nothing on this page says the integrations are healthy; it says none of them is watched.',
                'remedy' => self::PRODUCER_REMEDY,
            ]);
        }

        $age = $base['newest_bucket_age_seconds'];
        $stale = max(300, (int) config('monitoring.flush_interval_seconds', 60) * 5);

        if ($age !== null && $age > $stale) {
            return array_merge($base, [
                'state' => 'collector_offline',
                'short_note' => 'Outbound call recording has stopped — see the banner above.',
                'note' => 'Outbound calls were recorded once but the newest bucket is ' . $age . ' seconds old. While the recorder is stopped a silent service and a healthy one look identical here.',
                'remedy' => 'The buffer is drained by `php artisan monitoring:flush` from the scheduler. Check cron is calling `php artisan schedule:run`.',
            ]);
        }

        return array_merge($base, ['state' => 'ok', 'short_note' => null, 'note' => null, 'remedy' => null]);
    }

    /**
     * Whether the ingest pipeline that WOULD carry dependency buckets is alive.
     *
     * Read from the request buckets, which share the buffer, the flush command and the writer. When
     * requests are landing and dependencies are not, the fault is precisely one missing producer and
     * not a broken monitoring install — which is the difference between editing one provider and
     * debugging a queue.
     *
     * @return array<string, mixed>
     */
    private function pipeline(): array
    {
        try {
            // Rides monitoring_request_bucket_window (resolution, bucket_at).
            $newest = $this->reader->connection()->table(self::REQUEST_SOURCE)
                ->where('resolution', 'minute')
                ->max('bucket_at');
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'source' => self::REQUEST_SOURCE,
                'newest_bucket_at' => null,
                'age_seconds' => null,
            ];
        }

        $age = $this->secondsSince($newest);
        $stale = max(300, (int) config('monitoring.flush_interval_seconds', 60) * 5);

        return [
            'state' => match (true) {
                $newest === null => 'no_data',
                $age !== null && $age > $stale => 'collector_offline',
                default => 'ok',
            },
            'note' => match (true) {
                $newest === null => 'No request bucket has ever been written either, so the ingest pipeline itself has never run and the empty dependency table is not evidence of a missing producer.',
                $age !== null && $age > $stale => 'The ingest pipeline last wrote a request bucket ' . $age . ' seconds ago, so it is stopped for everything and not only for dependencies.',
                default => 'The ingest pipeline is writing request buckets, so the buffer, the flush command and the writer all work. Only the dependency producer is missing.',
            },
            'source' => self::REQUEST_SOURCE,
            'newest_bucket_at' => $this->displayStamp($newest),
            'age_seconds' => $age,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The numbers above the table

    /**
     * The readings drawn as cards.
     *
     * A measured zero is printed as one, but only while the recorder is ok. With no producer behind
     * the table, "0 failed calls" would be the most reassuring lie on the page — so those readings
     * become no_data carrying the recorder's own reason, and the banner above states it once.
     *
     * @param  array<string, mixed>  $services
     * @param  array<string, mixed>  $latency
     * @param  array<string, mixed>  $recorder
     * @param  array<string, mixed>  $catalogue
     * @return array<string, Metric>
     */
    private function headline(array $services, array $latency, array $recorder, array $catalogue): array
    {
        if ($services['state'] === 'failed') {
            return [];
        }

        $measured = $recorder['state'] === 'ok';
        $blind = $recorder['short_note'];
        $totals = $services['totals'];

        $reading = static function (?int $value, ?string $unit = null, ?string $note = null) use ($measured, $blind): Metric {
            return $measured
                ? Metric::of(value: $value, source: self::SOURCE, unit: $unit, note: $note)
                : Metric::noData(source: self::SOURCE, note: $blind);
        };

        $headline = [
            'services_calling_out' => $reading(
                $totals['services'],
                null,
                $totals['services'] === 0 ? 'No service recorded a call in this window.' : null,
            ),
            'outbound_calls' => $reading($totals['calls']),
            'failed_calls' => $reading($totals['failures']),
        ];

        // A rate over no calls is not a healthy 0% — it is the absence of a denominator.
        $headline['failure_rate'] = $measured && $totals['failure_rate'] !== null
            ? Metric::of(value: $totals['failure_rate'], source: self::SOURCE, unit: '%')
            : Metric::noData(
                source: self::SOURCE,
                note: $measured
                    ? 'No outbound call was recorded in this window, so there is no denominator for a failure rate.'
                    : $blind,
            );

        $headline['average_latency'] = $measured && $totals['avg_ms'] !== null
            ? Metric::of(value: $totals['avg_ms'], source: self::SOURCE . ' (duration_sum_ms / calls)', unit: 'ms')
            : Metric::noData(source: self::SOURCE, note: $measured ? 'No call was recorded, so nothing has a duration.' : $blind);

        $headline['p95_latency'] = $measured && $latency['state'] === 'ok' && $latency['totals']['p95'] !== null
            ? Metric::of(
                value: $latency['totals']['p95'],
                source: self::SOURCE . '.duration_buckets',
                unit: 'ms',
                note: $latency['truncated']
                    ? 'Interpolated over the newest ' . self::MAX_LATENCY_ROWS . ' buckets, which is fewer than this window holds.'
                    : null,
            )
            : Metric::noData(
                source: self::SOURCE . '.duration_buckets',
                note: $measured ? ($latency['note'] ?? 'No latency histogram was recorded in this window.') : $blind,
            );

        // The reading this page exists for. A service that stopped being called looks exactly like a
        // service with no failures, and only this number tells them apart.
        $headline['services_that_stopped_calling'] = $measured && $services['comparison']['state'] === 'ok'
            ? Metric::of(
                value: $services['comparison']['went_silent'],
                source: self::SOURCE,
                unit: null,
                note: 'Recorded calls in the previous window of the same length and none in this one.',
            )
            : Metric::noData(
                source: self::SOURCE,
                note: $measured ? ($services['comparison']['note'] ?? null) : $blind,
            );

        $headline['services_with_no_successful_call'] = $measured
            ? Metric::of(
                value: $totals['without_success'],
                source: self::SOURCE,
                unit: null,
                note: 'Services where every recorded call in this window failed.',
            )
            : Metric::noData(source: self::SOURCE, note: $blind);

        // Not a reading of the table — a reading of the codebase against it. It stays a real number
        // whatever the recorder is doing, because the seams exist whether or not anything watches
        // them, and it is the count this page was built to stop anybody forgetting.
        $headline['dependencies_not_instrumented'] = Metric::of(
            value: $catalogue['totals']['unmeasured'],
            source: 'IntegrationsPanel::CATALOGUE vs ' . self::SOURCE,
            unit: null,
            note: 'Outbound seams in this codebase with no matching row in the dependency table for this window, out of '
                . $catalogue['totals']['dependencies'] . ' known.',
        );

        return $headline;
    }

    // -------------------------------------------------------------------------------------------
    // Per service

    /**
     * Exact per-service totals for the window.
     *
     * Grouped in SQL so the totals stay exact whatever the latency read drops, and split across the
     * fold seam the same way every other bucketed read on this dashboard is: coarse buckets strictly
     * before the newest folded parent, minute buckets from it onward. Without that a 24h window
     * would be short by up to fifty-six minutes and the headline would contradict the table under it.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function totals(string $range, array $window): array
    {
        try {
            $connection = $this->reader->connection();
            $from = $this->reader->since($range);
            $seam = $window['resolution'] === 'minute' ? null : $this->seam($window['resolution'], $from);

            $columns = [
                'service',
                $connection->raw('SUM(calls) AS calls'),
                $connection->raw('SUM(failures) AS failures'),
                $connection->raw('SUM(timeouts) AS timeouts'),
                $connection->raw('SUM(client_errors) AS client_errors'),
                $connection->raw('SUM(server_errors) AS server_errors'),
                $connection->raw('SUM(rate_limited) AS rate_limited'),
                $connection->raw('SUM(duration_sum_ms) AS duration_sum_ms'),
                $connection->raw('MAX(duration_max_ms) AS duration_max_ms'),
                $connection->raw('COUNT(DISTINCT operation) AS operations'),
                $connection->raw('MAX(last_success_at) AS last_success_at'),
                $connection->raw('MAX(last_failure_at) AS last_failure_at'),
                // The substitute for the column nothing writes: the newest bucket in which at least
                // one call did not fail. Bucket-granular, and labelled as such everywhere it is
                // drawn — it is the start of a minute, an hour or a day, not the instant of a call.
                $connection->raw('MAX(CASE WHEN calls > failures THEN bucket_at END) AS last_ok_bucket_at'),
                $connection->raw('MAX(CASE WHEN failures > 0 THEN bucket_at END) AS last_bad_bucket_at'),
            ];

            // Rides monitoring_dependency_unique (resolution, bucket_at, ...): resolution is an
            // equality on the leading column and bucket_at is a range on the second.
            $rows = $connection->table(self::SOURCE)
                ->where('resolution', $window['resolution'])
                ->where('bucket_at', '>=', $from)
                ->when($seam !== null, fn ($query) => $query->where('bucket_at', '<', $seam))
                ->groupBy('service')
                ->limit(self::MAX_SERVICES + 1)
                ->get($columns);

            if ($seam !== null) {
                $rows = $rows->concat($connection->table(self::SOURCE)
                    ->where('resolution', 'minute')
                    ->where('bucket_at', '>=', $seam->greaterThan($from) ? $seam : $from)
                    ->groupBy('service')
                    ->limit(self::MAX_SERVICES + 1)
                    ->get($columns));
            }
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => 'The dependency table is created by `php artisan migrate`. Check the monitoring connection is reachable and migrated.',
                'by_service' => [],
                'truncated' => false,
            ];
        }

        $counters = [];
        foreach ($rows as $row) {
            $service = mb_substr((string) $row->service, 0, 64);
            $counters[$service] ??= [
                'calls' => 0, 'failures' => 0, 'timeouts' => 0, 'client_errors' => 0,
                'server_errors' => 0, 'rate_limited' => 0, 'duration_sum_ms' => 0,
                'duration_max_ms' => null, 'operations' => 0,
                'last_success_at' => null, 'last_failure_at' => null,
                'last_ok_bucket_at' => null, 'last_bad_bucket_at' => null,
            ];

            foreach (['calls', 'failures', 'timeouts', 'client_errors', 'server_errors', 'rate_limited', 'duration_sum_ms'] as $field) {
                $counters[$service][$field] += (int) ($row->{$field} ?? 0);
            }

            // The two halves of the seam can each see the same operation, so the distinct counts are
            // taken as the larger of the two rather than added: summing them would double it.
            $counters[$service]['operations'] = max($counters[$service]['operations'], (int) ($row->operations ?? 0));
            $counters[$service]['duration_max_ms'] = $this->larger($counters[$service]['duration_max_ms'], $row->duration_max_ms);

            foreach (['last_success_at', 'last_failure_at', 'last_ok_bucket_at', 'last_bad_bucket_at'] as $stamp) {
                $counters[$service][$stamp] = $this->later($counters[$service][$stamp], $row->{$stamp} ?? null);
            }
        }

        $truncated = count($counters) > self::MAX_SERVICES;

        return [
            'state' => $counters === [] ? 'no_data' : 'ok',
            'note' => $counters === [] ? 'No outbound call was recorded in this window.' : null,
            'remedy' => null,
            'by_service' => array_slice($counters, 0, self::MAX_SERVICES, preserve_keys: true),
            'truncated' => $truncated,
        ];
    }

    /**
     * The services that were being called one window ago.
     *
     * The whole point of the page in one query. Silence is the failure mode nothing else on this
     * dashboard can see: a gateway whose calls stopped has zero failures out of zero calls, which
     * every rate on this page renders as perfect health.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function previous(string $range, array $window): array
    {
        try {
            [$start, $end] = $this->reader->previousWindow($range);

            // The previous window ends where this one begins, so it lies entirely behind the fold
            // seam and the folded rows cover it in full — no minute tail is needed here.
            $rows = $this->reader->connection()->table(self::SOURCE)
                ->where('resolution', $window['resolution'])
                ->where('bucket_at', '>=', $start)
                ->where('bucket_at', '<', $end)
                ->groupBy('service')
                ->limit(self::MAX_SERVICES + 1)
                ->get(['service', $this->reader->connection()->raw('SUM(calls) AS calls')]);
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'by_service' => [],
                'truncated' => false,
            ];
        }

        $calls = [];
        foreach ($rows->take(self::MAX_SERVICES) as $row) {
            $calls[mb_substr((string) $row->service, 0, 64)] = (int) $row->calls;
        }

        return [
            'state' => 'ok',
            'note' => null,
            'by_service' => $calls,
            'truncated' => $rows->count() > self::MAX_SERVICES,
        ];
    }

    /**
     * One row per service: volume, failures, latency and the last time it worked.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>  $latency
     * @param  array<string, mixed>  $recorder
     * @return array<string, mixed>
     */
    private function services(string $range, array $window, array $totals, array $latency, array $recorder): array
    {
        $empty = [
            'rows' => [],
            'silent' => [],
            'truncated' => false,
            'limit' => self::MAX_SERVICES,
            'source' => self::SOURCE,
            'resolution' => $window['resolution'],
            'columns' => $this->unwrittenColumns(),
            'comparison' => ['state' => 'no_data', 'note' => null, 'went_silent' => null, 'truncated' => false],
            'totals' => [
                'services' => 0, 'calls' => 0, 'failures' => 0, 'failure_rate' => null,
                'avg_ms' => null, 'without_success' => 0,
            ],
        ];

        if ($totals['state'] === 'failed') {
            return array_merge($empty, [
                'state' => 'failed',
                'note' => $totals['note'],
                'remedy' => $totals['remedy'],
                'totals' => [
                    // Null, not zero: the read did not find nothing, it did not look.
                    'services' => null, 'calls' => null, 'failures' => null, 'failure_rate' => null,
                    'avg_ms' => null, 'without_success' => null,
                ],
            ]);
        }

        if ($totals['by_service'] === []) {
            return array_merge($empty, [
                'state' => 'no_data',
                'note' => $recorder['state'] === 'ok'
                    ? 'No outbound call was recorded in this window. Nothing here says the integrations are idle — it says none of them was measured.'
                    : 'No outbound call was recorded in this window, and nothing on this deployment can record one.',
                'remedy' => $recorder['state'] === 'ok' ? null : self::PRODUCER_REMEDY,
            ]);
        }

        $previous = $this->previous($range, $window);
        $rows = [];
        $sumCalls = 0;
        $sumFailures = 0;
        $sumDuration = 0;
        $withoutSuccess = 0;

        foreach ($totals['by_service'] as $service => $counter) {
            $calls = $counter['calls'];
            $failures = $counter['failures'];
            $sumCalls += $calls;
            $sumFailures += $failures;
            $sumDuration += $counter['duration_sum_ms'];

            if ($calls > 0 && $failures >= $calls) {
                $withoutSuccess++;
            }

            $distribution = $latency['by_service'][$service] ?? null;

            $rows[] = [
                'service' => $service,
                'calls' => $calls,
                'failures' => $failures,
                'timeouts' => $counter['timeouts'],
                'client_errors' => $counter['client_errors'],
                'server_errors' => $counter['server_errors'],
                'rate_limited' => $counter['rate_limited'],
                'operations' => $counter['operations'],
                // Zero calls cannot produce a rate. Null says so; 0 would say "nothing failed".
                'failure_rate' => $calls > 0 ? round(100 * $failures / $calls, 3) : null,
                'success_rate' => $calls > 0 ? round(100 * ($calls - $failures) / $calls, 3) : null,
                'avg_ms' => $calls > 0 ? round($counter['duration_sum_ms'] / $calls, 1) : null,
                'p95_ms' => $distribution['p95'] ?? null,
                'p50_ms' => $distribution['p50'] ?? null,
                'max_ms' => $counter['duration_max_ms'],
                'latency_state' => $latency['state'] === 'ok'
                    ? ($distribution === null ? 'no_data' : 'ok')
                    : $latency['state'],
                // The column, read even though nothing writes it: a value put there by a later
                // writer must appear rather than be hidden by a claim made about today's code.
                'last_success_at' => $this->displayStamp($counter['last_success_at']),
                'last_failure_at' => $this->displayStamp($counter['last_failure_at']),
                // The derived substitute, at bucket resolution.
                'last_ok_bucket_at' => $this->displayStamp($counter['last_ok_bucket_at']),
                'last_ok_bucket_age_seconds' => $this->secondsSince($counter['last_ok_bucket_at']),
                'last_bad_bucket_at' => $this->displayStamp($counter['last_bad_bucket_at']),
                'calls_previous' => $previous['state'] === 'ok'
                    ? ($previous['by_service'][$service] ?? 0)
                    : null,
            ];
        }

        // Silence is the finding, so services that went quiet are listed even though they have no
        // row in this window: dropping them would make the page agree with the outage.
        $silent = [];
        if ($previous['state'] === 'ok') {
            foreach ($previous['by_service'] as $service => $calls) {
                if ($calls > 0 && !isset($totals['by_service'][$service])) {
                    $silent[] = ['service' => $service, 'calls_previous' => $calls];
                }
            }
        }

        // Worst first: a service with no successful call at the top, then by failure rate, then by
        // volume. A table sorted by call count puts the busiest healthy service above the broken one.
        usort($rows, static fn (array $a, array $b) => [-($a['failure_rate'] ?? -1), -$a['calls'], $a['service']]
            <=> [-($b['failure_rate'] ?? -1), -$b['calls'], $b['service']]);

        return array_merge($empty, [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'rows' => $rows,
            'silent' => $silent,
            'truncated' => $totals['truncated'],
            'comparison' => [
                'state' => $previous['state'],
                'note' => $previous['state'] === 'ok'
                    ? null
                    : ($previous['note'] ?? 'The previous window could not be read, so no service can be called silent.'),
                'went_silent' => $previous['state'] === 'ok' ? count($silent) : null,
                'truncated' => $previous['truncated'],
                'source' => self::SOURCE,
            ],
            'totals' => [
                'services' => count($rows),
                'calls' => $sumCalls,
                'failures' => $sumFailures,
                'failure_rate' => $sumCalls > 0 ? round(100 * $sumFailures / $sumCalls, 3) : null,
                'avg_ms' => $sumCalls > 0 ? round($sumDuration / $sumCalls, 1) : null,
                'without_success' => $withoutSuccess,
            ],
        ]);
    }

    /**
     * The three columns the writer never sets, stated once for the whole table.
     *
     * Hoisted out of the rows on the "say one fault once" rule: three empty cells on every service
     * row is one gap repeated, and repeating it turns a single missing line in BucketWriter into a
     * table that looks broken in thirty places.
     *
     * @return array<string, array<string, string|null>>
     */
    private function unwrittenColumns(): array
    {
        $gap = [
            'state' => 'not_configured',
            'note' => 'Written by nothing. ' . self::WRITER . ' builds its row without these three columns, and the rollup can only carry forward what the minute rows hold — so they would stay empty even after a recorder existed. The last-success column in the table is derived from the buckets that do exist instead, and is accurate to the window resolution rather than to the call.',
            'remedy' => 'Add the three columns to the row built in ' . self::WRITER . ', set from the recorder that ' . self::PRODUCER_SEAM . ' would register.',
        ];

        // The same gap under each of the three names rather than one entry that hardcodes the other
        // two in the view: the view groups identical reasons into one paragraph, so this stays one
        // fault said once while each column still says for itself that it is empty on purpose.
        return [
            'last_success_at' => $gap,
            'last_failure_at' => $gap,
            'last_error' => $gap,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Latency

    /**
     * The latency distribution, folded from the stored histograms.
     *
     * Read separately from the totals because percentiles cannot be summed in SQL: the per-bucket
     * histogram JSON has to come back and be merged in PHP. The cap is on rows rather than on
     * services, and it is declared — a p95 over a partial read is still a real p95 of what it read,
     * but only if the page says which rows those were.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function latency(string $range, array $window): array
    {
        try {
            $connection = $this->reader->connection();
            $from = $this->reader->since($range);
            $seam = $window['resolution'] === 'minute' ? null : $this->seam($window['resolution'], $from);

            $columns = ['service', 'calls', 'duration_buckets', 'duration_sum_ms', 'duration_max_ms'];

            // Rides monitoring_dependency_unique (resolution, bucket_at, ...). Newest first, because
            // a partial read of the recent past is the useful half of a truncated distribution.
            $rows = $connection->table(self::SOURCE)
                ->where('resolution', $window['resolution'])
                ->where('bucket_at', '>=', $from)
                ->when($seam !== null, fn ($query) => $query->where('bucket_at', '<', $seam))
                ->orderByDesc('bucket_at')
                ->limit(self::MAX_LATENCY_ROWS + 1)
                ->get($columns);

            if ($seam !== null && $rows->count() <= self::MAX_LATENCY_ROWS) {
                $rows = $rows->concat($connection->table(self::SOURCE)
                    ->where('resolution', 'minute')
                    ->where('bucket_at', '>=', $seam->greaterThan($from) ? $seam : $from)
                    ->orderByDesc('bucket_at')
                    ->limit(self::MAX_LATENCY_ROWS + 1 - $rows->count())
                    ->get($columns));
            }
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'source' => self::SOURCE . '.duration_buckets',
                'by_service' => [],
                'totals' => $this->emptyPercentiles(),
                'truncated' => false,
                'limit' => self::MAX_LATENCY_ROWS,
            ];
        }

        $truncated = $rows->count() > self::MAX_LATENCY_ROWS;
        $overall = new Histogram();
        $perService = [];

        foreach ($rows->take(self::MAX_LATENCY_ROWS) as $row) {
            $calls = (int) $row->calls;
            if ($calls <= 0) {
                continue;
            }

            $counts = json_decode((string) ($row->duration_buckets ?? ''), true);
            $histogram = Histogram::fromState(
                is_array($counts) ? $counts : [],
                $calls,
                (float) ($row->duration_sum_ms ?? 0),
                null,
                $row->duration_max_ms !== null ? (float) $row->duration_max_ms : null,
            );

            $service = mb_substr((string) $row->service, 0, 64);
            $perService[$service] ??= new Histogram();
            $perService[$service]->merge($histogram);
            $overall->merge($histogram);
        }

        if ($overall->count() === 0) {
            return [
                'state' => 'no_data',
                'note' => 'No bucket in this window carries a latency histogram, so no percentile can be interpolated.',
                'source' => self::SOURCE . '.duration_buckets',
                'by_service' => [],
                'totals' => $this->emptyPercentiles(),
                'truncated' => $truncated,
                'limit' => self::MAX_LATENCY_ROWS,
            ];
        }

        $byService = [];
        foreach ($perService as $service => $histogram) {
            $byService[$service] = $histogram->standardPercentiles();
        }

        return [
            'state' => 'ok',
            'note' => null,
            'source' => self::SOURCE . '.duration_buckets',
            'by_service' => $byService,
            'totals' => $overall->standardPercentiles(),
            'truncated' => $truncated,
            'limit' => self::MAX_LATENCY_ROWS,
        ];
    }

    /** @return array<string, float|int|null> */
    private function emptyPercentiles(): array
    {
        // Nulls, not zeros: no observation and an observation of zero milliseconds are different
        // facts, and this dashboard renders them differently.
        return ['p50' => null, 'p75' => null, 'p90' => null, 'p95' => null, 'p99' => null, 'max' => null, 'avg' => null, 'count' => 0];
    }

    // -------------------------------------------------------------------------------------------
    // Per operation

    /**
     * Service and operation together — which call it is, not just who it is to.
     *
     * "Stripe is failing" and "Stripe's refund endpoint is failing while charges are fine" are
     * different pages to page somebody about, and the operation column is already stored.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function operations(string $range, array $window, array $filters): array
    {
        $base = [
            'source' => self::SOURCE,
            'rows' => [],
            'truncated' => false,
            'limit' => self::MAX_OPERATIONS,
        ];

        try {
            $connection = $this->reader->connection();
            $from = $this->reader->since($range);
            $seam = $window['resolution'] === 'minute' ? null : $this->seam($window['resolution'], $from);

            $columns = [
                'service',
                'operation',
                $connection->raw('SUM(calls) AS calls'),
                $connection->raw('SUM(failures) AS failures'),
                $connection->raw('SUM(timeouts) AS timeouts'),
                $connection->raw('SUM(rate_limited) AS rate_limited'),
                $connection->raw('SUM(duration_sum_ms) AS duration_sum_ms'),
                $connection->raw('MAX(duration_max_ms) AS duration_max_ms'),
            ];

            // Rides monitoring_dependency_unique (resolution, bucket_at, service, operation).
            $query = fn (string $resolution) => $connection->table(self::SOURCE)
                ->where('resolution', $resolution)
                ->when(
                    $filters['service'] !== 'all',
                    fn ($builder) => $builder->where('service', $filters['service']),
                )
                ->groupBy('service', 'operation')
                ->limit(self::MAX_OPERATIONS + 1);

            $rows = $query($window['resolution'])
                ->where('bucket_at', '>=', $from)
                ->when($seam !== null, fn ($builder) => $builder->where('bucket_at', '<', $seam))
                ->get($columns);

            if ($seam !== null) {
                $rows = $rows->concat($query('minute')
                    ->where('bucket_at', '>=', $seam->greaterThan($from) ? $seam : $from)
                    ->get($columns));
            }
        } catch (\Throwable $exception) {
            return array_merge($base, [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => null,
            ]);
        }

        $counters = [];
        foreach ($rows as $row) {
            $key = mb_substr((string) $row->service, 0, 64) . "\0" . mb_substr((string) $row->operation, 0, 96);
            $counters[$key] ??= [
                'service' => mb_substr((string) $row->service, 0, 64),
                'operation' => mb_substr((string) $row->operation, 0, 96),
                'calls' => 0, 'failures' => 0, 'timeouts' => 0, 'rate_limited' => 0,
                'duration_sum_ms' => 0, 'duration_max_ms' => null,
            ];

            foreach (['calls', 'failures', 'timeouts', 'rate_limited', 'duration_sum_ms'] as $field) {
                $counters[$key][$field] += (int) ($row->{$field} ?? 0);
            }
            $counters[$key]['duration_max_ms'] = $this->larger($counters[$key]['duration_max_ms'], $row->duration_max_ms);
        }

        if ($counters === []) {
            return array_merge($base, [
                'state' => 'no_data',
                'note' => $filters['narrowed']
                    ? 'No call to the selected service was recorded in this window.'
                    : 'No outbound call was recorded in this window, so no operation has a row.',
                'remedy' => null,
            ]);
        }

        $truncated = count($counters) > self::MAX_OPERATIONS;
        $out = [];
        foreach (array_slice($counters, 0, self::MAX_OPERATIONS) as $counter) {
            $calls = $counter['calls'];
            $out[] = [
                'service' => $counter['service'],
                // The writer folds an unbounded operation tail into this literal, so it is a real
                // value with a real meaning rather than a name anybody chose.
                'operation' => $counter['operation'] === '' ? null : $counter['operation'],
                'folded' => $counter['operation'] === '__other__',
                'calls' => $calls,
                'failures' => $counter['failures'],
                'timeouts' => $counter['timeouts'],
                'rate_limited' => $counter['rate_limited'],
                'failure_rate' => $calls > 0 ? round(100 * $counter['failures'] / $calls, 3) : null,
                'avg_ms' => $calls > 0 ? round($counter['duration_sum_ms'] / $calls, 1) : null,
                'max_ms' => $counter['duration_max_ms'],
            ];
        }

        usort($out, static fn (array $a, array $b) => [-($a['failure_rate'] ?? -1), -$a['calls']]
            <=> [-($b['failure_rate'] ?? -1), -$b['calls']]);

        return array_merge($base, [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'rows' => $out,
            'truncated' => $truncated,
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // The chart

    /**
     * Calls and failures per bucket across the window.
     *
     * Both are folded inside one grouped row per bucket rather than read as a row each: the limit is
     * counted in buckets, and a read ordered oldest-first drops its NEWEST rows when a per-metric
     * read doubles the row count — a chart that stops early reads as an outage that is not there.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function timeline(string $range, array $window): array
    {
        $limit = min(self::MAX_TIMELINE_BUCKETS, $this->bucketsInWindow($window) + 1);

        try {
            $connection = $this->reader->connection();

            // Rides monitoring_dependency_unique (resolution, bucket_at, ...).
            $rows = $connection->table(self::SOURCE)
                ->where('resolution', $window['resolution'])
                ->where('bucket_at', '>=', $this->reader->since($range))
                ->groupBy('bucket_at')
                ->orderBy('bucket_at')
                ->limit($limit)
                ->get([
                    'bucket_at',
                    $connection->raw('SUM(calls) AS calls'),
                    $connection->raw('SUM(failures) AS failures'),
                    $connection->raw('SUM(duration_sum_ms) AS duration_sum_ms'),
                ]);
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'source' => self::SOURCE,
                'points' => [],
                'truncated' => false,
            ];
        }

        $points = [];
        foreach ($rows as $row) {
            $calls = (int) $row->calls;
            $points[] = [
                't' => Clock::parse($row->bucket_at)->toIso8601String(),
                'hits' => $calls,
                'errors' => (int) $row->failures,
                // No call in the bucket means no duration to average, which is not an average of 0ms.
                'avg_ms' => $calls > 0 ? round((float) $row->duration_sum_ms / $calls, 1) : null,
            ];
        }

        return [
            'state' => count($points) >= 2 ? 'ok' : 'no_data',
            'note' => count($points) >= 2
                ? null
                : (count($points) === 1
                    ? 'Only one bucket in this window recorded an outbound call, and one point is not a line.'
                    : 'No outbound call was recorded in this window.'),
            'source' => self::SOURCE,
            'points' => $points,
            'truncated' => count($points) >= $limit,
        ];
    }

    /** How many buckets the chosen window can hold — the figure the chart read is bounded by. */
    private function bucketsInWindow(array $window): int
    {
        $minutesPerBucket = match ($window['resolution']) {
            'day' => 1440,
            'hour' => 60,
            default => 1,
        };

        return (int) max(1, ceil($window['minutes'] / $minutesPerBucket));
    }

    // -------------------------------------------------------------------------------------------
    // The filter row

    /**
     * The one filter this page offers, clamped against what is actually in the table.
     *
     * The service column is free text at the database level, so there is no shipped vocabulary to
     * validate against — the observed set is the allowlist. A value that is not in it is dropped
     * rather than bound into a WHERE clause, and `?service[]=x` arrives as an array, which casting
     * to string would turn into a warning the error handler raises as a throw.
     *
     * @param  array<string, mixed>  $services
     * @return array<string, mixed>
     */
    private function filters(Request $request, array $services): array
    {
        $value = $request->query('service', 'all');
        $service = is_string($value) ? mb_substr(trim($value), 0, 64) : 'all';

        $available = array_column($services['rows'] ?? [], 'service');
        if ($service === '' || !in_array($service, $available, true)) {
            $service = 'all';
        }

        return [
            'service' => $service,
            'narrowed' => $service !== 'all',
            'available' => $available,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The dependencies nobody is measuring

    /**
     * Every outbound seam in the codebase, matched against what is actually recorded.
     *
     * The most important block on the page while the recorder is missing, and still the most
     * important once it exists: a dependency that is never called cannot appear in a table of calls,
     * so its absence there is not a fact about the shop until this list says whether it was watched.
     *
     * The configured reading is three-valued. "Switched off" and "I could not read whether it is
     * switched on" are different, and an unconfigured gateway with no recorder is a much smaller job
     * than a live one with no recorder — which is the whole ordering of the work this block implies.
     *
     * @param  array<string, mixed>  $services
     * @return array<string, mixed>
     */
    private function catalogue(array $services): array
    {
        $recorded = array_map(
            static fn (string $service) => mb_strtolower($service),
            array_column($services['rows'] ?? [], 'service'),
        );

        $settings = $this->settings();
        $rows = [];
        $instrumented = 0;
        $liveAndUnmeasured = 0;

        foreach (self::CATALOGUE as $entry) {
            $matches = [];
            foreach ($recorded as $service) {
                foreach ($entry['service_keys'] as $key) {
                    if (str_contains($service, mb_strtolower($key))) {
                        $matches[] = $service;
                        break;
                    }
                }
            }
            $matches = array_values(array_unique($matches));

            $configured = $this->configured($entry['settings'] ?? null, $settings);

            if ($matches !== []) {
                $instrumented++;
            } elseif ($configured['enabled'] === true) {
                $liveAndUnmeasured++;
            }

            $rows[] = [
                'key' => $entry['key'],
                'name' => $entry['name'],
                'category' => $entry['category'],
                'detail' => $entry['detail'],
                'client' => $entry['client'],
                'seam' => $entry['seam'],
                'recorder' => $entry['recorder'],
                'service_keys' => $entry['service_keys'],
                'recorded' => $matches !== [],
                'recorded_as' => $matches,
                // Three-valued: true, false, or null where the settings read did not answer.
                'enabled' => $configured['enabled'],
                'enabled_detail' => $configured['detail'],
                'enabled_source' => $configured['source'],
            ];
        }

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => $instrumented === count($rows) ? null : self::PRODUCER_REMEDY,
            'source' => 'IntegrationsPanel::CATALOGUE, matched against ' . self::SOURCE,
            'matching' => 'A dependency is counted as measured when a service name in the table contains one of its candidate keys. The match is deliberately loose, because the service column is free text written by a recorder that does not exist yet — so an entry lights up the moment somebody starts recording it, and the names it matched are shown beside it rather than assumed.',
            'rows' => $rows,
            'settings' => $settings,
            'totals' => [
                'dependencies' => count($rows),
                'instrumented' => $instrumented,
                'unmeasured' => count($rows) - $instrumented,
                'enabled_and_unmeasured' => $liveAndUnmeasured,
            ],
        ];
    }

    /**
     * Which integrations the merchant has actually switched on.
     *
     * Two shop tables, both bounded by the number of features the codebase ships rather than by
     * anything a customer can create: addon_settings holds one row per gateway and per SMS provider
     * (51 on this deployment) and business_settings one row per setting (145). Neither has an index
     * beyond its primary key, so both reads are bounded by an explicit key list and a LIMIT instead.
     *
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        $base = [
            'source' => 'addon_settings, business_settings',
            'gateways' => [],
            'sms' => [],
            'flags' => [],
            'truncated' => false,
        ];

        try {
            $rows = $this->shop()->table('addon_settings')
                ->whereIn('settings_type', ['payment_config', 'sms_config'])
                ->limit(self::MAX_SETTING_ROWS + 1)
                ->get(['key_name', 'settings_type', 'is_active']);
        } catch (\Throwable $exception) {
            return array_merge($base, [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
            ]);
        }

        $gateways = [];
        $sms = [];
        foreach ($rows->take(self::MAX_SETTING_ROWS) as $row) {
            $entry = [
                'key' => mb_substr((string) $row->key_name, 0, 64),
                'active' => (int) $row->is_active === 1,
            ];

            if ((string) $row->settings_type === 'payment_config') {
                $gateways[] = $entry;
            } else {
                $sms[] = $entry;
            }
        }

        $flags = [];
        try {
            $settings = $this->shop()->table('business_settings')
                ->whereIn('type', self::SETTING_KEYS)
                ->limit(count(self::SETTING_KEYS) + 1)
                ->get(['type', 'value']);

            foreach ($settings as $setting) {
                $flags[(string) $setting->type] = $this->settingFlag($setting->value);
            }
        } catch (\Throwable $exception) {
            return array_merge($base, [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'gateways' => $gateways,
                'sms' => $sms,
            ]);
        }

        return array_merge($base, [
            'state' => 'ok',
            'note' => null,
            'gateways' => $gateways,
            'sms' => $sms,
            'flags' => $flags,
            'truncated' => $rows->count() > self::MAX_SETTING_ROWS,
        ]);
    }

    /**
     * One stored setting value reduced to on / off / unreadable.
     *
     * The column holds four different shapes across these keys and only two of them are answers.
     * "1"/"0" is a switch and a JSON blob carrying a status is a switch; a JSON blob without one and
     * a bare credential are not. The shipped value of push_notification_key is the literal string
     * "Put your firebase server key here.", so reading "a value is present" as "switched on" would
     * report an integration as live on every untouched install — the exact shape of fabrication
     * this dashboard exists to refuse. Those cases return null, and the kind says why.
     *
     * The stored value is never published in either direction: these rows are credentials.
     *
     * @return array{on: bool|null, kind: string}
     */
    private function settingFlag(mixed $stored): array
    {
        if ($stored === null) {
            return ['on' => null, 'kind' => 'missing'];
        }

        $value = trim((string) $stored);
        if ($value === '') {
            return ['on' => false, 'kind' => 'empty'];
        }

        if (in_array($value, ['0', '1'], true)) {
            return ['on' => $value === '1', 'kind' => 'flag'];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            foreach (['status', 'is_active', 'active'] as $field) {
                if (array_key_exists($field, $decoded) && is_scalar($decoded[$field])) {
                    return ['on' => (int) $decoded[$field] === 1, 'kind' => 'blob_with_status'];
                }
            }

            return ['on' => null, 'kind' => 'blob'];
        }

        return ['on' => null, 'kind' => 'credential'];
    }

    /**
     * Whether one catalogue entry's integration is switched on.
     *
     * @param  array<string, string>|null  $descriptor
     * @param  array<string, mixed>  $settings
     * @return array{enabled: bool|null, detail: string|null, source: string|null}
     */
    private function configured(?array $descriptor, array $settings): array
    {
        if ($descriptor === null) {
            return [
                'enabled' => null,
                'detail' => 'This integration has no on/off setting: it runs whenever the code path that calls it runs.',
                'source' => null,
            ];
        }

        if (($settings['state'] ?? null) !== 'ok') {
            return [
                'enabled' => null,
                'detail' => 'The shop settings tables could not be read, so whether this is switched on is unknown.',
                'source' => $descriptor['table'],
            ];
        }

        if ($descriptor['table'] === 'addon_settings') {
            $bucket = ($descriptor['type'] ?? '') === 'sms_config' ? $settings['sms'] : $settings['gateways'];

            if (isset($descriptor['key'])) {
                foreach ($bucket as $entry) {
                    if ($entry['key'] === $descriptor['key']) {
                        return [
                            'enabled' => $entry['active'],
                            'detail' => null,
                            'source' => 'addon_settings.' . $descriptor['key'],
                        ];
                    }
                }

                return [
                    'enabled' => null,
                    'detail' => 'No settings row exists for this provider.',
                    'source' => 'addon_settings',
                ];
            }

            $active = count(array_filter($bucket, static fn (array $entry) => $entry['active']));

            return [
                'enabled' => $active > 0,
                'detail' => $active . ' of ' . count($bucket) . ' switched on.',
                'source' => 'addon_settings (' . $descriptor['type'] . ')',
            ];
        }

        $flag = $settings['flags'][$descriptor['key']] ?? null;

        if ($flag === null) {
            return [
                'enabled' => false,
                'detail' => 'No business_settings row exists for this key, so it has never been configured.',
                'source' => 'business_settings.' . $descriptor['key'],
            ];
        }

        return [
            'enabled' => $flag['on'],
            'detail' => match ($flag['kind']) {
                'blob' => 'The stored value is a configuration blob with no status field, so whether it is switched on cannot be read from it.',
                'credential' => 'The stored value is a credential rather than a switch. Its presence is not proof that it works — the shipped default for this key is placeholder text — so this is left unanswered rather than reported as on.',
                'empty' => 'The setting exists and is empty.',
                default => null,
            },
            'source' => 'business_settings.' . $descriptor['key'],
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Outbound calls that ARE recorded — somewhere else

    /**
     * Monitoring's own probes.
     *
     * The one class of outbound call this deployment does record, and it is recorded into
     * monitoring_check_results rather than into the dependency buckets. Drawn here so that "no
     * outbound calls recorded" cannot be read as "this server makes no outbound calls": it makes
     * them, and the ones it watches are its own.
     *
     * @return array<string, mixed>
     */
    private function probes(string $range): array
    {
        $base = [
            'source' => self::PROBE_SOURCE,
            'rows' => [],
            'truncated' => false,
            'limit' => self::MAX_PROBE_GROUPS,
        ];

        try {
            $connection = $this->reader->connection();

            // Rides the checked_at index for the range; kind and status are folded after it. The
            // window plus the group cap is what bounds this, not the filter.
            $rows = $connection->table(self::PROBE_SOURCE)
                ->where('checked_at', '>=', $this->reader->since($range))
                ->groupBy('check_key', 'kind')
                ->limit(self::MAX_PROBE_GROUPS + 1)
                ->get([
                    'check_key',
                    'kind',
                    $connection->raw('COUNT(*) AS runs'),
                    $connection->raw("SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) AS ok_runs"),
                    $connection->raw("SUM(CASE WHEN status = 'failing' THEN 1 ELSE 0 END) AS failing_runs"),
                    $connection->raw('AVG(duration_ms) AS avg_ms'),
                    $connection->raw('MAX(checked_at) AS last_checked_at'),
                ]);
        } catch (\Throwable $exception) {
            return array_merge($base, [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => null,
            ]);
        }

        $presented = [];
        foreach ($rows->take(self::MAX_PROBE_GROUPS) as $row) {
            $runs = (int) $row->runs;
            $presented[] = [
                'check_key' => mb_substr((string) $row->check_key, 0, 64),
                'kind' => mb_substr((string) $row->kind, 0, 12),
                'runs' => $runs,
                'ok_runs' => (int) $row->ok_runs,
                'failing_runs' => (int) $row->failing_runs,
                'success_rate' => $runs > 0 ? round(100 * (int) $row->ok_runs / $runs, 1) : null,
                'avg_ms' => $row->avg_ms === null ? null : round((float) $row->avg_ms, 1),
                'last_checked_at' => $this->displayStamp($row->last_checked_at),
            ];
        }

        usort($presented, static fn (array $a, array $b) => [-$a['failing_runs'], $a['check_key']]
            <=> [-$b['failing_runs'], $b['check_key']]);

        return array_merge($base, [
            'state' => $presented === [] ? 'no_data' : 'ok',
            'note' => $presented === []
                ? 'No check has run inside this window, so not even monitoring\'s own outbound probes were recorded.'
                : null,
            'remedy' => $presented === []
                ? 'Checks run from the scheduler. Run `php artisan monitoring:check` once, then confirm cron is calling `php artisan schedule:run`.'
                : null,
            'rows' => $presented,
            'truncated' => $rows->count() > self::MAX_PROBE_GROUPS,
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // What this build cannot measure at all

    /**
     * The readings that have no writer at either end.
     *
     * Published as unconfigured readings rather than left out. An outbound-latency figure that is
     * simply absent from the page reads as a page that did not think to show it; a figure that says
     * "nothing increments this counter, here is the file" is a task with an owner.
     *
     * @return array<string, mixed>
     */
    private function unmeasured(): array
    {
        return [
            'state' => 'not_configured',
            'source' => self::SOURCE,
            'note' => 'Three structures for outbound measurement exist in this build and none of them has a producer. They are drawn as unconfigured readings rather than as empty charts, because an empty chart reads as a measurement that came back flat.',
            'fields' => [
                'dependency_call_recorder' => Metric::notConfigured(
                    source: self::SOURCE,
                    remedy: self::PRODUCER_REMEDY,
                    note: 'The table, ' . self::WRITER . ', the rollup, the retention plan and five Overview cards all read and write this and are waiting on one registration. Http:: callers would be covered at once; raw curl_exec, the three gateway SDKs, the Twilio SDK, the OpenAI SDK, Guzzle and Mail:: each need their own wrapper.',
                ),
                'last_success_at' => Metric::notConfigured(
                    source: self::SOURCE . '.last_success_at',
                    remedy: 'Set last_success_at, last_failure_at and last_error in the row built by ' . self::WRITER . '.',
                    note: 'The single most useful reading on this page and the one with no writer at either end. The rollup carries these three columns forward from the minute rows, and the minute writer never sets them — so they would stay null even after a recorder existed. The bucket-granular substitute in the table above is derived from calls and failures instead, and is accurate only to the window resolution.',
                ),
                'external_time_per_request' => Metric::notConfigured(
                    source: 'monitoring_request_buckets.external_ms_sum / external_calls',
                    remedy: 'Increment RequestContext::$externalMs and $externalCalls (app/Services/Monitoring/Ingest/RequestContext.php:40,42) from the same middleware.',
                    note: 'RequestRecorder already writes both columns and TraceRecorder already reads both fields; nothing anywhere increments them, so every external_ms_sum in the request buckets is a structural zero rather than a request that made no outbound call.',
                ),
                'outbound_spans' => Metric::notConfigured(
                    source: 'monitoring_spans (kind=http)',
                    remedy: 'Emit an http span from the same middleware. Counting them here would also need an index on monitoring_spans.kind — today the table is indexed only by trace_id, so this page does not query it.',
                    note: 'The span kind is documented in the migration and never emitted, so no trace waterfall shows an outbound call. This panel deliberately does not query the table: the only query that would answer the question is a full scan, and a monitoring page that table-scans becomes the incident.',
                ),
                'dependency_alert_rules' => Metric::notConfigured(
                    source: 'monitoring_alert_rules via app/Services/Monitoring/Alerting/MetricResolver.php',
                    remedy: 'Mirror the per-service counters into monitoring_series under BucketWriter::SERIES_PREFIX (dependency.failure_rate, dependency.p95_ms, labelled by service), which is the only shape MetricResolver can read, then seed rules against them.',
                    note: 'MetricResolver resolves stored series and the derived http.* request metrics, and has no path to the dependency buckets — so no alert rule can be written against an outbound dependency at all, and no gateway outage can open an incident by itself however long it lasts.',
                ),
            ],
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Shared reads and helpers

    /**
     * Where the folded buckets stop and the raw minutes take over.
     *
     * The newest folded parent's own start. The rollup folds the parent that is still in progress,
     * so treating it as complete would lose everything from its start to now — up to fifty-six
     * minutes at hour resolution, twenty-three hours at day — from both sides of the seam.
     */
    private function seam(string $resolution, \Illuminate\Support\Carbon $from): \Illuminate\Support\Carbon
    {
        if (isset($this->seams[$resolution])) {
            return $this->seams[$resolution];
        }

        try {
            // Rides monitoring_dependency_unique (resolution, bucket_at, ...): an index-only read.
            $newestFolded = $this->reader->connection()->table(self::SOURCE)
                ->where('resolution', $resolution)
                ->max('bucket_at');
        } catch (\Throwable) {
            // The caller's own try/catch will surface the real failure when it repeats this read;
            // returning the window start here means the fallback reads minutes for the whole window,
            // which is correct rather than empty.
            return $from;
        }

        return $this->seams[$resolution] = $newestFolded !== null ? Clock::parse($newestFolded) : $from;
    }

    /**
     * The shop's own database.
     *
     * Not $reader->connection(): that one resolves config('monitoring.connection'), which may point
     * at a separate database or a separate host. The gateway and integration settings live in the
     * application's own database and nowhere else.
     */
    private function shop(): Connection
    {
        return DB::connection();
    }

    /**
     * A failed read, said in one line that is safe to print.
     *
     * A QueryException carries the statement and its bindings, and an exception message is one of
     * the most reliable places in an application to find a token — which on this page is exactly
     * what the bindings would be.
     */
    private function failureNote(\Throwable $exception): string
    {
        return class_basename($exception) . ': '
            . $this->redactor->text(mb_substr($exception->getMessage(), 0, 400));
    }

    /**
     * A stored UTC stamp, in the timezone the dashboard renders in.
     *
     * Every timestamp on this page passes through here. These columns are written by monitoring
     * itself, so they are UTC; printing one directly would put a gateway's last success hours away
     * from the deploy that broke it on any deployment whose display timezone is not UTC.
     */
    private function displayStamp(mixed $stored): ?string
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return Clock::display($stored)->toDateTimeString();
        } catch (\Throwable) {
            // An unparseable stamp is shown as stored rather than dropped: the call really happened,
            // and inventing a time for it would be worse than showing the raw value.
            return is_scalar($stored) ? (string) $stored : null;
        }
    }

    private function secondsSince(mixed $stored): ?int
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return (int) Clock::parse($stored)->diffInSeconds(Clock::now(), false);
        } catch (\Throwable) {
            return null;
        }
    }

    /** The later of two stored stamps, keeping whichever is readable. */
    private function later(mixed $current, mixed $candidate): mixed
    {
        if ($candidate === null || (is_string($candidate) && trim($candidate) === '')) {
            return $current;
        }
        if ($current === null) {
            return $candidate;
        }

        try {
            return Clock::parse($candidate)->greaterThan(Clock::parse($current)) ? $candidate : $current;
        } catch (\Throwable) {
            return $current;
        }
    }

    /**
     * The larger of two maxima, or null when neither was measured.
     *
     * Null is preserved rather than cast: (int) null is 0, and a zero in a slowest-call column is
     * the single most misleading value this page could print.
     */
    private function larger(?int $current, mixed $candidate): ?int
    {
        if (!is_numeric($candidate)) {
            return $current;
        }

        return $current === null ? (int) $candidate : max($current, (int) $candidate);
    }
    /**
     * Outbound webhook delivery to sellers' own systems.
     *
     * Read live rather than from a series, and it says so: these are counts of rows in the delivery
     * ledger, not a measured rate over the selected window. Reporting them as a window figure would
     * be the one thing this console must not do — a number that looks measured and is not.
     *
     * @return array<string, mixed>
     */
    private function sellerWebhooks(): array
    {
        if (!Schema::hasTable('seller_webhooks') || !Schema::hasTable('seller_webhook_deliveries')) {
            return ['state' => 'not_installed', 'note' => 'The seller webhook tables are not installed on this deployment.'];
        }

        try {
            $endpoints = DB::table('seller_webhooks');
            $deliveries = DB::table('seller_webhook_deliveries');

            return [
                'state' => 'ok',
                'source' => 'seller_webhooks, seller_webhook_deliveries',
                'note' => 'Counted from the delivery ledger as it stands, not measured over the selected window.',
                'endpoints' => (int) $endpoints->count(),
                'active' => (int) (clone $endpoints)->where('status', 'active')->count(),
                // The finding that matters: the platform gave up on these endpoints, and the seller
                // may not know their event stream stopped.
                'auto_disabled' => (int) (clone $endpoints)->where('status', 'disabled')->count(),
                'waiting_to_retry' => (int) $deliveries->whereNotNull('next_attempt_at')->count(),
                'failed' => (int) (clone $deliveries)->where('status', 'failed')->count(),
                'sellers_affected' => (int) (clone $endpoints)->where('status', 'disabled')->distinct()->count('seller_id'),
                'retry_sweep' => 'seller:retry-webhooks, every five minutes',
            ];
        } catch (\Throwable $exception) {
            return ['state' => 'failed', 'note' => Metric::describeFailure($exception)];
        }
    }

}
