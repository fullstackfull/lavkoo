<?php

namespace App\Http\Controllers\Seller;

use App\Services\Marketplace\SellerScorecardService;
use App\Services\SellerCenter\Lists\HomeMetrics;
use App\Services\Marketplace\VendorLedger;
use App\Services\SellerIntelligence\ControlTowerService;
use App\Services\SellerIntelligence\DailyBriefingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Seller Home — the business overview for a period.
 *
 * It answers "how is the store doing", never "what do I do now"; that second question belongs to
 * the Control Tower, and mixing them is what turns a dashboard into wallpaper (handoff 01 §1).
 *
 * Every block loads independently. A finance service that is down takes out the payout card and
 * nothing else — a page that fails whole because one widget failed is a page a seller stops opening.
 */
class HomeController extends SellerCenterController
{
    private const PERIODS = ['today' => 1, '7d' => 7, '30d' => 30, '90d' => 90];

    public function __construct(
        private readonly DailyBriefingService $briefing,
        private readonly ControlTowerService $tower,
        private readonly VendorLedger $ledger,
        private readonly SellerScorecardService $scorecard,
        private readonly HomeMetrics $metrics,
    ) {
    }

    public function __invoke(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $period = $this->period($request);

        return view('seller-views.home', [
            'period' => $period,
            'periods' => array_keys(self::PERIODS),
            'compare' => $request->query('compare', '1') !== '0',
            'kpis' => $this->safely(fn () => $this->metrics->kpis($sellerId, $period)),
            'trend' => $this->safely(fn () => $this->metrics->trend($sellerId, $period)),
            'topProducts' => $this->safely(fn () => $this->metrics->topProducts($sellerId, $period)),
            'briefing' => $this->safely(fn () => $this->briefing->forSeller($sellerId)),
            'summary' => $this->safely(fn () => $this->tower->summary($sellerId)),
            'balances' => $this->safely(fn () => $this->ledger->balances($sellerId)),
            'withdrawable' => $this->safely(fn () => $this->ledger->withdrawable($sellerId)),
            'currency' => $this->ledger->baseCurrency(),
            'health' => $this->safely(fn () => $this->scorecard->scorecard($sellerId)),
        ]);
    }

    private function period(Request $request): string
    {
        $period = (string) $request->query('period', '7d');

        return array_key_exists($period, self::PERIODS) ? $period : '7d';
    }

    /**
     * Run one block's query, and return null rather than throwing.
     *
     * Null is the view's "this section could not load" signal. It is deliberately not an empty
     * array: a section that failed and a section with nothing in it need different renderings, and
     * conflating them tells a seller their sales are zero when the truth is the query timed out.
     */
    private function safely(callable $work): mixed
    {
        try {
            return $work();
        } catch (\Throwable) {
            return null;
        }
    }
}
