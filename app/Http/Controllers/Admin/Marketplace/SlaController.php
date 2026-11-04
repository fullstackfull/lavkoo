<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\BusinessSetting;
use App\Models\Seller;
use App\Models\SellerSlaBreach;
use App\Services\Marketplace\SlaService;
use App\Services\Platform\Policy;
use App\Services\Platform\PolicyRegistry;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Admin seller SLA policy (Phase 3, Stage E). Configure the thresholds, run an evaluation across
 * sellers, and read the breach ledger. The comparison and reconcile live in SlaService.
 */
class SlaController extends BaseController
{
    /** The operations windows are declared with every other platform rule; this page edits that group. */
    private const POLICY_GROUP = 'operations';

    public function __construct(
        private readonly SlaService $sla,
        private readonly Policy $policy,
    ) {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $breaches = collect();
        $paginator = null;
        $sellers = collect();

        if (Schema::hasTable('seller_sla_breaches')) {
            $query = SellerSlaBreach::query()->orderByRaw("status = 'open' desc")->orderByDesc('id');
            if ($request?->input('status')) {
                $query->where('status', $request->input('status'));
            }
            $paginator = $query->paginate(25)->withQueryString();
            $breaches = collect($paginator->items());

            $sellerIds = $breaches->pluck('seller_id')->unique();
            if (Schema::hasTable('sellers') && $sellerIds->isNotEmpty()) {
                $sellers = Seller::whereIn('id', $sellerIds)->get()->keyBy('id');
            }
        }

        return view('admin-views.marketplace.sla', [
            'thresholds' => $this->sla->thresholds(),
            // The windows the detectors judge by, on the same page as the rates they sit beside —
            // they are the same policy, and the seller feels them the same way.
            'operations' => $this->policy->all(self::POLICY_GROUP),
            'operationFields' => PolicyRegistry::GROUPS[self::POLICY_GROUP]['policies'],
            'breaches' => $breaches,
            'paginator' => $paginator,
            'sellers' => $sellers,
            'statusFilter' => $request?->input('status'),
            'openCount' => Schema::hasTable('seller_sla_breaches') ? SellerSlaBreach::open()->count() : 0,
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sla_max_cancellation_rate' => 'required|numeric|min:0|max:1',
            'sla_max_return_rate' => 'required|numeric|min:0|max:1',
            'sla_max_refund_rate' => 'required|numeric|min:0|max:1',
            'sla_min_rating' => 'required|numeric|min:0|max:5',
            // Whole hours, and at least one: a zero-hour deadline would mark every order late the
            // instant it arrived.
            'sla_processing_hours' => 'required|integer|min:1|max:720',
        ] + $this->policy->rules(self::POLICY_GROUP));

        // The operations windows go through the policy service so the write is clamped and audited
        // like every other platform rule; the four rate thresholds are this page's own settings.
        $this->policy->save(array_intersect_key($validated, $this->policy->rules(self::POLICY_GROUP)));

        foreach (array_diff_key($validated, $this->policy->rules(self::POLICY_GROUP)) as $type => $value) {
            BusinessSetting::updateOrCreate(['type' => $type], ['value' => (string) $value]);
        }
        cache()->flush();
        ToastMagic::success(translate('sla_policy_updated'));

        return back();
    }

    public function evaluate(): RedirectResponse
    {
        $evaluated = $this->sla->evaluateAll();
        $open = Schema::hasTable('seller_sla_breaches') ? SellerSlaBreach::open()->count() : 0;
        ToastMagic::success(translate('evaluated') . ' ' . $evaluated . ' ' . translate('sellers') . ' — ' . $open . ' ' . translate('open_breaches'));

        return back();
    }
}
