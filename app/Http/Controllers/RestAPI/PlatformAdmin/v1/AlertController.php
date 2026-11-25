<?php

namespace App\Http\Controllers\RestAPI\PlatformAdmin\v1;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdminGrant;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\PlatformAdmin\MarketSelection;
use App\Services\PlatformAdmin\MarketSelector;
use App\Services\PlatformAdmin\PlatformAdminPresenter;
use App\Services\PlatformAdmin\PlatformAdminPrincipal;
use App\Services\PlatformAdmin\PlatformAdminResponses;
use App\Services\PlatformAdmin\PlatformAdminSettings;
use App\Services\PlatformAdmin\PlatformAlertService;
use App\Services\PlatformAdmin\PlatformHealthService;
use App\Services\PlatformAdmin\PlatformPagination;
use App\Services\PlatformAdmin\PlatformWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 50;

    public function __construct(
        private readonly MarketSelector $selector,
        private readonly PlatformAlertService $alerts,
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
        summary: 'The alerts of the markets, worst first',
        description: 'Derived when asked from the storefront legal gate, the stock of live products, orders that stayed pending and -- for an operator who holds `health` -- each of the operational health signals, with a stable id per alert and market, a severity (info, warning, critical), a plain message and a deep link the app can route. The health alerts are raised only for a holder of that capability, because the figures behind them are the payment and integration failures it exists to withhold. Filter with `market` and `severity`; paginated, at most 50 to a page.',
        idempotent: true,
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $principal = PlatformAdminPrincipal::fromRequest(request: $request);
        $selection = $this->selector->select(request: $request, principal: $principal);
        $severity = $this->alerts->severityOf(given: $request->query('severity'));
        $pagination = PlatformPagination::fromRequest(request: $request, defaultPerPage: self::DEFAULT_PER_PAGE, maxPerPage: self::MAX_PER_PAGE);

        $alerts = $this->alerts->forMarkets(selected: $selection->markets, health: $this->healthFiguresFor(principal: $principal, selection: $selection));
        if ($severity !== null) {
            $alerts = array_values(array_filter($alerts, static fn (array $alert): bool => $alert['severity'] === $severity));
        }

        return PlatformAdminResponses::data(data: array_slice($alerts, $pagination->offset(), $pagination->perPage), meta: [
            'scope' => $selection->scope,
            'market' => $selection->requested,
            'severity' => $severity,
            'generated_at' => PlatformAdminPresenter::timestamp(moment: now()),
            'page' => $pagination->page,
            'per_page' => $pagination->perPage,
            'total' => count($alerts),
            'has_more' => $pagination->offset() + $pagination->perPage < count($alerts),
        ]);
    }

    /**
     * The health figures the health alerts are raised from, or null for an operator who does not hold the `health` capability.
     *
     * Null rather than an empty list, because the two say different things: null is "these alerts are not for you", and an empty list would
     * be "your markets are healthy". The health answer itself is never returned here -- only the alerts derived from it -- but it is not even
     * BUILT for an operator without the capability, so nothing of it can reach them through this endpoint.
     *
     * @return list<array<string, mixed>>|null
     */
    private function healthFiguresFor(PlatformAdminPrincipal $principal, MarketSelection $selection): ?array
    {
        if (!$principal->can(PlatformAdminGrant::CAPABILITY_HEALTH)) {
            return null;
        }

        $window = PlatformWindow::resolve(
            from: null,
            to: null,
            defaultDays: $this->settings->windowDaysDefault(),
            maxDays: $this->settings->windowDaysMax(),
        );

        return $this->health->build(selection: $selection, window: $window)['markets'];
    }
}
