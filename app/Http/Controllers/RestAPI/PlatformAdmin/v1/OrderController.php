<?php

namespace App\Http\Controllers\RestAPI\PlatformAdmin\v1;

use App\Exceptions\PlatformAdminApiException;
use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\PlatformAdmin\MarketSelection;
use App\Services\PlatformAdmin\MarketSelector;
use App\Services\PlatformAdmin\PlatformAdminPresenter;
use App\Services\PlatformAdmin\PlatformAdminPrincipal;
use App\Services\PlatformAdmin\PlatformAdminResponses;
use App\Services\PlatformAdmin\PlatformAdminSettings;
use App\Services\PlatformAdmin\PlatformMarketFigures;
use App\Services\PlatformAdmin\PlatformPagination;
use App\Services\PlatformAdmin\PlatformWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One market's orders, as a list rather than as a count (L-13): the first drill-down under the market card.
 *
 * It is ONE market only, like the report and the export, and for the same reason: a list across markets would have to put the orders of a market the
 * viewer may not see next to the ones they may, and the figure that answers "how is the platform doing" is the overview's count, not a list of
 * everything. Asking for `all` is the same 422 the report answers.
 *
 * The market is the query parameter `market`, never a path segment -- this API resolves no market from a path or a header anywhere, which is what
 * keeps its 403 and its 422 in one place (`MarketSelector`) and its audit line carrying the market that was asked for.
 */
class OrderController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    /** The most orders one page may hold. Named here rather than shared with the trail, whose lines are far smaller than an order's row. */
    private const MAX_PER_PAGE = 50;

    public function __construct(
        private readonly MarketSelector $selector,
        private readonly PlatformMarketFigures $figures,
        private readonly PlatformAdminSettings $settings,
    ) {
    }

    #[ApiDoc(
        audience: ApiDoc::ADMIN,
        visibility: ApiDoc::INTERNAL,
        stability: ApiDoc::BETA,
        since: 'v1',
        group: 'platform_admin',
        summary: 'One market\'s orders over a window, newest first',
        description: 'For ONE market (`market`, required) over a bounded window of days (`from`, `to`). Each order gives its id, its status, the moment it was placed and how many lines it has, newest first, at most 50 to a page. No customer is in it: no name, no address, no telephone number, no e-mail. An order belongs to a market only when every one of its lines is a product of that one market, which is the rule the counts use, so a market\'s list and its count never disagree.',
        idempotent: true,
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $selection = $this->selector->select(request: $request, principal: PlatformAdminPrincipal::fromRequest(request: $request));

        if ($selection->requested === MarketSelection::ALL) {
            throw PlatformAdminApiException::oneMarketOnly();
        }

        $window = PlatformWindow::resolve(
            from: $request->query('from'),
            to: $request->query('to'),
            defaultDays: $this->settings->windowDaysDefault(),
            maxDays: $this->settings->windowDaysMax(),
        );
        $pagination = PlatformPagination::fromRequest(request: $request, defaultPerPage: self::DEFAULT_PER_PAGE, maxPerPage: self::MAX_PER_PAGE);

        $page = $this->figures->ordersOfMarket(
            market: $selection->requested,
            from: $window->startsAt(),
            before: $window->endsBefore(),
            offset: $pagination->offset(),
            limit: $pagination->perPage,
        );

        return PlatformAdminResponses::data(data: [
            'market' => $selection->requested,
            'generated_at' => PlatformAdminPresenter::timestamp(moment: now()),
            'window' => $window->toArray(),
            'orders' => $page['orders'],
            'attribution' => PlatformMarketFigures::ORDER_ATTRIBUTION,
        ], meta: [
            'page' => $pagination->page,
            'per_page' => $pagination->perPage,
            'has_more' => $page['has_more'],
        ]);
    }
}
