<?php

namespace App\Services\Marketplace;

use App\Models\Product;
use App\Models\ProductModerationEvent;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moderates products with a history, structured reasons, and the states the spec asks for
 * (Phase 3, Stage A — spec item 7).
 *
 * The platform had one `request_status` flag and one overwritten `denied_note`. This adds the six
 * states the spec names — approved, rejected, needs changes, suspended, plus resubmit — with a
 * per-decision reason and note, while keeping `request_status` in sync so the storefront and the
 * Flutter apps, which read that column, see no change in contract.
 *
 * The mapping onto the legacy column, stated so it is not a mystery:
 *
 *   approved       -> request_status 1  (live and sellable)
 *   rejected       -> request_status 2  (denied; a resubmit is a new pending)
 *   needs_changes  -> request_status 2  (denied, but the seller is told what to fix and may resubmit)
 *   suspended      -> status 0          (an approved product taken offline; request_status untouched)
 *
 * Every decision is written to the unified audit log, so product governance shares the same trail as
 * settlements and payouts rather than keeping a private one.
 */
class ProductModerationService
{
    public const ACTION_APPROVED = 'approved';
    public const ACTION_REJECTED = 'rejected';
    public const ACTION_NEEDS_CHANGES = 'needs_changes';
    public const ACTION_SUSPENDED = 'suspended';

    /** The structured reasons a moderator can cite, from the spec's own list. */
    public const REASONS = [
        'missing_image', 'incorrect_category', 'invalid_claims',
        'price_issue', 'missing_registration', 'duplicate_product',
        'prohibited_item', 'poor_description', 'other',
    ];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function approve(int|string $productId, ?string $note = null): ?ProductModerationEvent
    {
        return $this->decide($productId, self::ACTION_APPROVED, newRequestStatus: 1, note: $note);
    }

    /**
     * Reject outright. Reason codes are required in spirit — a rejection with no reason is useless
     * to the seller — but not enforced hard here, so a legitimate "other" + note still works.
     */
    public function reject(int|string $productId, array $reasonCodes = [], ?string $note = null): ?ProductModerationEvent
    {
        return $this->decide($productId, self::ACTION_REJECTED, newRequestStatus: 2, reasonCodes: $reasonCodes, note: $note);
    }

    /** Send back for changes: denied on the legacy column, but a distinct, resubmittable state here. */
    public function needsChanges(int|string $productId, array $reasonCodes = [], ?string $note = null): ?ProductModerationEvent
    {
        return $this->decide($productId, self::ACTION_NEEDS_CHANGES, newRequestStatus: 2, reasonCodes: $reasonCodes, note: $note);
    }

    /** Take an already-approved product offline without denying it. Toggles `status`, not request_status. */
    public function suspend(int|string $productId, array $reasonCodes = [], ?string $note = null): ?ProductModerationEvent
    {
        return $this->decide($productId, self::ACTION_SUSPENDED, deactivate: true, reasonCodes: $reasonCodes, note: $note);
    }

    /**
     * Apply one decision: sync the legacy column, write the history event, and record the audit line
     * — all in one transaction so the three never disagree.
     */
    private function decide(
        int|string $productId,
        string $action,
        ?int $newRequestStatus = null,
        bool $deactivate = false,
        array $reasonCodes = [],
        ?string $note = null,
    ): ?ProductModerationEvent {
        if (!Schema::hasTable('product_moderation_events') || !Schema::hasTable('products')) {
            return null;
        }

        $product = Product::find($productId);
        if (!$product) {
            return null;
        }

        // Keep only reasons from the known list; a typo'd code should not become a silent category.
        $reasonCodes = array_values(array_intersect($reasonCodes, self::REASONS));

        return DB::transaction(function () use ($product, $action, $newRequestStatus, $deactivate, $reasonCodes, $note) {
            $previous = (int) $product->request_status;

            $updates = [];
            if ($newRequestStatus !== null) {
                $updates['request_status'] = $newRequestStatus;
            }
            if ($deactivate) {
                $updates['status'] = 0;
            }
            if ($updates) {
                Product::where('id', $product->id)->update($updates);
            }

            $event = ProductModerationEvent::create([
                'product_id' => $product->id,
                'action' => $action,
                'reason_codes' => $reasonCodes ?: null,
                'note' => $note,
                'previous_request_status' => $previous,
                'new_request_status' => $newRequestStatus ?? $previous,
            ]);

            $this->audit->record(
                action: 'product.' . $action,
                subject: $product,
                context: ['reasons' => $reasonCodes, 'note' => $note],
            );

            return $event;
        }, attempts: 3);
    }

    /**
     * Bulk moderation (spec item 7: bulk approve / reject / request changes with audit logs).
     *
     * Each product is moderated on its own so one bad id does not abort the batch, and every one
     * still produces its own history event and audit line.
     *
     * @return array{processed: int, skipped: int}
     */
    public function bulk(string $action, array $productIds, array $reasonCodes = [], ?string $note = null): array
    {
        $processed = 0;
        $skipped = 0;

        foreach (array_unique($productIds) as $productId) {
            $event = match ($action) {
                self::ACTION_APPROVED => $this->approve($productId, $note),
                self::ACTION_REJECTED => $this->reject($productId, $reasonCodes, $note),
                self::ACTION_NEEDS_CHANGES => $this->needsChanges($productId, $reasonCodes, $note),
                self::ACTION_SUSPENDED => $this->suspend($productId, $reasonCodes, $note),
                default => null,
            };

            $event ? $processed++ : $skipped++;
        }

        return ['processed' => $processed, 'skipped' => $skipped];
    }
}
