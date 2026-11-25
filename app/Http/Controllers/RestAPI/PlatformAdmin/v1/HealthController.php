<?php

namespace App\Http\Controllers\RestAPI\PlatformAdmin\v1;

use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\PlatformAdmin\MarketSelector;
use App\Services\PlatformAdmin\PlatformAdminPrincipal;
use App\Services\PlatformAdmin\PlatformAdminResponses;
use App\Services\PlatformAdmin\PlatformAdminSettings;
use App\Services\PlatformAdmin\PlatformHealthService;
use App\Services\PlatformAdmin\PlatformWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthController extends Controller
{
    public function __construct(
        private readonly MarketSelector $selector,
        private readonly PlatformHealthService $health,
        private readonly PlatformAdminSettings $settings,
    ) {
    }

    #[ApiDoc(
        audience: ApiDoc::ADMIN,
        visibility: ApiDoc::INTERNAL,
        stability: ApiDoc::BETA,
        since: 'v1',
        group: 'platform_admin',
        summary: 'Operational health per market: payment attempts that never came back, and integrations that have stopped syncing',
        description: 'For all markets or one (`market`), over a bounded window of days (`from`, `to`). Counts and timestamps only: no credential, no customer, no amount and no gateway reference, so the capability that opens this is safe to give an operator who is not trusted with money. Each figure is sent with a sentence saying what it means, because a stuck payment attempt is not the same thing as a failed one. Rows whose market is not recorded are reported apart and never counted as the default market.',
        idempotent: true,
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $selection = $this->selector->select(request: $request, principal: PlatformAdminPrincipal::fromRequest(request: $request));
        $window = PlatformWindow::resolve(
            from: $request->query('from'),
            to: $request->query('to'),
            defaultDays: $this->settings->windowDaysDefault(),
            maxDays: $this->settings->windowDaysMax(),
        );

        return PlatformAdminResponses::data(data: $this->health->build(selection: $selection, window: $window));
    }
}
