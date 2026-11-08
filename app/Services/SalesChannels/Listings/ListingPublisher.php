<?php

namespace App\Services\SalesChannels\Listings;

use App\Enums\SalesChannels\IntegrationCapability;
use App\Models\ChannelListingDraft;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\AuditLogger;
use App\Services\SalesChannels\ChannelConnectorRegistry;
use App\Services\SalesChannels\Contracts\CreatesListings;
use App\Services\SalesChannels\Data\ListingSubmission;
use App\Services\SalesChannels\Exceptions\ChannelRateLimitedException;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Support\ChannelOperations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Puts a product of the store on a channel, once.
 *
 *  1. the gate: the host switch, the account, the `product_sync` capability, the recorded `product_writes` approval, and a connector that can really create a listing. Nothing is
 *     read from the channel and nothing is written here while any of them is closed;
 *  2. the draft must be VALIDATED against what the channel itself said it requires (ListingDraftService), and a regulated product needs a person to say so;
 *  3. NO SECOND LISTING: a listing of this product on this account that the draft is not already linked to refuses the attempt. A draft that IS linked updates that listing;
 *  4. one call that sets an absolute state (the seller's own SKU is the identity), and the channel's answer is written down: its submission id, or its refusal with reasons;
 *  5. `follow()` asks the channel what became of a submission. Only an acceptance creates the listing row here, and it starts with stock and price publishing OFF: a new listing
 *     sells nothing until somebody has decided where its stock comes from.
 *
 * The product is never written: a draft holds the channel's fields, and the listing row holds the channel's identifiers.
 */
class ListingPublisher
{
    public function __construct(
        private readonly ChannelConnectorRegistry $connectors,
        private readonly ListingDraftService $drafts,
        private readonly RegulatedProductGate $regulated,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param int|null $adminId the person asking; null means something automatic asked, and a regulated product is then refused
     */
    public function publish(ChannelListingDraft $draft, ?int $adminId = null): ListingOutcome
    {
        $account = $draft->account;
        if (($refusal = $this->closedGate($account, $draft)) !== null) {
            return $this->refuse($draft, $refusal);
        }

        // Already with the channel: asking again would be a second call for the same thing. Editing the draft's fields sends it back to DRAFT, and that is how an update is asked for.
        if ($draft->state === ChannelListingDraft::SUBMITTED) {
            return ListingOutcome::refused('listing_already_submitted');
        }
        if ($draft->state === ChannelListingDraft::ACCEPTED) {
            return ListingOutcome::refused('listing_already_accepted');
        }

        $validation = $this->drafts->validate($draft);
        if (!$validation->ok()) {
            return $validation;
        }

        if (($refusal = $this->regulated->refusalFor((int) $draft->product_id, $adminId !== null)) !== null) {
            return $this->refuse($draft, $refusal);
        }
        if (($refusal = $this->aListingAlreadyExists($draft)) !== null) {
            return $this->refuse($draft, $refusal);
        }

        /** @var CreatesListings $connector */
        $connector = $this->connectorFor($account);

        try {
            $submission = $connector->putListing($account, $draft);
        } catch (ChannelRateLimitedException) {
            // The channel never looked at it: the draft stays as it was and the next run asks again.
            return $this->refuse($draft, 'channel_rate_limited');
        } catch (ChannelUnavailableException $exception) {
            return $this->refuse($draft, 'channel_' . $exception->reason);
        } catch (Throwable $exception) {
            // Only the kind of failure: what a channel says about a product can repeat the whole payload back.
            Log::warning('Creating a channel listing failed.', ['draft_id' => $draft->id, 'error' => $exception::class]);

            return $this->refuse($draft, 'listing_write_failed');
        }

        return $this->record($draft, $submission, $adminId);
    }

    /** Asks the channel what became of a submission. Safe to call as often as you like; it writes nothing at the channel. */
    public function follow(ChannelListingDraft $draft): ListingOutcome
    {
        if (!$draft->isWaitingForTheChannel()) {
            return ListingOutcome::refused('listing_not_waiting_for_the_channel');
        }
        $account = $draft->account;
        if (($refusal = $this->closedGate($account, $draft)) !== null) {
            return $this->refuse($draft, $refusal);
        }

        /** @var CreatesListings $connector */
        $connector = $this->connectorFor($account);

        try {
            $submission = $connector->listingSubmission($account, $draft);
        } catch (ChannelRateLimitedException) {
            return ListingOutcome::working();
        } catch (Throwable $exception) {
            Log::warning('Reading a channel listing submission failed.', ['draft_id' => $draft->id, 'error' => $exception::class]);

            return ListingOutcome::working();
        }

        return $this->record($draft, $submission, $draft->requested_by);
    }

    /** @return string|null the code of the first thing that is closed, or null when the way is open */
    private function closedGate(?SalesChannelAccount $account, ChannelListingDraft $draft): ?string
    {
        return match (true) {
            !config('sales_channels.listing.publish_enabled', false) => 'listing_publishing_off',
            $account === null || !$account->enabled => 'account_off',
            !$account->hasCapability(IntegrationCapability::ProductSync) => 'product_sync_capability_off',
            empty(($account->capability_approvals ?? [])[ChannelOperations::SCOPE_PRODUCT_WRITES]) => 'product_writes_approval_missing',
            !$this->connectorFor($account) instanceof CreatesListings => 'channel_cannot_create_listings',
            (int) $draft->product_id === 0 => 'draft_without_a_product',
            default => null,
        };
    }

    /** The account's connector, or null when the channel has none yet: a channel we cannot speak to is said so, not crashed on. */
    private function connectorFor(SalesChannelAccount $account): ?object
    {
        try {
            return $this->connectors->for($account);
        } catch (Throwable) {
            return null;
        }
    }

    /** A listing of this product on this account that this draft is not the one for: creating another would be a duplicate at the channel. */
    private function aListingAlreadyExists(ChannelListingDraft $draft): ?string
    {
        $existing = SalesChannelProductMapping::where('account_id', $draft->account_id)
            ->where('product_id', $draft->product_id)
            ->where('variant_key', (string) $draft->variant_key)
            ->when($draft->product_mapping_id !== null, static fn ($query) => $query->whereKeyNot($draft->product_mapping_id))
            ->exists();

        return $existing ? 'listing_already_exists' : null;
    }

    private function record(ChannelListingDraft $draft, ListingSubmission $submission, ?int $adminId): ListingOutcome
    {
        $wasWaiting = $draft->isWaitingForTheChannel();

        if ($submission->isRejected()) {
            $draft->forceFill([
                'state' => ChannelListingDraft::REJECTED, 'submission_id' => $submission->id ?? $draft->submission_id,
                'channel_status' => $submission->channelStatus, 'channel_problems' => $submission->problems, 'checked_at' => now(),
            ])->save();
            $this->note($draft, 'sales_channel.listing_rejected', ['problems' => array_slice($submission->problems, 0, 10)], $adminId);

            return ListingOutcome::rejected($submission->problems);
        }

        if (!$submission->isAccepted()) {
            $draft->forceFill([
                'state' => ChannelListingDraft::SUBMITTED, 'submission_id' => $submission->id ?? $draft->submission_id,
                'channel_status' => $submission->channelStatus, 'submitted_at' => $draft->submitted_at ?? now(), 'checked_at' => now(),
                'requested_by' => $adminId ?? $draft->requested_by, 'refusal_code' => null, 'channel_problems' => null,
            ])->save();
            $this->note($draft, 'sales_channel.listing_submitted', ['submission_id' => $submission->id], $adminId);

            return $wasWaiting ? ListingOutcome::working() : ListingOutcome::submitted();
        }

        $listingId = $this->attachTheListing($draft, $submission);
        $draft->forceFill([
            'state' => ChannelListingDraft::ACCEPTED, 'submission_id' => $submission->id ?? $draft->submission_id, 'channel_status' => $submission->channelStatus,
            'channel_problems' => null, 'refusal_code' => null, 'product_mapping_id' => $listingId, 'checked_at' => now(),
            'submitted_at' => $draft->submitted_at ?? now(), 'requested_by' => $adminId ?? $draft->requested_by,
        ])->save();
        $this->note($draft, 'sales_channel.listing_accepted', ['listing_id' => $listingId], $adminId);

        return ListingOutcome::accepted($listingId);
    }

    /**
     * The listing row for an accepted draft: the one already linked, the one the channel's own key belongs to (a listing we had read before), or a new row.
     *
     * A new row is MAPPED to the product straight away — we are the ones who asked for it, so there is nothing to guess — and it starts with stock and price publishing OFF, so
     * the listing exists at the channel and sells nothing until somebody decides where its stock comes from.
     */
    private function attachTheListing(ChannelListingDraft $draft, ListingSubmission $submission): ?int
    {
        $listingKey = $submission->listingKey ?? (string) (($draft->payload ?? [])['sku'] ?? '');
        if ($listingKey === '') {
            return $draft->product_mapping_id;
        }

        return DB::transaction(function () use ($draft, $listingKey): int {
            $listing = SalesChannelProductMapping::where('account_id', $draft->account_id)->where('listing_key', $listingKey)->lockForUpdate()->first()
                ?? ($draft->product_mapping_id === null ? null : SalesChannelProductMapping::whereKey($draft->product_mapping_id)->lockForUpdate()->first());

            if ($listing === null) {
                $listing = SalesChannelProductMapping::create([
                    'account_id' => $draft->account_id,
                    'product_id' => $draft->product_id,
                    'variant_key' => (string) $draft->variant_key,
                    'listing_key' => $listingKey,
                    'external_sku' => $listingKey,
                    'status' => SalesChannelProductMapping::MAPPED,
                    'inventory_publish' => false,
                    'price_publish' => false,
                    'mapped_at' => now(),
                ]);

                return (int) $listing->id;
            }

            $listing->forceFill([
                'product_id' => $listing->product_id ?? $draft->product_id,
                'variant_key' => (string) $draft->variant_key,
                'status' => SalesChannelProductMapping::MAPPED,
                'mapped_at' => $listing->mapped_at ?? now(),
            ])->save();

            return (int) $listing->id;
        }, 3);
    }

    private function refuse(ChannelListingDraft $draft, string $reason): ListingOutcome
    {
        $draft->forceFill(['refusal_code' => $reason])->save();

        return ListingOutcome::refused($reason);
    }

    /** @param array<string, mixed> $context */
    private function note(ChannelListingDraft $draft, string $action, array $context, ?int $adminId): void
    {
        $this->audit->record(
            action: $action,
            subject: $draft,
            context: $context + ['draft_id' => $draft->id, 'account_id' => $draft->account_id, 'product_id' => $draft->product_id, 'by' => $adminId],
        );
    }
}
