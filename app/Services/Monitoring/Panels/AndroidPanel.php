<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Metric;

/**
 * The Android app, seen from the only place this shop can see it: the requests it sends.
 *
 * Traffic, latency, the request chart, the version mix and the self-reported stability counters
 * are shared with iOS and live in MobileAppPanel — the two sections ask the same questions of the
 * same series one platform apart, and the copy that used to sit here drifted from the iOS one the
 * first time either was fixed. What is left is what is genuinely NOT the same:
 *
 * WHAT "ANDROID" MEANS. Every figure on this page is the subset of requests that MonitorRequest
 * decided was Android, and that decision is a header first and a user-agent guess second. The
 * guess matches the word "android", which every mainstream browser on an Android phone also sends
 * — so on a shop whose app does not set X-Platform, this section is closer to "Android devices"
 * than to "the Android app". The rule is published as an ordered list of the branches the
 * middleware actually takes, so the number can be challenged rather than merely believed, and the
 * share of it that rests on the guess is published as an unconfigured reading, because nothing
 * records which branch fired.
 *
 * WHAT THIS PAGE CANNOT SEE. Start-up time, crash detail, per-version 4xx and requests that never
 * left the handset are named as unconfigured readings with the exact change each needs. They are
 * not drawn as zeroes, and not left off the page: a section that silently omits start-up time
 * reads as a section where start-up time is fine.
 *
 * Nothing in the URL reaches a query here. The panel takes no filters at all, so a hostile query
 * string has nothing to arrive in; `$range` is validated by the controller before it is passed.
 */
class AndroidPanel extends MobileAppPanel
{
    protected function platform(): string
    {
        return 'android';
    }

    protected function userAgentHint(): string
    {
        // What MonitorRequest::platform() looks for when no X-Platform header arrives — okhttp is
        // the default client for Retrofit, which is what most Android shopping apps are built on.
        return 'okhttp, android';
    }

    // -------------------------------------------------------------------------------------------
    // What counts as Android here

    /**
     * How a request comes to be on this page, transcribed from the branch that decides it.
     *
     * Published as the middleware's own ordered rules rather than as a sentence, because the
     * useful question is never "is this figure right" but "which of these five rules produced it",
     * and only the first two of them are the app declaring itself.
     *
     * @return array<string, mixed>
     */
    protected function identification(): array
    {
        return [
            'source' => self::MIDDLEWARE . '::platform()',
            'recorder' => self::RECORDER . '::finishRequest()',
            'header' => 'X-Platform: android',
            'version_header' => 'X-App-Version: 4.2.1',
            'version_pattern' => '^[0-9A-Za-z.+-]{1,32}$',
            'declared' => true,
            'rules' => [
                [
                    'test' => 'X-Platform header is android (any case)',
                    'outcome' => 'Counted on this page. The header is read first and nothing else is consulted.',
                    'certain' => true,
                ],
                [
                    'test' => 'X-Platform header is ios or web',
                    'outcome' => 'Not counted here, whatever the user agent says.',
                    'certain' => true,
                ],
                [
                    'test' => 'No usable X-Platform, and the user agent contains okhttp or android',
                    'outcome' => 'Counted on this page. This is an inference, not a declaration.',
                    'certain' => false,
                ],
                [
                    'test' => 'No usable X-Platform, and the user agent matches neither Android nor iOS',
                    'outcome' => 'Counted as web.',
                    'certain' => true,
                ],
                [
                    'test' => 'No user agent at all',
                    'outcome' => 'No platform is recorded. The request is in the shop totals and on no platform page.',
                    'certain' => true,
                ],
            ],
            'caveat' => 'Rule three is a guess and it can be wrong in both directions. Every mainstream browser on an Android'
                . ' handset puts the word "android" in its user agent, so a shopper using Chrome is counted here whenever the'
                . ' app-only header is absent — and an app built on an HTTP client that sends neither the header nor a'
                . ' recognisable agent is counted as web. On a deployment whose app does not send X-Platform, read this'
                . ' section as "Android devices" rather than as "the Android app".',
            'remedy' => 'Send `X-Platform: android` from the app on every request. It is checked before the user agent, so it'
                . ' removes the ambiguity entirely — and send `X-App-Version` with it, or the traffic cannot be attributed to a release.',
            // The one number that would settle the caveat, and nothing produces it.
            'attribution_split' => Metric::notConfigured(
                source: 'monitoring_series (requests.by_platform)',
                remedy: 'Record which branch decided the platform — a `requests.by_platform_source|android:header` /'
                    . ' `|android:agent` counter written beside the existing one in ' . self::RECORDER . '::finishRequest()'
                    . ' would do it, and costs one more increment on a path that already writes four.',
                note: 'Nothing records whether a request was filed here because it declared X-Platform or because its user'
                    . ' agent merely contained the word "android", so the share of this page that rests on the guess cannot'
                    . ' be quantified. It is not zero and it is not known.',
            ),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // What this section cannot see

    /**
     * The four questions an Android section is opened for that this deployment cannot answer.
     *
     * Published as readings with their reason rather than left off the page. A section that simply
     * omits start-up time reads as a section where start-up time is fine, and a crash count with
     * no diagnosis beside it reads as a crash count nobody needed to explain.
     *
     * @return array<string, mixed>
     */
    protected function notMeasured(): array
    {
        return [
            'state' => 'not_configured',
            'source' => self::TIMELINE_SOURCE . ', ' . self::HEALTH_RECORDER,
            'note' => 'These are not empty measurements. Nothing on this deployment produces them at all, so each names the'
                . ' exact change that would, and none of them is drawn as a zero or a percentage.',
            'fields' => [
                'app_start_time' => Metric::notConfigured(
                    source: 'no start-up ingest',
                    remedy: 'Extend the app-health report with a start-up distribution: ' . self::HEALTH_RECORDER . ' accepts three'
                        . ' integer counters today, so add bucketed fields (start_ms_le_500, start_ms_le_1000, start_ms_le_2000, …)'
                        . ' written into monitoring_series exactly as sessions and crashes are. Store the distribution, not a'
                        . ' mean — a mean start-up time hides the cold starts that are the whole complaint.',
                    note: 'Cold start is timed on the handset, between process launch and the first frame. The shop sees'
                        . ' neither: the first request an app makes arrives long after its screen is up, so no arrival time'
                        . ' here is a start-up time.',
                ),
                'crash_diagnostics' => Metric::notConfigured(
                    source: 'monitoring_error_groups, monitoring_errors',
                    remedy: 'Both tables already carry platform, app_version, release, fingerprint and stack_trace and nothing'
                        . ' in this build writes either. A crash-report endpoint — or a third-party crash SDK bridged into'
                        . ' them — would group app crashes the same way server exceptions are grouped.',
                    note: 'Crashes are counted, never described. The app-health ingest deliberately accepts three integers and'
                        . ' stores no stack trace, device model or user id, so this page can say that a release is crashing'
                        . ' and can never say why.',
                ),
                'client_error_rate_by_version' => Metric::notConfigured(
                    source: 'monitoring_series (requests.by_app_version.errors)',
                    remedy: 'Add a `requests.by_app_version.client_errors` counter beside the 5xx one in ' . self::RECORDER
                        . '::finishRequest(). It is one increment on a path that already writes it for the platform.',
                    note: 'The version table counts 5xx responses only. 4xx is counted for the platform as a whole, so a'
                        . ' release that started sending expired tokens or calling a route that has moved raises nothing in'
                        . ' the per-version column — and a 4xx storm is what a bad release usually looks like first.',
                ),
                'response_time_percentiles' => Metric::notSupported(
                    source: 'monitoring_series (requests.by_platform)',
                    note: 'The platform counters hold a sum and a count, so a mean is the only honest figure derivable from'
                        . ' them. Real percentiles live on monitoring_request_buckets, whose histogram is keyed by route,'
                        . ' method and channel and carries no platform — so a p95 for this app cannot be interpolated'
                        . ' from anything stored today.',
                    remedy: 'A per-platform p95 needs a latency histogram on the platform key, which means a second bucket'
                        . ' family rather than a second column. Until then the Requests section has real percentiles for'
                        . ' the API routes this app calls, across all clients.',
                ),
                'requests_that_never_arrived' => Metric::notSupported(
                    source: self::MIDDLEWARE,
                    note: 'A request that failed to leave the handset — no signal, a DNS failure, a TLS error, a timeout on'
                        . ' the way out — is invisible here by definition. Every figure on this page is assembled from'
                        . ' requests this shop answered, so the app can be failing entirely while this section stays quiet.',
                    remedy: 'Only the app can count these. They would have to be reported the way sessions and crashes are,'
                        . ' as counters posted to the app-health endpoint.',
                ),
            ],
        ];
    }
}
