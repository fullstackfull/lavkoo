<?php

namespace App\Services\SellerAutomation\Actions;

use App\Models\Product;
use App\Models\SellerAutomationAction as ActionRecord;
use App\Services\AuditLogger;
use App\Services\Marketplace\SellerPrincipal;
use App\Services\SellerAutomation\AutomationAction;

/**
 * Put a listing back on the storefront.
 *
 * Refuses a product the marketplace has not approved. Publication is the one visibility change that
 * can put something in front of customers that a moderator said no to, so the rule does not get to
 * make it: `request_status` is the marketplace's answer, and automation working for the seller has
 * no standing to overturn it.
 */
class PublishListingAction implements AutomationAction
{
    public const KEY = 'publish_listing';

    /** The marketplace's own approval flag: 1 is approved, 2 is denied, 0 is still pending. */
    private const APPROVED = 1;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function permission(): string
    {
        return 'products.manage';
    }

    public function subjectTypes(): array
    {
        return [ActionRecord::SUBJECT_PRODUCT];
    }

    public function rules(): array
    {
        return [];
    }

    public function revertibleColumns(): array
    {
        return ['status'];
    }

    public function preview(object $subject, array $settings): array
    {
        if (!$subject instanceof Product) {
            return ['ok' => false, 'reason' => 'automation_reason_wrong_subject'];
        }

        if ((int) $subject->status === 1) {
            return ['ok' => false, 'reason' => 'automation_reason_already_live', 'label' => $subject->getRawOriginal('name')];
        }

        if ((int) $subject->request_status !== self::APPROVED) {
            return ['ok' => false, 'reason' => 'automation_reason_not_approved', 'label' => $subject->getRawOriginal('name')];
        }

        if ((int) $subject->current_stock <= 0) {
            return ['ok' => false, 'reason' => 'automation_reason_nothing_to_sell', 'label' => $subject->getRawOriginal('name')];
        }

        return [
            'ok' => true,
            'label' => $subject->getRawOriginal('name'),
            'before' => ['status' => 0],
            'after' => ['status' => 1],
        ];
    }

    public function apply(object $subject, array $settings, SellerPrincipal $principal): array
    {
        $planned = $this->preview($subject, $settings);

        if (!$planned['ok']) {
            return $planned;
        }

        /** @var Product $subject */
        $subject->forceFill(['status' => 1])->save();

        $this->audit->record(
            action: 'seller.listing_published_by_rule',
            subject: ['type' => 'product', 'id' => $subject->id],
            before: ['status' => 0],
            after: ['status' => 1],
            context: ['actor' => $principal->actorLabel(), 'seller_id' => $principal->sellerId()],
        );

        return $planned;
    }
}
