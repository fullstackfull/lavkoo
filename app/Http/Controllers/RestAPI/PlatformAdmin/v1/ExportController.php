<?php

namespace App\Http\Controllers\RestAPI\PlatformAdmin\v1;

use App\Exceptions\PlatformAdminApiException;
use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\PlatformAdmin\MarketSelection;
use App\Services\PlatformAdmin\MarketSelector;
use App\Services\PlatformAdmin\PlatformAdminAudit;
use App\Services\PlatformAdmin\PlatformAdminPrincipal;
use App\Services\PlatformAdmin\PlatformAdminResponses;
use App\Services\PlatformAdmin\PlatformAdminSettings;
use App\Services\PlatformAdmin\PlatformExportThrottle;
use App\Services\PlatformAdmin\PlatformMarketFigures;
use App\Services\PlatformAdmin\PlatformOrderExport;
use App\Services\PlatformAdmin\PlatformWindow;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExportController extends Controller
{
    public function __construct(
        private readonly MarketSelector $selector,
        private readonly PlatformOrderExport $export,
        private readonly PlatformExportThrottle $throttle,
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
        summary: 'Take ONE market\'s orders of a window out of the platform as a file',
        description: 'A CSV of the orders one market recorded over a bounded window: the order id, the day, the status, the market, the selling company, the currency and the amount. No customer appears in it -- no name, address, telephone number or e-mail. The file is streamed and never stored anywhere on the server, and its first lines are a watermark naming the operator, the market, the window and the moment. It needs the `export` capability, which no existing grant holds unless it was given on purpose, and a second factor proved again within the last few minutes; `all` is refused, because what left the platform is one named market; and one operator may take a few per hour.',
        idempotent: true,
    )]
    public function orders(Request $request): Response
    {
        $principal = PlatformAdminPrincipal::fromRequest(request: $request);
        $selection = $this->selector->select(request: $request, principal: $principal);

        if ($selection->requested === MarketSelection::ALL) {
            throw PlatformAdminApiException::oneMarketOnly();
        }

        if (!$this->figures->orderCurrencyRecorded()) {
            throw PlatformAdminApiException::orderMarketNotRecorded();
        }

        if ($this->throttle->exceeded(adminId: $principal->adminId())) {
            return PlatformAdminResponses::throttled(retryAfterSeconds: $this->throttle->secondsUntilAvailable(adminId: $principal->adminId()));
        }

        $window = PlatformWindow::resolve(
            from: $request->query('from'),
            to: $request->query('to'),
            defaultDays: $this->settings->windowDaysDefault(),
            maxDays: $this->settings->windowDaysMax(),
        );
        $this->throttle->hit(adminId: $principal->adminId());

        // Counted before the file is streamed: the audit line of an export says how many rows left the platform, and it is written by the
        // authentication middleware after the answer -- which, for a streamed answer, is before a single row has been sent.
        $rows = $this->export->rowCount(market: $selection->requested, window: $window);
        $request->attributes->set(PlatformAdminAudit::DETAIL_ATTRIBUTE, [
            'rows' => $rows,
            'file' => $this->export->fileName(market: $selection->requested, window: $window),
            'from' => $window->from->toDateString(),
            'to' => $window->to->toDateString(),
        ]);

        return $this->export->stream(
            market: $selection->requested,
            window: $window,
            operator: ['id' => $principal->adminId(), 'name' => (string) $principal->admin->name],
        );
    }
}
