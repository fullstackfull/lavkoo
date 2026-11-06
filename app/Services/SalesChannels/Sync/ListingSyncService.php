<?php

namespace App\Services\SalesChannels\Sync;

use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\Fulfillment\Support\PiiRedactor;
use App\Services\SalesChannels\Data\RemoteListing;
use App\Services\SalesChannels\Support\SnapshotState;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Brings the channel's listings into the application WITHOUT touching a single product.
 *
 * Each listing becomes (or updates) one row in `sales_channel_product_mappings`, keyed by the channel's own identifier, so reading a channel twice is the same as reading it once. A new
 * listing starts unlinked. If its SKU or barcode identifies exactly one of our products it is SUGGESTED; with the account's explicit "link exact identifiers" setting that single, unambiguous
 * proposal is accepted automatically; otherwise it waits for a person. A listing is never matched by name, never creates a product, and an existing link is never replaced by a sync.
 */
class ListingSyncService
{
    public function __construct(private readonly ProductMatcher $matcher)
    {
    }

    /**
     * @param iterable<RemoteListing> $listings
     * @return array{created: int, updated: int, unchanged: int, suggested: int, linked: int, conflicts: int}
     */
    public function sync(SalesChannelAccount $account, iterable $listings): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'suggested' => 0, 'linked' => 0, 'conflicts' => 0];
        $autoLink = (bool) $account->setting($account->channel === 'amazon' ? 'auto_link_exact_sku' : 'auto_link_exact_barcode', false);

        foreach ($listings as $listing) {
            $row = SalesChannelProductMapping::where('account_id', $account->id)->where('listing_key', $listing->listingKey)->first();
            if ($row === null) {
                $this->create($account, $listing, $autoLink, $stats);
                continue;
            }
            if ($row->payload_hash === $listing->hash()) {
                $row->forceFill(['last_seen_at' => now()])->save();
                $stats['unchanged']++;
                continue;
            }
            $fields = $this->remoteFields($listing);
            $fields['snapshot'] = SnapshotState::refreshed($row->snapshot, $fields['snapshot'], SnapshotState::LISTING);
            $row->forceFill($fields)->save();
            $stats['updated']++;
            if (!$row->isMapped() && $row->status !== SalesChannelProductMapping::IGNORED) {
                $this->propose($row, $listing, $autoLink, $stats);
            }
        }

        return $stats;
    }

    /** After a COMPLETE run: listings that were not seen are flagged, never deleted (the channel may simply have stopped listing them). */
    public function markUnseen(SalesChannelAccount $account, CarbonInterface $runStartedAt): int
    {
        return SalesChannelProductMapping::where('account_id', $account->id)
            ->where(static function ($query) use ($runStartedAt): void {
                $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $runStartedAt);
            })
            ->where(static function ($query): void {
                $query->whereNull('remote_status')->orWhere('remote_status', '!=', 'not_seen');
            })
            ->update(['remote_status' => 'not_seen']);
    }

    /** @param array<string, int> $stats */
    private function create(SalesChannelAccount $account, RemoteListing $listing, bool $autoLink, array &$stats): void
    {
        try {
            $row = SalesChannelProductMapping::create($this->remoteFields($listing) + [
                'account_id' => $account->id,
                'listing_key' => $listing->listingKey,
                'status' => SalesChannelProductMapping::UNMAPPED,
                'price_source' => 'internal',
            ]);
        } catch (UniqueConstraintViolationException) {
            $stats['unchanged']++;

            return;
        }
        $stats['created']++;
        $this->propose($row, $listing, $autoLink, $stats);
    }

    /** @param array<string, int> $stats */
    private function propose(SalesChannelProductMapping $row, RemoteListing $listing, bool $autoLink, array &$stats): void
    {
        $candidates = $this->matcher->candidates($listing->sku, $listing->barcode);
        if (count($candidates) > 1) {
            $row->forceFill(['status' => SalesChannelProductMapping::CONFLICT, 'match_method' => 'ambiguous', 'suggested_product_id' => null])->save();
            $stats['conflicts']++;

            return;
        }
        if (count($candidates) === 0) {
            return;
        }

        $candidate = $candidates[0];
        $alreadyTaken = SalesChannelProductMapping::where('account_id', $row->account_id)->where('product_id', $candidate['product_id'])
            ->where('variant_key', $candidate['variant_key'])->where('status', SalesChannelProductMapping::MAPPED)->where('id', '!=', $row->id)->exists();
        if ($alreadyTaken) {
            $row->forceFill(['status' => SalesChannelProductMapping::CONFLICT, 'match_method' => 'already_linked_elsewhere', 'suggested_product_id' => $candidate['product_id']])->save();
            $stats['conflicts']++;

            return;
        }

        if ($autoLink) {
            $row->forceFill([
                'product_id' => $candidate['product_id'], 'variant_key' => $candidate['variant_key'], 'status' => SalesChannelProductMapping::MAPPED,
                'match_method' => 'exact_' . $candidate['reason'] . '_auto', 'mapped_at' => now(), 'suggested_product_id' => null,
            ])->save();
            $stats['linked']++;

            return;
        }

        $row->forceFill(['status' => SalesChannelProductMapping::SUGGESTED, 'suggested_product_id' => $candidate['product_id'], 'variant_key' => $candidate['variant_key'], 'match_method' => $candidate['reason']])->save();
        $stats['suggested']++;
    }

    /** @return array<string, mixed> */
    private function remoteFields(RemoteListing $listing): array
    {
        return [
            'external_sku' => $listing->sku,
            'external_id' => $listing->externalId,
            'external_barcode' => $listing->barcode,
            'external_parent_id' => $listing->parentId,
            'external_title' => mb_substr($listing->title, 0, 500),
            'fulfillment_mode' => $listing->fulfillmentMode,
            'remote_price' => $listing->price?->toStorage(),
            'remote_currency' => $listing->currency,
            'remote_quantity' => $listing->quantity,
            'remote_status' => $listing->status,
            'remote_updated_at' => $listing->updatedAt,
            'last_seen_at' => now(),
            'payload_hash' => $listing->hash(),
            'snapshot' => PiiRedactor::redact($listing->snapshot, true),
        ];
    }
}
