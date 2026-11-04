<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\SellerAutomationAction;
use App\Services\SellerAutomation\Actions\HideListingAction;
use App\Services\SellerAutomation\Actions\PublishListingAction;
use Illuminate\Support\Facades\Schema;

/**
 * Lets go of a listing as soon as somebody else decides its visibility.
 *
 * Automation only claims what it did, and a listing it hid is its to put back only while nothing
 * else has touched it. Without this, a seller who republished a hidden line by hand and later hid it
 * again on purpose was republished by the rule that hid it months earlier — the trail still said the
 * last thing automation did to that listing was hide it, which was true and no longer the point.
 *
 * An observer rather than a call in each controller: a product's status is written from the admin
 * panel, the vendor panel, three API versions and the bulk importer, and the next writer will be
 * added by somebody who never heard of automation. Automation's own change is recorded after the
 * save, so a rule never releases the claim it is in the middle of making.
 */
class AutomationClaimObserver
{
    /** The actions whose claim is about visibility, and therefore what a status change releases. */
    private const VISIBILITY_ACTIONS = [HideListingAction::KEY, PublishListingAction::KEY];

    public function updated(Product $product): void
    {
        if (!$product->wasChanged('status')) {
            return;
        }

        try {
            if (!Schema::hasTable('seller_automation_actions')) {
                return;
            }

            SellerAutomationAction::where('subject_type', SellerAutomationAction::SUBJECT_PRODUCT)
                ->where('subject_id', $product->id)
                ->whereIn('action', self::VISIBILITY_ACTIONS)
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now()]);
        } catch (\Throwable) {
            // The save has already succeeded — never undo a merchant's own change over a claim.
        }
    }
}
