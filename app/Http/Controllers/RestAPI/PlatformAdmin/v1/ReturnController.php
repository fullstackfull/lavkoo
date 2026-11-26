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
 * One market's refund requests, as a list rather than as a count (L-13): the returns drill-down under the market card.
 *
 * It is the same shape as the orders list, deliberately and not by coincidence: ONE market taken from the query parameter, a bounded window, a page of
 * at most fifty, newest first, a capability a grant holds only when it names it, and no customer in the answer. A second shape for a second
 * drill-down would mean an app with two ways of reading one card and a refusal that differs by which part of it was asked.
 *
 * The market is the query parameter `market`, never a path segment or a header, which is what keeps this API's 403 and its 422 in one place
 * (`MarketSelector`) and its audit line carrying the market that was asked for.
 */
class ReturnController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    /** The most returns one page may hold, as the orders list holds at most fifty orders. */
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
        summary: 'One market\'s refund requests over a window, newest first',
        description: 'For ONE market (`market`, required) over a bounded window of days (`from`, `to`). Each return gives its id, its status, the moment it was asked for and the order and product it is against, newest first, at most 50 to a page. No customer is in it, and nothing the customer wrote: no name, telephone number, e-mail, reason, image, payment detail or note. No amount either -- the money of a return is in the report and in the export, each behind a capability of its own. A return belongs to the market of the product it was made for, which is the rule the counted returns use, so a market\'s list and its count never disagree.',
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

        $page = $this->figures->returnsOfMarket(
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
            'returns' => $page['returns'],
            'attribution' => PlatformMarketFigures::RETURN_ATTRIBUTION,
        ], meta: [
            'page' => $pagination->page,
            'per_page' => $pagination->perPage,
            'has_more' => $page['has_more'],
        ]);
    }
}
