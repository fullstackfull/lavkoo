<?php

namespace App\Services\Marketplace;

use App\Models\ApprovalRequest;
use App\Models\SellerSlaBreach;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many issues of the shops each market has open, for a monitor to report (L-09).
 *
 * It lives here, beside `SellerMarketScope`, and NOT in the platform-admin area, for a reason the platform admin's own guard states: nothing
 * under `App\Services\PlatformAdmin` may name the seller world at all (`ReverseIsolationTest`), because that area has an identity of its own
 * and must never be able to reach a seller's credentials or sign in through the seller guard. Counting shop issues is honest monitoring data
 * rather than a credential, but the rule that keeps the two apart is worth more than the convenience of breaking it, so the platform monitor
 * asks this for counts and never touches a shop itself.
 *
 * **Which market an issue is in.** The shop's, and only when the shop is granted exactly one (`SellerMarketScope::soleMarketOfShop()`). A shop
 * granted several belongs to no single market, and a shop granted none is not narrowed at all; both are counted under the empty key, which the
 * monitor reports apart. Putting them under the first market found would be a number somebody acts on.
 *
 * **Both figures are backlogs, not windows.** An SLA breach is open until somebody clears it and an approval is pending until somebody decides
 * it; neither stops being true because time passed, so neither is bounded by the window a monitor was asked for.
 *
 * Neither table has a create migration in this repository -- both arrive with the installation dump -- so an installation without them reads
 * zero rather than failing, which is the truth about it.
 */
#[Scoped]
final class ShopIssueCounts
{
    private const BREACHES = 'seller_sla_breaches';

    private const APPROVALS = 'approval_requests';

    /** What `requested_by_type` says of an approval a shop asked for. */
    private const ASKED_BY_A_SHOP = 'seller';

    /** The key the counts of "no single market" are kept under. */
    private const NO_MARKET = '';

    public function __construct(private readonly SellerMarketScope $markets)
    {
    }

    /**
     * Shop issues nobody has cleared, by market.
     *
     * @return array<string, int> keyed by market code, with the empty string for the shops no single market owns
     */
    public function openIssuesByMarket(): array
    {
        if (!Schema::hasTable(self::BREACHES)) {
            return [];
        }

        $counts = [];

        $rows = DB::table(self::BREACHES)
            ->where('status', SellerSlaBreach::STATUS_OPEN)
            ->groupBy('seller_id')
            ->selectRaw('seller_id, COUNT(*) as breaches')
            ->get();

        foreach ($rows as $row) {
            $key = $this->marketOfShop($row->seller_id ?? null);
            $counts[$key] = ($counts[$key] ?? 0) + (int) $row->breaches;
        }

        return $counts;
    }

    /**
     * Approval requests nobody has decided, by the market of the shop that asked for one.
     *
     * A request is attributed only when `requested_by_type` says a shop asked for it. Administrator ids and shop ids are separate sequences,
     * so reading the id alone would put an administrator's request in the market of whichever shop happens to carry that id.
     *
     * @return array<string, int>
     */
    public function pendingApprovalsByMarket(): array
    {
        if (!Schema::hasTable(self::APPROVALS)) {
            return [];
        }

        $counts = [];

        $rows = DB::table(self::APPROVALS)
            ->where('status', ApprovalRequest::STATUS_PENDING)
            ->select(['requested_by', 'requested_by_type'])
            ->get();

        foreach ($rows as $row) {
            $key = ($row->requested_by_type ?? null) === self::ASKED_BY_A_SHOP
                ? $this->marketOfShop($row->requested_by ?? null)
                : self::NO_MARKET;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    private function marketOfShop(int|string|null $shopId): string
    {
        return $shopId === null ? self::NO_MARKET : (string) ($this->markets->soleMarketOfShop(sellerId: $shopId) ?? self::NO_MARKET);
    }
}
