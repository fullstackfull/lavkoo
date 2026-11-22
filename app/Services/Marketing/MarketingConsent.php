<?php

namespace App\Services\Marketing;

/**
 * The names a consent decision is filed under, in one place, so a sender and the gate can never disagree by a typo.
 *
 * A CHANNEL is how the message travels. A PURPOSE is what the message is for. Both are part of the key, because
 * agreeing to a newsletter is not agreeing to a WhatsApp template, and agreeing to be reminded about a cart is not
 * agreeing to a campaign.
 *
 * All four channels are listed, not only the two that have a gated sender today: a decision is stored against the
 * channel it was given for, and MarketingUnsubscribeLink refuses a token naming anything outside these lists. On main
 * there is no SMS marketing sender at all, and the push broadcast goes to one global topic with no per-customer
 * recipient to ask the gate about (tracker I1-05), so neither is gated yet.
 */
final class MarketingConsent
{
    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_PUSH = 'push';

    public const CHANNELS = [self::CHANNEL_EMAIL, self::CHANNEL_SMS, self::CHANNEL_WHATSAPP, self::CHANNEL_PUSH];

    public const PURPOSE_NEWSLETTER = 'newsletter';

    public const PURPOSE_ABANDONED_CART = 'abandoned_cart';

    public const PURPOSE_CART_MARKETING = 'cart_marketing';

    public const PURPOSE_BROADCAST = 'broadcast';

    public const PURPOSES = [self::PURPOSE_NEWSLETTER, self::PURPOSE_ABANDONED_CART, self::PURPOSE_CART_MARKETING, self::PURPOSE_BROADCAST];

    public const POLICY_OPT_IN = 'opt_in';

    public const POLICY_OPT_OUT = 'opt_out';

    public const SOURCE_REGISTRATION = 'registration';

    public const SOURCE_ACCOUNT_PAGE = 'account_page';

    public const SOURCE_NEWSLETTER_FORM = 'newsletter_form';

    public const SOURCE_UNSUBSCRIBE_LINK = 'unsubscribe_link';

    /**
     * A market spelled the one way it is stored and compared: an upper-case ISO code.
     *
     * THERE IS ONE RULE AND IT LIVES HERE, because the two engines disagree about the rest. The column is utf8mb4
     * with a case-INSENSITIVE collation on MySQL and MariaDB, where 'sa' finds a row stored as 'SA'; SQLite compares
     * text BINARY, where it does not. Normalising on the write and leaving the read alone would therefore have made
     * the same call answer differently on the two engines — found by self-review, not by a failing test, because no
     * caller passes a lower-case market today.
     */
    public static function market(string $market): string
    {
        return mb_strtoupper(trim($market));
    }
}
