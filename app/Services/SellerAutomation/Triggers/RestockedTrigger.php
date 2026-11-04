<?php

namespace App\Services\SellerAutomation\Triggers;

use App\Models\SellerAutomationAction;
use App\Services\SellerAutomation\Actions\HideListingAction;
use App\Services\SellerAutomation\Actions\PublishListingAction;
use App\Services\SellerAutomation\AutomationTrigger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Listings automation took down, that now have stock again.
 *
 * This is the half that makes hiding safe. Without it a rule that hides a stockout leaves a shop
 * that quietly shrinks every month, and the seller has to remember which listings the marketplace
 * switched off for them.
 *
 * It will only put back what automation itself took down. A listing the seller hid deliberately —
 * a discontinued line, something under review, a product they are not ready to sell — stays hidden
 * however much stock arrives, because the shop is theirs and a rule they wrote about stock levels
 * is not consent to republish everything in it.
 */
class RestockedTrigger implements AutomationTrigger
{
    use SelectsSimpleProducts;

    public const KEY = 'restocked_after_automation_hid_it';

    public function key(): string
    {
        return self::KEY;
    }

    public function subjectType(): string
    {
        return SellerAutomationAction::SUBJECT_PRODUCT;
    }

    public function rules(): array
    {
        return ['threshold' => 'nullable|integer|min:1|max:1000'];
    }

    public function match(int $sellerId, array $settings, int $limit, array $scope = []): Collection
    {
        if (!Schema::hasTable('products') || !Schema::hasTable('seller_automation_actions')) {
            return collect();
        }

        $hidden = $this->hiddenByAutomation($sellerId);

        if ($hidden === []) {
            return collect();
        }

        $products = $this->sellerProducts($sellerId, $scope)
            ->where('status', 0)
            ->where('current_stock', '>=', (int) ($settings['threshold'] ?? 1))
            ->whereIn('id', $hidden)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->withoutVariants($products);
    }

    /**
     * Products whose most recent automated visibility change was a hide.
     *
     * Read as "the last thing automation did to this listing, and nobody has touched it since",
     * rather than "automation ever hid it": a listing that was hidden, republished, and then hidden
     * again by the seller's own hand is not claimed by a rule that had nothing to do with it.
     *
     * @return array<int, int>
     */
    private function hiddenByAutomation(int $sellerId): array
    {
        $latest = DB::table('seller_automation_actions')
            ->where('seller_id', $sellerId)
            ->where('subject_type', SellerAutomationAction::SUBJECT_PRODUCT)
            ->where('status', SellerAutomationAction::STATUS_APPLIED)
            ->whereNull('reverted_at')
            ->whereIn('action', [HideListingAction::KEY, PublishListingAction::KEY])
            ->selectRaw('subject_id, MAX(id) as last_id')
            ->groupBy('subject_id')
            ->pluck('last_id', 'subject_id');

        if ($latest->isEmpty()) {
            return [];
        }

        return DB::table('seller_automation_actions')
            ->whereIn('id', $latest->values()->all())
            ->where('action', HideListingAction::KEY)
            // Not if somebody has decided this listing's visibility since. The seller republishing
            // it by hand and hiding it again later is their decision, not a stockout.
            ->whereNull('superseded_at')
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
