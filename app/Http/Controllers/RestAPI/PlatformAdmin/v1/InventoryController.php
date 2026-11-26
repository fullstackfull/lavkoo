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
 * One market's stock that is promised to orders or divided between channels, product by product (L-13): the inventory drill-down under the market card.
 *
 * The same shape as the other three lists, and like the products list it takes no window: what a shelf is promised is a state and not an event.
 *
 * It is the only list of the four whose underlying tables may not exist. They are the dormant half of the inventory stack, and the answer says which
 * halves are recorded rather than reporting a zero for an absent one -- "no pool is short" and "nothing divides a stock here" are different facts about a
 * market, and a zero would read as the first while meaning the second.
 */
class InventoryController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 50;

    /** What the answer says when neither inventory table exists on this installation, so that an empty list is never read as an empty shelf. */
    private const NOT_RECORDED = 'inventory_not_recorded';

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
        summary: 'One market\'s stock that is promised to orders or divided between channels, product by product',
        description: 'For ONE market (`market`, required). A row per product that holds or divides something: its stock, how many reserved holds it has and how many units they hold, how many pools it has and how many units those pools could not satisfy. Only reserved holds count -- committed and released are the two correct ends of a hold\'s life -- and the shortfall is the allocator\'s own record, not a second opinion about it. `recorded` says which of the two halves this installation keeps; when neither is kept the list is empty and `reason` says so, because an empty list and an unrecorded one are different facts. No customer, no order, no shop and no money.',
        idempotent: true,
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $selection = $this->selector->select(request: $request, principal: PlatformAdminPrincipal::fromRequest(request: $request));

        if ($selection->requested === MarketSelection::ALL) {
            throw PlatformAdminApiException::oneMarketOnly();
        }

        $pagination = PlatformPagination::fromRequest(request: $request, defaultPerPage: self::DEFAULT_PER_PAGE, maxPerPage: self::MAX_PER_PAGE);

        $page = $this->figures->stockHoldsOfMarket(
            market: $selection->requested,
            offset: $pagination->offset(),
            limit: $pagination->perPage,
        );
        $recorded = $page['recorded'];

        return PlatformAdminResponses::data(data: [
            'market' => $selection->requested,
            'generated_at' => PlatformAdminPresenter::timestamp(moment: now()),
            'recorded' => $recorded,
            'reason' => $recorded['holds'] || $recorded['pools'] ? null : self::NOT_RECORDED,
            'products' => $page['products'],
            'attribution' => PlatformMarketFigures::PRODUCT_ATTRIBUTION,
        ], meta: [
            'page' => $pagination->page,
            'per_page' => $pagination->perPage,
            'has_more' => $page['has_more'],
        ]);
    }
}
