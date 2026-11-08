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
 * Puts a product of the store on a channel, once, and never claims more than the channel has actually said.
 *
 *  1. THE GATES: the host switch, the account, the `product_sync` capability, the recorded `product_writes` approval, a connector that can really create a listing, and the
 *     draft not being with the channel already. Nothing is read from the channel and nothing is written while any of them is closed.
 *  2. THE DRAFT must be VALIDATED against what the channel itself said it requires, and a regulated product needs a recorded clearance (RegulatedProductGate).
 *  3. WHOSE SKU IS IT: the channel is asked what it holds for our SKU before anything is written. A listing we have no record of is never replaced and never re-pointed at
 *     another product — it is handed to a person. Our own records are checked the same way: a listing key that belongs to another product refuses the attempt.
 *  4. CREATE or UPDATE, never both ways round: a create (PUT) sets the whole listing and is used only when the channel holds nothing for the SKU; a change to a listing that
 *     exists is a PATCH of the fields that differ, so attributes we were never told about are not lost.
 *  5. NO OFFER EVER LEAVES HERE: every attribute the connector names as an offer attribute (a price, a quantity, an availability) is withheld and recorded. A quantity reaches
 *     a channel only through stock publishing, which is a capability of its own with an approval of its own.
 *  5b. WHAT WAS SENT IS NOT WHAT WAS APPLIED: the fields of a write are kept as `submitted_payload` while the channel has not spoken, and become `published_payload` only when
 *     it confirms the listing. An update is computed against the CONFIRMED payload, so a submission that was taken and then refused is sent again, and changing the same field
 *     twice records the second value, not the first.
 *  6. A SUBMISSION IS NOT A LISTING: a channel that answers "accepted" has taken the submission, nothing more. The draft waits, and `follow()` asks the channel what it HOLDS
 *     (`listingFor`). Only that answer creates the listing row here, with what the channel says about it; and if the channel reports it BUYABLE although we published no offer,
 *     that is written down as a problem for a person, not as a success.
 *
 * The product is never written: a draft holds the channel's fields, and the listing row holds the channel's identifiers.
 */
class ListingPublisher
{
    /** The channel says it is sellable although no offer of ours was ever published: the owner has to look at it. */
    public const BUYABLE_WITHOUT_OUR_OFFER = 'the_channel_reports_this_listing_buyable_although_no_offer_of_ours_was_published';

    public function __construct(
        private readonly ChannelConnectorRegistry $connectors,
        private readonly ListingDraftService $drafts,
        private readonly RegulatedProductGate $regulated,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param int|null $adminId the person asking, recorded with the submission
     */
    public function publish(ChannelListingDraft $draft, ?int $adminId = null): ListingOutcome
    {
        $account = $draft->account;
        if (($refusal = $this->closedGate($account, $draft)) !== null) {
            return $this->refuse($draft, $refusal);
        }

        $validation = $this->drafts->validate($draft);
        if (!$validation->ok()) {
            return $validation;
        }
        if (($refusal = $this->regulated->refusalFor($draft)) !== null) {
            return $this->refuse($draft, $refusal);
        }
        if (($refusal = $this->aListingOfOursAlreadyUsesIt($draft)) !== null) {
            return $this->refuse($draft, $refusal);
        }

        /** @var CreatesListings $connector */
        $connector = $this->connectorFor($account);
        [$attributes, $withheld] = $this->withoutAnOffer($connector, $draft);
        if ($attributes === []) {
            return $this->refuse($draft, 'listing_has_nothing_to_send_that_is_not_an_offer');
        }

        $held = $this->whatTheChannelHolds($connector, $account, $draft);
        if ($held instanceof ListingOutcome) {
            return $held;
        }

        try {
            $submission = $held
                ? $connector->updateListing($account, $draft, $draft->changedAttributes($attributes))
                : $connector->createListing($account, $draft, $attributes);
        } catch (ChannelRateLimitedException) {
            // The channel never looked at it: the draft stays as it was and the next run sends it.
            return $this->refuse($draft, 'channel_rate_limited');
        } catch (ChannelUnavailableException $exception) {
            return $this->refuse($draft, 'channel_' . $exception->reason);
        } catch (Throwable $exception) {
            // Only the kind of failure: what a channel says about a product can repeat the whole payload back.
            Log::warning('Writing a channel listing failed.', ['draft_id' => $draft->id, 'error' => $exception::class]);

            return $this->refuse($draft, 'listing_write_failed');
        }

        return $this->recordTheWrite($draft, $submission, $attributes, $withheld, $adminId, updated: $held);
    }

    /**
     * Asks the channel what it HOLDS for the draft's SKU, and makes of it what it really is. Safe to call as often as you like; it writes nothing at the channel.
     */
    public function follow(ChannelListingDraft $draft): ListingOutcome
    {
        if (!$draft->isWaitingForTheChannel()) {
            return ListingOutcome::refused('listing_not_waiting_for_the_channel');
        }
        $account = $draft->account;
        if (($refusal = $this->closedGate($account, $draft, forAWrite: false)) !== null) {
            return $this->refuse($draft, $refusal);
        }

        /** @var CreatesListings $connector */
        $connector = $this->connectorFor($account);

        try {
            $submission = $connector->listingFor($account, $this->skuOf($draft));
        } catch (ChannelRateLimitedException) {
            return ListingOutcome::working();
        } catch (Throwable $exception) {
            Log::warning('Reading a channel listing failed.', ['draft_id' => $draft->id, 'error' => $exception::class]);

            return ListingOutcome::working();
        }

        if ($submission->isRejected()) {
            return $this->markRejected($draft, $submission, $draft->requested_by);
        }
        if (!$submission->isLive()) {
            // Still working, or we could not ask: either way the draft waits and nothing is sent again.
            $draft->forceFill(['channel_status' => $submission->channelStatus, 'checked_at' => now()])->save();

            return ListingOutcome::working();
        }

        return $this->markLive($draft, $submission);
    }

    /**
     * @param bool $forAWrite a read (follow) needs neither the host's listing switch nor the write approval: it only asks the channel what it already holds
     * @return string|null the code of the first thing that is closed, or null when the way is open
     */
    private function closedGate(?SalesChannelAccount $account, ChannelListingDraft $draft, bool $forAWrite = true): ?string
    {
        return match (true) {
            $forAWrite && !config('sales_channels.listing.publish_enabled', false) => 'listing_publishing_off',
            $account === null || !$account->enabled => 'account_off',
            !$account->hasCapability(IntegrationCapability::ProductSync) => 'product_sync_capability_off',
            $forAWrite && empty(($account->capability_approvals ?? [])[ChannelOperations::SCOPE_PRODUCT_WRITES]) => 'product_writes_approval_missing',
            !$this->connectorFor($account) instanceof CreatesListings => 'channel_cannot_create_listings',
            (int) $draft->product_id === 0 => 'draft_without_a_product',
            $this->skuOf($draft) === '' => 'draft_without_our_own_sku',
            $forAWrite && $draft->state === ChannelListingDraft::SUBMITTED => 'listing_already_submitted',
            $forAWrite && $draft->state === ChannelListingDraft::ACCEPTED => 'listing_already_accepted',
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

    private function skuOf(ChannelListingDraft $draft): string
    {
        return trim((string) (($draft->payload ?? [])['sku'] ?? ''));
    }

    /**
     * What OUR OWN records say about this product and this listing key, before the channel is asked anything.
     *
     *  - a listing with this key on this account that stands for ANOTHER product: the key is taken, and a write would quietly move someone else's listing onto this product;
     *  - any other listing of this product on this account that the draft is not the one for: a second listing of the same product.
     */
    private function aListingOfOursAlreadyUsesIt(ChannelListingDraft $draft): ?string
    {
        $byKey = SalesChannelProductMapping::where('account_id', $draft->account_id)->where('listing_key', $this->skuOf($draft))->first();
        if ($byKey !== null && $byKey->product_id !== null && (int) $byKey->product_id !== (int) $draft->product_id) {
            return 'listing_key_belongs_to_another_product';
        }

        $otherListing = SalesChannelProductMapping::where('account_id', $draft->account_id)
            ->where('product_id', $draft->product_id)
            ->where('variant_key', (string) $draft->variant_key)
            ->when($draft->product_mapping_id !== null, static fn ($query) => $query->whereKeyNot($draft->product_mapping_id))
            ->when($byKey !== null, static fn ($query) => $query->whereKeyNot($byKey->id))
            ->exists();

        return $otherListing ? 'listing_already_exists' : null;
    }

    /**
     * Does the channel already hold a listing for our SKU? That decides create or update — and it is also where someone else's listing is kept safe.
     *
     * The channel is asked, not guessed, and only two of its answers permit a write:
     *
     *  - it HOLDS a listing for the SKU, and our own record says that listing is this product's → an update (a PATCH of what differs);
     *  - it says it holds NOTHING (`ListingSubmission::ABSENT`, Amazon's 404) → a create (a PUT).
     *
     * Everything else refuses: a listing we have no record of is never replaced and never adopted (a person links it, which the listing sync and the products screen are for),
     * a failed read tells us nothing, and an answer that settles nothing — the channel still busy, or Amazon answering 200 for the SKU with no summary for the marketplace,
     * which means the item IS in its catalogue — must not be read as an absence. A create on any of those could replace a listing.
     *
     * @return bool|ListingOutcome true when it is an update, false when the channel has confirmed there is nothing there, or the refusal
     */
    private function whatTheChannelHolds(CreatesListings $connector, SalesChannelAccount $account, ChannelListingDraft $draft): bool|ListingOutcome
    {
        try {
            $held = $connector->listingFor($account, $this->skuOf($draft));
        } catch (ChannelRateLimitedException) {
            return $this->refuse($draft, 'channel_rate_limited');
        } catch (ChannelUnavailableException $exception) {
            return $this->refuse($draft, 'channel_' . $exception->reason);
        } catch (Throwable $exception) {
            Log::warning('Reading what a channel holds for our SKU failed.', ['draft_id' => $draft->id, 'error' => $exception::class]);

            return $this->refuse($draft, 'could_not_read_what_the_channel_holds');
        }

        if ($held->isUnknown()) {
            // Fail closed: without knowing whether the SKU is taken, a create could replace a listing and an update could be sent to nothing.
            return $this->refuse($draft, 'could_not_read_what_the_channel_holds');
        }

        if ($held->isLive() || $held->isRejected()) {
            // The channel holds a listing for the SKU. It may only be changed when our own record says it is this product's.
            if (!$this->weHaveARecordOfIt($draft)) {
                $this->note($draft, 'sales_channel.listing_key_taken_at_the_channel', ['sku' => $this->skuOf($draft)], null);

                return $this->refuse($draft, 'sku_already_listed_at_the_channel_and_not_linked_here');
            }

            return true;
        }

        if ($held->isAbsent()) {
            // The channel said it holds nothing. This is the ONLY answer a create is made on.
            return false;
        }

        // Anything else settles nothing — the channel is still busy, or it answered about the SKU without saying whether a listing exists (Amazon: 200 with no summary for the
        // marketplace, which means the item IS in the seller's catalogue). A create here could replace a listing, so the write waits for an answer or for a person.
        $this->note($draft, 'sales_channel.listing_presence_unconfirmed', ['sku' => $this->skuOf($draft), 'channel_said' => $held->channelStatus], null);

        return $this->refuse($draft, 'could_not_confirm_that_the_channel_holds_no_listing');
    }

    /** Is the draft linked to a listing of OURS for this very product? Only then may a write change what the channel holds. */
    private function weHaveARecordOfIt(ChannelListingDraft $draft): bool
    {
        if ($draft->product_mapping_id === null) {
            return false;
        }
        $listing = SalesChannelProductMapping::find($draft->product_mapping_id);

        return $listing !== null
            && (int) $listing->account_id === (int) $draft->account_id
            && (int) $listing->product_id === (int) $draft->product_id
            && (string) $listing->listing_key === $this->skuOf($draft);
    }

    /**
     * The channel's fields without anything that would give the listing an offer. A draft may carry a price because a person filled a form; it never reaches a channel from
     * here, and what was withheld is written on the draft so that nobody believes it was sent.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function withoutAnOffer(CreatesListings $connector, ChannelListingDraft $draft): array
    {
        $payload = (array) ($draft->payload ?? []);
        $attributes = (array) ($payload['attributes'] ?? []);
        if ($attributes === []) {
            $attributes = array_diff_key($payload, array_flip(['sku', 'product_type', 'attributes']));
        }

        $withheld = [];
        foreach ($connector->offerAttributes() as $field) {
            if (array_key_exists($field, $attributes)) {
                unset($attributes[$field]);
                $withheld[] = $field;
            }
        }

        return [$attributes, $withheld];
    }

    /**
     * What the channel made of the write. A refusal is a refusal; anything else is a submission it TOOK, and the draft waits to be followed up — the listing row is not created
     * here, because nothing yet says a listing exists.
     *
     * @param array<string, mixed> $sent
     * @param list<string> $withheld
     */
    private function recordTheWrite(ChannelListingDraft $draft, ListingSubmission $submission, array $sent, array $withheld, ?int $adminId, bool $updated): ListingOutcome
    {
        if ($submission->isRejected()) {
            return $this->markRejected($draft, $submission, $adminId);
        }

        $draft->forceFill([
            'state' => ChannelListingDraft::SUBMITTED,
            'submission_id' => $submission->id ?? $draft->submission_id,
            'channel_status' => $submission->channelStatus,
            'channel_problems' => null,
            'refusal_code' => null,
            // What the channel will hold once it applies this: what it has CONFIRMED, with the fields just sent on top (array_merge, so a field sent again wins). It stays
            // `submitted` until the channel confirms the listing; `published_payload` is only written then, so nothing is believed published because a submission was taken.
            'submitted_payload' => $draft->payloadAfterSending($sent),
            'withheld_attributes' => $withheld,
            'submitted_at' => now(),
            'checked_at' => now(),
            'requested_by' => $adminId ?? $draft->requested_by,
        ])->save();
        $this->note($draft, $updated ? 'sales_channel.listing_update_submitted' : 'sales_channel.listing_submitted', [
            'submission_id' => $submission->id, 'fields' => array_keys($sent), 'withheld' => $withheld,
        ], $adminId);

        return ListingOutcome::submitted();
    }

    private function markRejected(ChannelListingDraft $draft, ListingSubmission $submission, ?int $adminId): ListingOutcome
    {
        $draft->forceFill([
            'state' => ChannelListingDraft::REJECTED,
            'submission_id' => $submission->id ?? $draft->submission_id,
            'channel_status' => $submission->channelStatus,
            'channel_problems' => $submission->problems,
            // The refusal applied nothing, so what was sent is forgotten and what the channel holds is unchanged: the fields go out again next time.
            'submitted_payload' => null,
            'checked_at' => now(),
        ])->save();
        $this->note($draft, 'sales_channel.listing_rejected', ['problems' => array_slice($submission->problems, 0, 10)], $adminId);

        return ListingOutcome::rejected($submission->problems);
    }

    /** The channel reports a listing for our SKU: now, and only now, is there a listing to record here. */
    private function markLive(ChannelListingDraft $draft, ListingSubmission $submission): ListingOutcome
    {
        $listingId = $this->attachTheListing($draft, $submission);
        $problems = [];
        if ($listingId === null) {
            $problems[] = 'listing_key_belongs_to_another_product';
        }
        if ($submission->isBuyable()) {
            // This publisher never sends an offer, so a quantity came from somewhere else (an older listing, the seller's panel, another tool) and a person has to know.
            $problems[] = self::BUYABLE_WITHOUT_OUR_OFFER;
        }

        $draft->forceFill([
            'state' => ChannelListingDraft::ACCEPTED,
            'channel_status' => $submission->channelStatus,
            'channel_listing_statuses' => $submission->statuses,
            // The channel confirms the listing: what was sent is now what it holds, and nothing is waiting any more.
            'published_payload' => $draft->submitted_payload ?? $draft->published_payload,
            'submitted_payload' => null,
            'channel_problems' => $problems === [] ? null : $problems,
            'product_mapping_id' => $listingId ?? $draft->product_mapping_id,
            'checked_at' => now(),
        ])->save();
        $this->note($draft, 'sales_channel.listing_live', ['listing_id' => $listingId, 'statuses' => $submission->statuses, 'problems' => $problems], $draft->requested_by);

        return ListingOutcome::accepted($listingId, $problems);
    }

    /**
     * The listing row for a listing the channel confirms: the one already linked, or a new one.
     *
     * A row with this key that stands for ANOTHER product is never re-pointed and never overwritten — the method answers null and the draft keeps the problem. A new row is
     * MAPPED to the product (we asked for it, so there is nothing to guess) and starts with stock and price publishing OFF: the listing exists and sells nothing until somebody
     * decides where its stock comes from.
     */
    private function attachTheListing(ChannelListingDraft $draft, ListingSubmission $submission): ?int
    {
        $listingKey = $submission->listingKey ?? $this->skuOf($draft);
        if ($listingKey === '') {
            return $draft->product_mapping_id;
        }

        return DB::transaction(function () use ($draft, $listingKey): ?int {
            $listing = SalesChannelProductMapping::where('account_id', $draft->account_id)->where('listing_key', $listingKey)->lockForUpdate()->first();
            if ($listing !== null && $listing->product_id !== null && (int) $listing->product_id !== (int) $draft->product_id) {
                return null;
            }
            if ($listing === null) {
                return (int) SalesChannelProductMapping::create([
                    'account_id' => $draft->account_id,
                    'product_id' => $draft->product_id,
                    'variant_key' => (string) $draft->variant_key,
                    'listing_key' => $listingKey,
                    'external_sku' => $listingKey,
                    'status' => SalesChannelProductMapping::MAPPED,
                    'inventory_publish' => false,
                    'price_publish' => false,
                    'mapped_at' => now(),
                ])->id;
            }

            $listing->forceFill([
                'product_id' => $draft->product_id,
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
