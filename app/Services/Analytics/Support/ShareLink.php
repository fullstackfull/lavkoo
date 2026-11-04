<?php

namespace App\Services\Analytics\Support;

/**
 * Tags a link this shop hands out, so the traffic it brings back can be told apart.
 *
 * The campaign builder produces tagged links for anything an administrator creates. Everything the
 * SHOP itself hands out was untagged: the refer-a-friend link a customer sends, the social share
 * buttons on a product, the "track your order" button in a confirmation email. All of that came
 * back as (direct)/(none), so the referral programme looked like it produced nothing and a share
 * that lost its referrer looked like somebody typing the URL — the two sections were built at
 * different times and neither knew the other existed.
 *
 * Deliberately a thin wrapper over CampaignDestination::withUtm(): the tagging rules — existing
 * query preserved, campaign parameters win — are already written there, and a second copy would
 * drift from the first.
 */
class ShareLink
{
    public function __construct(private readonly CampaignDestination $destination)
    {
    }

    /**
     * @param  array<string, string|null>  $extra  utm_content, utm_term where they add something
     */
    public function tag(string $url, string $source, string $medium, string $campaign, array $extra = []): string
    {
        return $this->destination->withUtm($url, array_merge([
            'utm_source' => $source,
            'utm_medium' => $medium,
            'utm_campaign' => $campaign,
        ], $extra));
    }

    /** A customer sharing a product. The network is the source, so each button is told apart. */
    public function forSocialShare(string $url, string $network): string
    {
        return $this->tag($url, $network, 'social', 'product_share');
    }

    /** A customer's own referral link. */
    public function forReferral(string $url): string
    {
        return $this->tag($url, 'referral', 'customer_referral', 'refer_a_friend');
    }

    /** A link inside a transactional email, which is not marketing but is still not direct. */
    public function forTransactionalEmail(string $url, string $campaign): string
    {
        return $this->tag($url, 'email', 'transactional', $campaign);
    }
}
