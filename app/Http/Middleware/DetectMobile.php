<?php

namespace App\Http\Middleware;

use App\Services\DeepLink\AppLinkService;
use Closure;
use Illuminate\Http\Request;

class DetectMobile
{
    public function __construct(private readonly AppLinkService $appLinks)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if ($request->expectsJson() || $request->is('api/*') || $request->is('vendor/*') || $request->is('admin/*')) {
            return $next($request);
        }

        $userAgent = (string) $request->header('User-Agent');
        $isAndroid = (bool) preg_match('/android/i', $userAgent);
        $isIOS = (bool) preg_match('/iphone/i', $userAgent);
        $platform = $isAndroid ? AppLinkService::PLATFORM_ANDROID : AppLinkService::PLATFORM_IOS;

        // "Mobile" here means "we can actually send this visitor to the app store", not "small
        // screen": the download banner is pointless without a store link to send them to.
        $isMobile = ($isAndroid || $isIOS) && $this->appLinks->isConfigured($platform);

        /*
         * The install link carries the campaign this visit came from.
         *
         * This is the join between campaigns and the app. A customer arrives on an Instagram
         * campaign, taps download, installs — and until now that install was attributed to nobody,
         * because the banner sent them to a bare store URL. Now the campaign travels with them:
         * Play hands the referrer to the app on first launch, and Apple records the campaign token
         * against the install.
         */
        $appInstallUrl = $isMobile
            ? $this->appLinks->storeUrl($platform, $this->appLinks->attributionFromRequest($request))
            : null;

        view()->share([
            'isMobile' => $isMobile,
            'isAndroid' => $isAndroid,
            'isIOS' => $isIOS,
            'appInstallUrl' => $appInstallUrl,
        ]);

        return $next($request);
    }
}
