<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Analytics\Analytics;
use App\Services\Analytics\CampaignService;
use App\Services\Analytics\Support\AttributionEngine;
use App\Services\Analytics\Support\BotDetector;
use App\Services\Analytics\VisitorContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The short link a campaign hands out.
 *
 * The security shape matters more than the feature. This endpoint never redirects to a URL from
 * the request: it looks up a row an administrator created, re-validates that row's destination
 * against the allow-list, and only then sends the visitor. A code that does not exist, is
 * inactive, has expired, or whose destination no longer passes is not followed — the visitor goes
 * to the shop's home page instead of anywhere an attacker chose.
 *
 * Everything analytics-related here is best-effort. A printed QR on a shop window has to work when
 * the analytics database does not.
 */
class CampaignRedirectController extends Controller
{
    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly VisitorContext $context,
        private readonly BotDetector $bots,
        private readonly AttributionEngine $attribution,
        private readonly Analytics $analytics,
    ) {
    }

    public function __invoke(Request $request, string $code): RedirectResponse
    {
        $resolved = $this->campaigns->resolve($code);

        if (!$resolved['ok']) {
            // Deliberately quiet: a 404 page for a mistyped code on a poster is a worse outcome
            // than the home page, and telling a prober which codes exist is worse than either.
            return redirect()->to('/');
        }

        $this->remember($request, $code);
        $this->record($request, $resolved['campaign']);

        // 302, not 301: a permanently-cached redirect cannot be retired, retargeted or counted
        // again, and a campaign link's whole purpose is to be measured.
        return redirect()->away($resolved['url'], 302);
    }

    /**
     * Leave the campaign code where the visit that follows can find it.
     *
     * The redirect lands the visitor on a page carrying the campaign's UTM parameters, so the
     * attribution engine would resolve it anyway — but a customer who edits the URL, or a link
     * that lands on a page that strips its query, would lose it. The cookie is short-lived because
     * it should only survive the redirect itself.
     */
    private function remember(Request $request, string $code): void
    {
        try {
            $request->attributes->set('analytics_campaign_code', $code);
            cookie()->queue(cookie(
                VisitorContext::CAMPAIGN_COOKIE,
                $code,
                30,          // minutes
                null, null, null,
                false,
            ));
        } catch (\Throwable) {
            // The UTM parameters on the destination still carry the attribution.
        }
    }

    private function record(Request $request, object $campaign): void
    {
        try {
            $this->context->resolve($request);

            $this->campaigns->recordClick($campaign, [
                'visitor_id' => $this->context->visitorId($request),
                'device' => $this->bots->device($request),
                'country' => $this->context->country($request),
                'referrer_domain' => $this->attribution->referrerDomain($request),
                'is_bot' => $this->bots->isBot($request),
                'ip_hash' => null,
            ]);

            // The click table answers "how is this link performing"; the event stream answers "what
            // did the person who clicked it go on to do". Writing only the first left campaign_clicked
            // in the catalogue as an event nothing produced, so every journey through a short link
            // started at the destination page with no visible arrival.
            $this->analytics->campaignClicked(campaignId: (int) $campaign->id, code: (string) $campaign->code);
        } catch (\Throwable) {
            // A click that cannot be counted still has to arrive.
        }
    }
}
