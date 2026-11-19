<?php

namespace App\Services\Notification;

use InvalidArgumentException;

/**
 * Every notification the platform sends, as necessary or marketing (I1-07). A necessary notification reports the state of something its recipient is part of or asked for: an order and
 * its refund, edit, due payment or delivery, a payment, wallet or withdrawal, an account, a chat the recipient takes part in, a code that proves who is asking, an alert the recipient
 * requested, and what only the platform or a seller reads. A marketing notification is one nobody asked for that invites a purchase or a visit: a reminder of a cart, a broadcast, a
 * template that offers something. A storefront that may not sell stops the marketing sent to its customers and never the necessary ones (StorefrontMarketingStop), so an order placed
 * before a stop is still told how it goes.
 *
 * A type is named by what the code already names it by. A push is its message key (the messages the admin edits, PushNotificationService) or, when it has none, the `type` it carries to the
 * app; an e-mail is its EmailTemplate key or, when it is sent without a template, the Mailable or the view it is sent with; a WhatsApp message is its automation event or the one-time code.
 * A scan test (NotificationTypeRegistryTest) fails for a type the code names that is not listed here and for a listed type no code names, so a new type is classified when it is added.
 * Where a type belongs to the recipient's own order, chat or code it is necessary even when its text is friendly; the two judgements worth a second look are marked.
 */
final class NotificationTypeRegistry
{
    public const NECESSARY = 'necessary';

    public const MARKETING = 'marketing';

    public const PUSH = 'push';

    public const EMAIL = 'email';

    public const WHATSAPP = 'whatsapp';

    public const ABANDONED_CART_REMINDER = 'AbandonedCartReminderMail';

    public const CART_MARKETING = 'cart_marketing';

    /** @var array<string, array<string, string>> channel => type => classification */
    private const TYPES = [
        self::PUSH => [
            // The state of an order
            'order_pending_message' => self::NECESSARY,
            'order_confirmation_message' => self::NECESSARY,
            'order_processing_message' => self::NECESSARY,
            'out_for_delivery_message' => self::NECESSARY,
            'order_delivered_message' => self::NECESSARY,
            'order_returned_message' => self::NECESSARY,
            'order_failed_message' => self::NECESSARY,
            'order_canceled' => self::NECESSARY,
            'order_edit_message' => self::NECESSARY,
            'order_edit_return_amount_message' => self::NECESSARY,
            'order_edit_due_payment_message' => self::NECESSARY,
            'order_rescheduled_message' => self::NECESSARY,
            'expected_delivery_date' => self::NECESSARY,
            'new_order_message' => self::NECESSARY,
            'new_order_assigned_message' => self::NECESSARY,
            'delivery_man_assign_by_admin_message' => self::NECESSARY,
            'delivery_man_charge' => self::NECESSARY,
            // Refunds
            'order_refunded_message' => self::NECESSARY,
            'refund_request_message' => self::NECESSARY,
            'refund_request_canceled_message' => self::NECESSARY,
            'refund_request_status_changed_by_admin' => self::NECESSARY,
            // Chat
            'message_from_admin' => self::NECESSARY,
            'message_from_seller' => self::NECESSARY,
            'message_from_delivery_man' => self::NECESSARY,
            'message_from_customer' => self::NECESSARY,
            // Wallet, cash collection and withdrawals
            'fund_added_by_admin_message' => self::NECESSARY,
            'cash_collect_by_admin_message' => self::NECESSARY,
            'cash_collect_by_seller_message' => self::NECESSARY,
            'withdraw_request_status_message' => self::NECESSARY,
            // An account, and what a seller asks the platform
            'customer_block_message' => self::NECESSARY,
            'customer_unblock_message' => self::NECESSARY,
            'product_request_approved_message' => self::NECESSARY,
            'product_request_rejected_message' => self::NECESSARY,
            // The orders of a referred customer are orders. The invitation to a referral is not (a judgement for the owner to confirm).
            'your_referred_customer_has_been_place_order' => self::NECESSARY,
            'your_referred_customer_order_has_been_delivered' => self::NECESSARY,
            'referral_code_used' => self::MARKETING,
            // Auctions the recipient joined or owns (the add-on)
            'auction_went_live' => self::NECESSARY,
            'auction_outbid' => self::NECESSARY,
            'auction_won' => self::NECESSARY,
            'auction_participation_payment_verified' => self::NECESSARY,
            'auction_claim_payment_verified' => self::NECESSARY,
            'auction_commission_payment_verified' => self::NECESSARY,
            'auction_delivery_ready' => self::NECESSARY,
            'auction_delivery_on_the_way' => self::NECESSARY,
            'auction_delivered' => self::NECESSARY,
            'auction_claim_expired' => self::NECESSARY,
            'auction_next_bidder' => self::NECESSARY,
            'auction_approved' => self::NECESSARY,
            'auction_denied' => self::NECESSARY,
            'auction_expired_result' => self::NECESSARY,
            'auction_new_participation' => self::NECESSARY,
            'auction_new_bid' => self::NECESSARY,
            'auction_item_claimed' => self::NECESSARY,
            'auction_claim_payment_verified_owner' => self::NECESSARY,
            'auction_withdrawal_approved' => self::NECESSARY,
            'auction_withdrawal_rejected' => self::NECESSARY,
            // The pushes that carry a type and no message key
            'order' => self::NECESSARY,
            'refund' => self::NECESSARY,
            'chatting' => self::NECESSARY,
            'wallet' => self::NECESSARY,
            'wallet_withdraw' => self::NECESSARY,
            'block' => self::NECESSARY,
            'notification' => self::MARKETING,
            // An alert the customer asked for, and what only the platform and its sellers read (a judgement for the owner to confirm: the restock alert)
            'product_restock_update' => self::NECESSARY,
            'maintenance_mode' => self::NECESSARY,
            'seller_operations' => self::NECESSARY,
            'theme_version_changed' => self::NECESSARY,
            'demo_reset' => self::NECESSARY,
        ],

        self::EMAIL => [
            'registration' => self::NECESSARY,
            'registration-approved' => self::NECESSARY,
            'registration-denied' => self::NECESSARY,
            'registration-from-pos' => self::NECESSARY,
            'registration-verification' => self::NECESSARY,
            'account-activation' => self::NECESSARY,
            'account-suspended' => self::NECESSARY,
            'account-block' => self::NECESSARY,
            'account-unblock' => self::NECESSARY,
            'forgot-password' => self::NECESSARY,
            'reset-password-verification' => self::NECESSARY,
            'digital-product-download' => self::NECESSARY,
            'digital-product-otp' => self::NECESSARY,
            'order-place' => self::NECESSARY,
            'order-delivered' => self::NECESSARY,
            'order-received' => self::NECESSARY,
            'add-fund-to-wallet' => self::NECESSARY,
            'AddFundToWallet' => self::NECESSARY,
            'OrderReceivedNotifySeller' => self::NECESSARY,
            'TestEmailSender' => self::NECESSARY,
            'email-templates.customer-message' => self::NECESSARY,
            self::ABANDONED_CART_REMINDER => self::MARKETING,
        ],

        self::WHATSAPP => [
            'order_created' => self::NECESSARY,
            'order_status_changed' => self::NECESSARY,
            'otp' => self::NECESSARY,
            self::CART_MARKETING => self::MARKETING,
        ],
    ];

    /** @throws InvalidArgumentException for a type nobody classified, because guessing would stop an order's message or send a marketing one */
    public static function classificationOf(string $channel, string $type): string
    {
        return self::TYPES[$channel][$type] ?? throw new InvalidArgumentException("The {$channel} notification type [{$type}] is not classified as necessary or marketing: list it in " . self::class . '.');
    }

    public static function isMarketing(string $channel, string $type): bool
    {
        return self::classificationOf(channel: $channel, type: $type) === self::MARKETING;
    }

    /** @return array<string, array<string, string>> channel => type => classification */
    public static function all(): array
    {
        return self::TYPES;
    }
}
