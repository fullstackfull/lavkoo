<?php

namespace App\Providers;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Banner;
use App\Models\Coupon;
use App\Models\DealOfTheDay;
use App\Models\FeatureDeal;
use App\Models\FlashDeal;
use App\Models\MostDemanded;
use App\Models\OfflinePaymentMethod;
use App\Models\Order;
use App\Models\Product;
use App\Models\RefundRequest;
use App\Models\StockClearanceSetup;
use App\Models\LoyaltyPointTransaction;
use App\Models\StorefrontLegalDocument;
use App\Models\VendorPayoutRequest;
use App\Models\WalletTransaction;
use App\Models\WithdrawalMethod;
use App\Observers\AuditTrailObserver;
use App\Observers\AutomationClaimObserver;
use App\Observers\MarketShadowWriteObserver;
use App\Observers\ProductPriceObserver;
use App\Observers\ProductSearchIndexObserver;
use App\Observers\SellerWebhookEventObserver;
use App\Observers\StorefrontLegalDocumentObserver;
use Illuminate\Support\ServiceProvider;

class ObserverServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Keeps the normalised search index in step with the catalogue however a product is written
        // — admin panel, vendor panel, three API versions or the bulk importer. The observer
        // swallows its own failures, so an index write can never fail a product save.
        // Records every price change, whoever made it. Same reasoning as the index observer and
        // more pressing: a price moved at seven call sites in one service alone, and none of them
        // wrote down what it had been.
        // And releases automation's claim on a listing the moment anybody else changes whether it
        // is visible, so a rule cannot put back something the seller took down on purpose.
        Product::observe([
            ProductSearchIndexObserver::class,
            ProductPriceObserver::class,
            SellerWebhookEventObserver::class,
            AutomationClaimObserver::class,
        ]);

        // A seller's webhooks are raised from the model rather than from the call sites, for the
        // same reason the price history is: an order's status is written from the admin panel, the
        // vendor panel, three API versions, the delivery partner's callback and a queued job, and
        // the next writer will be added by somebody who never heard of webhooks.
        Order::observe(SellerWebhookEventObserver::class);
        RefundRequest::observe(SellerWebhookEventObserver::class);
        VendorPayoutRequest::observe(SellerWebhookEventObserver::class);

        // The business-page lists of the storefronts are built from their legal texts, so a text saved or deleted through the model clears them.
        StorefrontLegalDocument::observe(StorefrontLegalDocumentObserver::class);

        // Mirrors every movement of a customer's balance into the account of the market it names, while the shadow key is on (E-07). It
        // sits on the two ledgers rather than on the five wallet writers and the two points writers: the row carries the market and the
        // amounts already, and a mirror at each writer would be seven copies of one rule. It cannot fail the write it follows.
        WalletTransaction::observe(MarketShadowWriteObserver::class);
        LoyaltyPointTransaction::observe(MarketShadowWriteObserver::class);

        // Who changed what a customer pays, which gateway takes the money, and who may sign in to
        // the panel. Same argument as the two above: a coupon is edited from the admin panel and
        // the vendor panel, an employee's role from three screens, and a list of call sites goes
        // stale the moment somebody adds a fourth. The settings tables are not here — they are
        // written with mass updates that raise no event, and are caught by AuditedBuilder instead.
        foreach ([
            Coupon::class, FlashDeal::class, DealOfTheDay::class, FeatureDeal::class,
            Banner::class, MostDemanded::class, StockClearanceSetup::class,
            OfflinePaymentMethod::class, WithdrawalMethod::class,
            AdminRole::class, Admin::class,
        ] as $audited) {
            $audited::observe(AuditTrailObserver::class);
        }
    }
}
