<?php

namespace App\Http\Controllers\RestAPI\PlatformAdmin\v1;

use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\PlatformAdmin\MarketSelector;
use App\Services\PlatformAdmin\PlatformAdminPresenter;
use App\Services\PlatformAdmin\PlatformAdminPrincipal;
use App\Services\PlatformAdmin\PlatformAdminResponses;
use App\Services\PlatformAdmin\PlatformAlertService;
use App\Services\PlatformAdmin\PlatformPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 50;

    public function __construct(private readonly MarketSelector $selector, private readonly PlatformAlertService $alerts)
    {
    }

    #[ApiDoc(
        audience: ApiDoc::ADMIN,
        visibility: ApiDoc::INTERNAL,
        stability: ApiDoc::BETA,
        since: 'v1',
        group: 'platform_admin',
        summary: 'The alerts of the markets, worst first',
        description: 'Derived when asked from the storefront legal gate, the stock of live products and orders that stayed pending, with a stable id per alert and market, a severity (info, warning, critical), a plain message and a deep link the app can route. Filter with `market` and `severity`; paginated, at most 50 to a page.',
        idempotent: true,
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $selection = $this->selector->select(request: $request, principal: PlatformAdminPrincipal::fromRequest(request: $request));
        $severity = $this->alerts->severityOf(given: $request->query('severity'));
        $pagination = PlatformPagination::fromRequest(request: $request, defaultPerPage: self::DEFAULT_PER_PAGE, maxPerPage: self::MAX_PER_PAGE);

        $alerts = $this->alerts->forMarkets(selected: $selection->markets);
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
}
