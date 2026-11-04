<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\BrandClaim;
use App\Models\BrandClaimDocument;
use App\Models\BusinessSetting;
use App\Models\Seller;
use App\Services\Marketplace\BrandRegistryService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The marketplace's side of the brand registry: a queue of claims and one switch.
 *
 * Every decision here is a person's. Nothing on this page computes a verification, infers ownership
 * from a document having been uploaded, or approves anything because time passed — a brand registry
 * that did any of those would be worse than none, because sellers would believe it.
 *
 * The switch is deliberately separate from the queue and deliberately off. Enforcement takes a
 * marketplace that works today and starts refusing listings, so it is armed once, on purpose, with
 * the affected counts on the same screen — not as a side effect of shipping the feature.
 */
class BrandRegistryController extends BaseController
{
    public function __construct(private readonly BrandRegistryService $registry)
    {
    }

    public function index(Request|null $request = null, ?string $type = null): View
    {
        $claims = collect();
        $sellers = collect();
        $statusFilter = $request?->input('status');

        if (Schema::hasTable('brand_claims')) {
            $claims = BrandClaim::with(['documents', 'brand'])
                ->when($statusFilter, fn ($query) => $query->where('status', $statusFilter))
                // Waiting first, then newest. A reviewer opening this page is here to clear a queue,
                // not to browse a history.
                ->orderByRaw('CASE WHEN status IN (?, ?) THEN 0 ELSE 1 END', [
                    BrandClaim::STATUS_SUBMITTED, BrandClaim::STATUS_UNDER_REVIEW,
                ])
                ->orderByDesc('submitted_at')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString();

            $sellerIds = collect($claims->items())->pluck('seller_id')->unique();
            if (Schema::hasTable('sellers') && $sellerIds->isNotEmpty()) {
                $sellers = Seller::whereIn('id', $sellerIds)->get()->keyBy('id');
            }
        }

        // How many listings each decision would affect, counted before it is taken rather than
        // discovered afterwards. One grouped query for the whole page rather than two per row.
        $exposure = $this->exposureFor($claims);

        return view('admin-views.marketplace.brand-registry', [
            'claims' => $claims,
            'sellers' => $sellers,
            'exposure' => $exposure,
            'statusFilter' => $statusFilter,
            'enforcing' => $this->registry->isEnforcing(),
        ]);
    }

    /**
     * Listings per (brand, seller) for the claims on this page.
     *
     * A reviewer approving an ownership claim on a brand four other shops already sell under should
     * see that before they click, not after — and seeing it should not cost a query per row.
     *
     * @return array<int, array{own: int, others: int}>
     */
    private function exposureFor(iterable $claims): array
    {
        $brandIds = collect($claims)->pluck('brand_id')->unique()->values();

        if ($brandIds->isEmpty() || !Schema::hasTable('products')) {
            return [];
        }

        $counts = \Illuminate\Support\Facades\DB::table('products')
            ->whereIn('brand_id', $brandIds)
            ->where('added_by', 'seller')
            ->groupBy('brand_id', 'user_id')
            ->selectRaw('brand_id, user_id, COUNT(*) as listings')
            ->get();

        $exposure = [];

        foreach ($claims as $claim) {
            $forBrand = $counts->where('brand_id', $claim->brand_id);

            $exposure[$claim->id] = [
                'own' => (int) $forBrand->where('user_id', $claim->seller_id)->sum('listings'),
                'others' => (int) $forBrand->where('user_id', '!=', $claim->seller_id)->sum('listings'),
            ];
        }

        return $exposure;
    }

    /** Stream one piece of evidence. Reachable only behind the admin middleware. */
    public function document(int $id)
    {
        $document = BrandClaimDocument::findOrFail($id);
        $path = $this->registry->documentPath($document);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    public function approve(Request $request): RedirectResponse
    {
        $claim = BrandClaim::findOrFail($request['claim_id']);

        $result = $this->registry->approve(
            claim: $claim,
            reviewer: auth('admin')->id(),
            note: $request['note'],
            expiresAt: $request['expires_at'],
        );

        // Said out loud rather than resolved silently. Two shops approved for one brand is a real
        // situation — an owner and their distributor — and also how a mistake looks.
        if (!empty($result['conflicts'])) {
            ToastMagic::warning(translate('another_seller_is_also_approved_for_this_brand'));
        }

        ToastMagic::success(translate('brand_claim_approved'));

        return back();
    }

    public function reject(Request $request): RedirectResponse
    {
        $claim = BrandClaim::findOrFail($request['claim_id']);
        $this->registry->reject($claim, auth('admin')->id(), $request['note']);

        ToastMagic::success(translate('brand_claim_rejected'));

        return back();
    }

    public function revoke(Request $request): RedirectResponse
    {
        $claim = BrandClaim::findOrFail($request['claim_id']);
        $result = $this->registry->revoke($claim, auth('admin')->id(), $request['note']);

        if (!$result['ok']) {
            ToastMagic::error(translate($result['reason']));

            return back();
        }

        ToastMagic::success(translate('brand_claim_revoked'));

        return back();
    }

    /**
     * Arm or disarm the gate.
     *
     * Nothing else on this page changes what sellers can do; this changes it for everybody at once,
     * which is why it is its own action with its own confirmation rather than a checkbox beside a
     * claim.
     */
    public function updateEnforcement(Request $request): RedirectResponse
    {
        BusinessSetting::updateOrCreate(
            ['type' => BrandRegistryService::ENFORCEMENT_SETTING],
            ['value' => $request->boolean('enforce') ? '1' : '0'],
        );

        ToastMagic::success(translate('settings_updated'));

        return back();
    }
}
