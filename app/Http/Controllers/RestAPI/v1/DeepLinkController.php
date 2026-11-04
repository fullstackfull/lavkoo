<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Services\Analytics\CampaignService;
use App\Services\Analytics\Support\BotDetector;
use App\Services\DeepLink\AppLinkService;
use App\Services\DeepLink\DeepLinkResolver;
use App\Services\DeveloperPortal\ApiDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the mobile apps ask before they open a link.
 *
 * Two endpoints, and the second is the one that closes the gap between campaigns and deep links.
 * `config` tells an app which paths this shop publishes as app links, so the app team mirrors the
 * shop's list instead of a copy of it. `resolve` takes a URL the app received and answers with the
 * screen to open — following a campaign short link the whole way and handing back the attribution
 * so the visit is counted against the campaign even though it never touched the web.
 *
 * Public on purpose: none of it is secret — the association files that publish the same path list
 * are fetched unauthenticated by every phone — and requiring a token would mean an app could not
 * resolve a link before the customer had logged in, which is exactly when a campaign link arrives.
 */
class DeepLinkController extends Controller
{
    public function __construct(
        private readonly DeepLinkResolver $resolver,
        private readonly AppLinkService $links,
        private readonly CampaignService $campaigns,
        private readonly BotDetector $bots,
    ) {
    }

    #[ApiDoc(
        summary: 'The paths this shop publishes as app links, and whether deep linking is set up',
        audience: ApiDoc::CUSTOMER_APP,
        visibility: ApiDoc::PARTNER_VISIBLE,
        stability: ApiDoc::STABLE,
        since: 'v1',
    )]
    public function config(): JsonResponse
    {
        return response()->json([
            'android' => [
                'configured' => $this->links->isConfigured(AppLinkService::PLATFORM_ANDROID),
                'package_name' => $this->links->packageName(),
                'paths' => $this->links->paths(AppLinkService::PLATFORM_ANDROID),
            ],
            'ios' => [
                'configured' => $this->links->isConfigured(AppLinkService::PLATFORM_IOS),
                'bundle_id' => $this->links->bundleId(),
                'paths' => $this->links->paths(AppLinkService::PLATFORM_IOS),
            ],
            'targets' => array_values(array_unique(array_map(
                static fn (array $route): string => (string) $route['target'],
                (array) config('deeplinks.enabled_routes', [])
            ))),
            'campaign_path' => '/' . trim((string) config('analytics.campaigns.path', 'go'), '/') . '/{code}',
        ]);
    }

    #[ApiDoc(
        summary: 'Resolve a shop link — including a campaign short link — to the screen the app should open',
        description: 'Give it the URL the app received. It answers with the native target, its parameter, '
            . 'the canonical web URL, and the campaign attribution to stamp on the session that follows. '
            . 'A campaign short link is followed to its destination and its click is counted.',
        audience: ApiDoc::CUSTOMER_APP,
        visibility: ApiDoc::PARTNER_VISIBLE,
        stability: ApiDoc::STABLE,
        since: 'v1',
    )]
    public function resolve(Request $request): JsonResponse
    {
        $request->validate([
            'url' => 'required|string|max:2000',
        ]);

        $resolved = $this->resolver->resolve((string) $request->query('url'));

        if ($resolved['campaign'] !== null) {
            $this->recordAppClick($request, (string) $resolved['campaign']['code']);
        }

        return response()->json($resolved);
    }

    /**
     * Count an app open of a short link the same way the web redirect counts a browser one.
     *
     * Best-effort, like every other analytics write on a customer path: a campaign whose click
     * cannot be recorded still has to open the right screen.
     */
    private function recordAppClick(Request $request, string $code): void
    {
        try {
            $campaign = $this->resolver->campaignForCode($code);

            if ($campaign === null) {
                return;
            }

            $this->campaigns->recordClick($campaign, [
                'visitor_id' => null,
                'device' => 'mobile',
                'surface' => 'app',
                'country' => null,
                'referrer_domain' => null,
                'is_bot' => $this->bots->isBot($request),
                'ip_hash' => null,
            ]);
        } catch (\Throwable) {
            // A click that cannot be counted still has to arrive.
        }
    }
}
