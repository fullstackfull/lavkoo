<?php

namespace App\Http\Middleware;

use App\Services\Analytics\Analytics;
use App\Services\Analytics\Support\AnalyticsPolicy;
use App\Services\Analytics\Support\PathNormalizer;
use App\Services\Analytics\EventRecorder;
use App\Services\Analytics\Support\PrivacyGate;
use App\Services\Analytics\VisitorContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens the visit and closes the books.
 *
 * Terminable on purpose: the visitor cookie has to be queued during the request so it reaches the
 * browser, but every write happens in terminate(), after the response has been sent. A shopper
 * never waits for analytics, and an analytics failure cannot reach their page.
 *
 * Only real navigations count as pageviews. An ajax poll, a JSON endpoint, an image and a redirect
 * are not pages a person looked at, and counting them is how a bounce rate ends up being computed
 * against a denominator that includes the cart's stock-check request.
 */
class RecordAnalytics
{
    public function __construct(
        private readonly Analytics $analytics,
        private readonly VisitorContext $context,
        private readonly PathNormalizer $paths,
        private readonly PrivacyGate $privacy,
        private readonly EventRecorder $recorder,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Not measured means not identified either: minting a visitor cookie for somebody who
        // has asked not to be tracked is the tracking they asked not to have.
        if (app(AnalyticsPolicy::class)->enabled() && $this->privacy->allows($request)) {
            // Resolving here, inside the request, is what allows the cookie to be queued onto the
            // outgoing response — by terminate() the response has already gone.
            $this->context->resolve($request);
        } elseif (app(AnalyticsPolicy::class)->enabled()) {
            // Counted, not silent. A shop that turns consent on and sees its traffic fall needs the
            // number that explains the fall, or it concludes analytics is broken and turns the
            // privacy control back off.
            $reason = $this->privacy->reason($request);

            if ($reason !== null) {
                $this->recorder->recordPrivacyRefusal($reason);
            }
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (!app(AnalyticsPolicy::class)->enabled() || !$this->privacy->allows($request)) {
                return;
            }

            if ($this->isNavigation($request, $response)) {
                $this->analytics->pageViewed($request);
            }

            $this->analytics->flush($request);
        } catch (\Throwable) {
            // Analytics is never the reason anything fails, including at the very end.
        }
    }

    private function isNavigation(Request $request, Response $response): bool
    {
        if (!$request->isMethod('GET') || $request->ajax() || $request->wantsJson()) {
            return false;
        }

        // 200-ish HTML only. A redirect is a step on the way to a page, not the page; a 404 is a
        // pageview of an error, which belongs in monitoring rather than in a traffic report.
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        if (!str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return false;
        }

        return !$this->paths->isIgnored($request->path());
    }
}
