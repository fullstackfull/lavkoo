<?php

namespace App\Http\Controllers\Admin\SalesChannels\Support;

use App\Http\Controllers\Admin\SalesChannels\ChannelLabels;
use App\Models\ChannelListingDraft;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Listings\ListingPublisher;
use App\Services\SalesChannels\Listings\ProductListingReadiness;
use App\Services\SalesChannels\Listings\RegulatedProductGate;

/**
 * The words the listing screens use for the codes the listing pipeline produces, and only for those.
 *
 * Why the lists are closed: `translate()` writes an unknown key into the language files, and two of the three things a listing screen shows do not come from us — the field
 * names a channel says it requires, and the sentences a channel writes when it refuses. Those are shown exactly as they came. OURS and PROBLEMS are the codes our own code
 * produces, each with a translation of its own; a code in neither is shown as it came, so a new backend code can never dirty the repository or appear half-translated.
 *
 * A call the transport refused before anything left this host is not given words of its own here: it says the same thing on an account page, so ChannelLabels says it.
 */
final class ListingLabels
{
    public const NONE = ChannelLabels::NONE;

    /** Reasons of our own, each with its own translation. */
    public const OURS = [
        // The gates, in the order ListingPublisher checks them (the account being off is the transport's word, below).
        'listing_publishing_off', 'product_sync_capability_off', 'product_writes_approval_missing', 'channel_cannot_create_listings',
        'draft_without_a_product', 'draft_without_our_own_sku', 'listing_already_submitted', 'listing_already_accepted',
        // What the draft itself is missing.
        'listing_product_type_missing', 'product_data_not_ready', 'listing_requirements_unknown', 'listing_fields_missing',
        'listing_has_nothing_to_send_that_is_not_an_offer',
        // The pharmacy gate.
        RegulatedProductGate::NEEDS_CLEARANCE, RegulatedProductGate::CLASSIFICATION_MISSING, RegulatedProductGate::CLEARANCE_NOT_PERMITTED,
        'regulatory_clearance_needs_a_reference',
        // Whose SKU it is, and what the channel holds.
        'listing_key_belongs_to_another_product', 'listing_already_exists', 'sku_already_listed_at_the_channel_and_not_linked_here',
        'could_not_read_what_the_channel_holds', 'could_not_confirm_that_the_channel_holds_no_listing',
        // The write itself.
        'listing_write_failed', 'channel_refused_the_listing', 'listing_not_waiting_for_the_channel',
        // Asking the channel's own CATALOGUE about a barcode. `could_not_ask` and the three beneath it are the honest "we do not know", and a screen must never show them as
        // an empty catalogue: that is what invites a second listing of a product that is already listed.
        'catalog_not_read_yet', 'catalog_candidate_not_offered', 'catalog_no_identifier_the_catalogue_knows',
        'catalog_rehearsal', 'catalog_channel_unreachable', 'catalog_channel_has_no_catalogue',
        'catalog_product_has_no_barcode_or_code', 'catalog_account_is_gone',
    ];

    /**
     * The reasons that belong to the transport, not to this pipeline: a ChannelUnavailableException's own code (the publisher prefixes it with `channel_`), the channel asking
     * us to wait, and the account being off — which the gate reports bare. Each is shown in the words the account pages use.
     */
    public const CHANNEL_REFUSALS = [
        ChannelUnavailableException::GLOBAL_OFF, ChannelUnavailableException::ACCOUNT_OFF, ChannelUnavailableException::CAPABILITY_OFF,
        ChannelUnavailableException::WRITES_OFF_ENVIRONMENT, ChannelUnavailableException::WRITES_OFF_ACCOUNT, ChannelUnavailableException::NOT_APPROVED,
        ChannelUnavailableException::UNREGISTERED_OPERATION, ChannelUnavailableException::AUTH_NEEDED, ChannelUnavailableException::BUYER_DATA_OFF,
        'rate_limited',
    ];

    /** Every problem of our own that can stand beside a draft: what the store has not finished, and what the channel reports that a person has to look at. */
    public const PROBLEMS = [
        ...ProductListingReadiness::CODES,
        ListingPublisher::BUYABLE_WITHOUT_OUR_OFFER,
        'listing_key_belongs_to_another_product',
        // The catalogue names a different product type than the one on the draft. Not an error and not overwritten: a person decides which is right.
        'catalog_product_type_differs',
    ];

    /** Where a product can stand on one channel, as the screens name it (`product_channel_state_` + state; the product card uses the same keys). */
    public const STATES = [
        ChannelListingDraft::DRAFT, ChannelListingDraft::VALIDATED, ChannelListingDraft::SUBMITTED,
        ChannelListingDraft::ACCEPTED, ChannelListingDraft::REJECTED, ChannelListingDraft::WITHDRAWN,
        'listed', 'not_offered',
    ];

    public function __construct(private readonly ChannelLabels $channels)
    {
    }

    /** Why nothing was sent, or why the channel refused. A code we do not produce ourselves is shown as it came. */
    public function refusal(?string $code): string
    {
        if ($code === null || $code === '') {
            return self::NONE;
        }
        $transport = str_starts_with($code, 'channel_') ? substr($code, strlen('channel_')) : $code;
        if (in_array($transport, self::CHANNEL_REFUSALS, true)) {
            return $this->channels->channelReason($transport);
        }

        return in_array($code, self::OURS, true) ? translate($code) : $code;
    }

    /** Is this a code the screens can put words to? What is not is a channel's own word and is shown as written. */
    public function knows(string $code): bool
    {
        return $this->refusal($code) !== $code;
    }

    /**
     * One problem beside a draft. Ours are translated; everything else is a CHANNEL's own word — a field name it requires, or a sentence it wrote — and is shown as it came.
     */
    public function problem(string $code): string
    {
        return in_array($code, self::PROBLEMS, true) ? translate($code) : $code;
    }

    /** @param list<string> $codes */
    public function problems(array $codes): string
    {
        return implode(' · ', array_map(fn (string $code): string => $this->problem($code), $codes));
    }

    /** Where the product stands on one channel. A state this version does not know is shown as stored. */
    public function state(?string $state): string
    {
        if ($state === null || $state === '') {
            return self::NONE;
        }

        return in_array($state, self::STATES, true) ? translate('product_channel_state_' . $state) : $state;
    }

    /**
     * Every key this class hands to translate() itself, for whoever maintains the language files. The transport's reasons are not here: they are ChannelLabels' keys.
     *
     * @return list<string>
     */
    public static function translationKeys(): array
    {
        return [
            ...self::OURS,
            ...self::PROBLEMS,
            ...array_map(static fn (string $state): string => 'product_channel_state_' . $state, self::STATES),
        ];
    }
}
