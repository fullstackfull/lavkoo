<?php

namespace App\Http\Controllers\RestAPI\PlatformAdmin\v1;

use App\Exceptions\PlatformAdminApiException;
use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\PlatformAdmin\MarketSelection;
use App\Services\PlatformAdmin\MarketSelector;
use App\Services\PlatformAdmin\PlatformAdminPrincipal;
use App\Services\PlatformAdmin\PlatformAdminResponses;
use App\Services\PlatformAdmin\PlatformAdminSettings;
use App\Services\PlatformAdmin\PlatformReportService;
use App\Services\PlatformAdmin\PlatformWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private readonly MarketSelector $selector,
        private readonly PlatformReportService $reports,
        private readonly PlatformAdminSettings $settings,
    ) {
    }

    #[ApiDoc(
        audience: ApiDoc::ADMIN,
        visibility: ApiDoc::INTERNAL,
        stability: ApiDoc::BETA,
        since: 'v1',
        group: 'platform_admin',
        summary: 'One market\'s summary over a window: orders, returns, what it sold, what it took, its tax and what was settled',
        description: 'For ONE market (`market`, required) over a bounded window of days (`from`, `to`). Orders by status and returns as counts; what the market sold, what it TOOK (`payments`, by payment method and currency, with the money received apart from the orders still unpaid), the TAX it recorded (`tax`, what was charged on top and what was already inside a tax-included price, never added together) and what was settled with its shops, each by the company that did it and the currency of its own money, never one figure across two currencies. A figure across currencies exists only when the owner has recorded a conversion rate, its source and its date, and is then sent with all three. A market with no selling company recorded, orders that record no currency, a payment method an order does not name, an included tax nothing has written and an absent settlement ledger each say so with a reason or a count of their own rather than a number that would be false. The tax here is a SUMMARY and not a compliant tax return, which stays desktop work.',
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

        return PlatformAdminResponses::data(data: $this->reports->build(market: $selection->requested, window: $window));
    }
}
