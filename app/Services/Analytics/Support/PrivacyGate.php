<?php

namespace App\Services\Analytics\Support;

use Illuminate\Http\Request;

/**
 * Whether this visit may be measured at all.
 *
 * config/analytics.php has declared two privacy controls since the pipeline was built and neither
 * was ever read: setting ANALYTICS_RESPECT_DNT=true did nothing, and ANALYTICS_REQUIRE_CONSENT=true
 * did nothing — a shop that had switched both on believed it was honouring a signal it had never
 * looked at. Both are decided here, once, so there is one answer rather than one per call site.
 *
 * Both default to OFF, which is the behaviour every existing installation already has. Turning
 * either on is a deliberate act by an administrator, and this class is what makes that act mean
 * something.
 */
class PrivacyGate
{
    /** The cookie the storefront's own consent banner writes. */
    public const CONSENT_COOKIE = '6valley_cookie_consent';

    private ?bool $allowed = null;

    public function allows(Request $request): bool
    {
        return $this->allowed ??= $this->decide($request);
    }

    /** Why a visit was not measured, for the data-quality screen. Null when it was. */
    public function reason(Request $request): ?string
    {
        if ($this->allows($request)) {
            return null;
        }

        return $this->signalsDoNotTrack($request) ? 'do_not_track' : 'consent_not_given';
    }

    /** Forget the decision — for tests and long-running workers, which serve many requests. */
    public function forget(): void
    {
        $this->allowed = null;
    }

    private function decide(Request $request): bool
    {
        $policy = app(AnalyticsPolicy::class);

        if ($policy->respectDoNotTrack() && $this->signalsDoNotTrack($request)) {
            return false;
        }

        if ($policy->requireConsent()) {
            // Anything other than an explicit acceptance is a no. "Not asked yet" and "declined"
            // both mean consent has not been given, and only one of them is the visitor's fault.
            return $request->cookie(self::CONSENT_COOKIE) === 'accepted';
        }

        return true;
    }

    /**
     * DNT is the old header and Sec-GPC the one that carries legal weight in several places. Both
     * mean the same thing to this shop, so both are honoured.
     */
    private function signalsDoNotTrack(Request $request): bool
    {
        return $request->headers->get('DNT') === '1'
            || $request->headers->get('Sec-GPC') === '1';
    }
}
