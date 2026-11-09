<?php

namespace App\Services\SalesChannels\Support;

/**
 * Every operation this application has REGISTERED but never calls — each with the reason, in one place.
 *
 * ## Why a registered operation that nothing calls is worth a file of its own
 *
 * Registering an operation is a promise: it names a path, a method, the capabilities it needs and the approval it relies on,
 * and the transport will carry it. Twenty of the hundred and five registered here are never named by any code. Read charitably
 * that is forward planning; read honestly it is the difference between "this integration does that" and "this integration
 * could". The gap matrix kept saying the first about several of the second, and the only cure is a list that the tests check.
 *
 * ## The four kinds, and they are not interchangeable
 *
 *  - `DECIDED` — built deliberately no further. Somebody weighed it and the answer was no. Cancelling an order's lines locks
 *    the product at the channel; sending a patient's invoice needs a public URL this pharmacy will not publish.
 *  - `NOT_BUILT` — ordinary unfinished work. No obstacle, nobody has done it. This is the honest label for most of the list
 *    and the one the matrix used to blur.
 *  - `NEEDS_AN_ACCOUNT` — the code could be written but could not be verified without a real seller account or credentials
 *    nobody here holds, so writing it would be writing against a guess.
 *  - `PARTLY_BUILT` — some of the pieces exist and the operation itself is still not made. Amazon's feeds WERE the case and
 *    are no longer: the document and the result reader were built with nothing to send them, and `AmazonFeedSubmitter` is
 *    now that cycle. The kind is kept because it is the honest label the next such case will need.
 *
 * `ChannelOperationHygieneTest` compares this list against a scan of the code. An operation that stops being called without
 * being listed here fails; one listed here that something starts calling fails too. So the list cannot rot in either
 * direction, which is what went wrong before.
 */
final class UncalledOperations
{
    public const DECIDED = 'decided';

    public const NOT_BUILT = 'not_built';

    public const NEEDS_AN_ACCOUNT = 'needs_an_account';

    public const PARTLY_BUILT = 'partly_built';

    /**
     * @return array<string, array{kind: string, why: string}>
     */
    public static function all(): array
    {
        return [
            // ---- Amazon feeds: now wired, and so no longer listed here --------------------------------------------
            //
            // `amazon.feeds_create_document`, `feeds_upload`, `feeds_create`, `feeds_get`, `feeds_document`, `feeds_list` and
            // `feed_report_download` were all PARTLY_BUILT: the submission document and the report reader existed and
            // nothing sent one. `AmazonFeedSubmitter` is that missing cycle -- the five calls in Amazon's published order,
            // the `amazon_feed_submissions` row that keeps the `feedId` and the messageId-to-SKU map across them, and the
            // write-ahead fence that stops one batch being queued twice where Amazon documents no idempotency at all.
            //
            // They are deliberately NOT replaced with another entry. The path has its own switch
            // (`sales_channels.amazon.bulk_feeds_enabled`) which ships off, and per-SKU publishing stays the default --
            // but that is a configuration decision, not an unbuilt operation, and recording a built thing as unbuilt is the
            // blurring this class exists to stop.

            // ---- Amazon notifications: no documentation problem, an infrastructure one ----------------------------
            // There is NO `ORDER_CREATED` notification type at all; `ORDER_CHANGE` is "order status changes and buyer
            // requested cancelations", about an order that already exists. So notifications can only ever make the order
            // cycle faster and never replace the polling that finds an order. And reading the queue needs the seller's own
            // AWS account and a standard SQS queue whose policy grants `arn:aws:iam::437568002678:root`.
            'amazon.notifications_read' => ['kind' => self::NEEDS_AN_ACCOUNT, 'why' => 'amazon_notifications_need_the_sellers_own_aws_queue'],
            'amazon.notifications_write' => ['kind' => self::NEEDS_AN_ACCOUNT, 'why' => 'amazon_notifications_need_the_sellers_own_aws_queue'],


            // ---- Trendyol: lookups nothing asks for yet ----------------------------------------------------------
            // Category attributes ARE read (that is `category_attributes`); the category TREE is not, because a listing is
            // created against a category a person chose on the product screen rather than browsed here.
            'trendyol.categories' => ['kind' => self::NOT_BUILT, 'why' => 'trendyol_category_tree_is_not_browsed_here'],
            'trendyol.product_by_barcode' => ['kind' => self::NOT_BUILT, 'why' => 'trendyol_single_barcode_lookup_is_not_used'],
            // Archiving and unlocking a product. Both change a listing's life and neither is reachable from a screen yet.
            'trendyol.products_archive' => ['kind' => self::NOT_BUILT, 'why' => 'trendyol_archiving_has_no_screen'],
            'trendyol.products_unlock' => ['kind' => self::NOT_BUILT, 'why' => 'trendyol_unlocking_has_no_screen'],
            // **A DECISION, and the owner has taken it.** Invoices stay with the Trendyol seller panel: the contract is
            // verified, and what is missing is a URL reachable without a login for ten years in Saudi Arabia. Publishing one
            // would put a customer's name, address, telephone and medicines behind a link anybody holding it could read.
            'trendyol.orders_invoice_link' => ['kind' => self::DECIDED, 'why' => 'trendyol_invoices_stay_with_the_seller_panel'],
            // **A DECISION.** Cancelling lines cancels a customer's order and locks the product at Trendyol.
            'trendyol.orders_unsupplied' => ['kind' => self::DECIDED, 'why' => 'trendyol_cancelling_lines_locks_the_product'],

            // ---- Odoo -------------------------------------------------------------------------------------------
            // Listing the instance's models. The preflight asks about the models it needs BY NAME and reports what is
            // missing, which is the narrower and safer question.
            // ---- noon: the one the closed guard hole left behind ---------------------------------------------------
            // The hygiene guard counted an operation as called at two mentions, and `NoonOperations` names each of its
            // operations twice -- once in `operations()`, once in the path map -- so every noon operation passed whether
            // anything called it or not. Three were genuinely uncalled. Two are built now; this is the third.
            //
            // The path and the body are known and the capability matrix already said `built => false`: it shows where noon
            // sends events, with credentials redacted on read. CREATING a destination is a separate operation whose page has
            // not been read, so a webhook is configured in noon's own portal for now, and a list with nothing to compare it
            // against would be a screen with no decision on it.
            'noon.webhook_destinations_list' => ['kind' => self::NOT_BUILT, 'why' => 'noon_event_destinations_are_configured_in_noons_own_portal'],

            'odoo.model_list' => ['kind' => self::NOT_BUILT, 'why' => 'odoo_preflight_asks_about_named_models_instead'],
        ];
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /** @return list<string> */
    public static function ofKind(string $kind): array
    {
        return array_keys(array_filter(self::all(), static fn (array $entry): bool => $entry['kind'] === $kind));
    }

    public static function kindOf(string $operation): ?string
    {
        return self::all()[$operation]['kind'] ?? null;
    }

    public static function whyOf(string $operation): ?string
    {
        return self::all()[$operation]['why'] ?? null;
    }
}
