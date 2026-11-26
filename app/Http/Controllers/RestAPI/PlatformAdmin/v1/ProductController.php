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
use App\Services\PlatformAdmin\PlatformMarketFigures;
use App\Services\PlatformAdmin\PlatformPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One market's live products, as a list rather than as a count (L-13): the stock drill-down under the market card.
 *
 * The same shape as the orders and returns lists, for the same reason. It takes NO window, and that is the one difference worth stating: a product's
 * stock is a state of the shelf now, not something that happened between two dates, and a window over it would answer a question nobody asked. The
 * orders and the returns are events and carry one.
 *
 * Worst first: the market's shortest stock is the first row, because the figure this list drills into is "how many are at zero".
 */
class ProductController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 50;

    public function __construct(
        private readonly MarketSelector $selector,
        private readonly PlatformMarketFigures $figures,
    ) {
    }

    #[ApiDoc(
        audience: ApiDoc::ADMIN,
        visibility: ApiDoc::INTERNAL,
        stability: ApiDoc::BETA,
        since: 'v1',
        group: 'platform_admin',
        summary: 'One market\'s live products and their stock, shortest first',
        description: 'For ONE market (`market`, required). Each row gives the product\'s id and name, its stock, the stock limit that applies to it and whether it is low or oversold, shortest stock first, at most 50 to a page. The population is the one the stock figures and the stock alerts are counted over -- physical, published, approved, and either the platform\'s own or an approved shop\'s -- so a market\'s list and its counts never disagree. No shop is named and no price is given: who sells a product and what it costs are a different question from whether the market has run out of it. There is no window: stock is the state of the shelf now.',
        idempotent: true,
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $selection = $this->selector->select(request: $request, principal: PlatformAdminPrincipal::fromRequest(request: $request));

        if ($selection->requested === MarketSelection::ALL) {
            throw PlatformAdminApiException::oneMarketOnly();
        }

        $pagination = PlatformPagination::fromRequest(request: $request, defaultPerPage: self::DEFAULT_PER_PAGE, maxPerPage: self::MAX_PER_PAGE);

        $page = $this->figures->productsOfMarket(
            market: $selection->requested,
            offset: $pagination->offset(),
            limit: $pagination->perPage,
        );

        return PlatformAdminResponses::data(data: [
            'market' => $selection->requested,
            'generated_at' => PlatformAdminPresenter::timestamp(moment: now()),
            'products' => $page['products'],
            'attribution' => PlatformMarketFigures::PRODUCT_ATTRIBUTION,
        ], meta: [
            'page' => $pagination->page,
            'per_page' => $pagination->perPage,
            'has_more' => $page['has_more'],
        ]);
    }
}
