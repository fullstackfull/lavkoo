<?php

namespace App\Services\SellerAutomation\Actions;

use App\Models\Product;
use App\Models\SellerAutomationAction as ActionRecord;
use App\Services\AuditLogger;
use App\Services\Marketplace\SellerPrincipal;
use App\Services\SellerAutomation\AutomationAction;

/**
 * Take a listing off the storefront.
 *
 * The safest automated change there is: nothing is lost, no price moves, no stock moves, and a
 * single row goes back the way it came. That is exactly why it is the first action offered — the
 * seller can watch automation work on something reversible before trusting it with anything else.
 */
class HideListingAction implements AutomationAction
{
    public const KEY = 'hide_listing';

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

        if ((int) $subject->status === 0) {
            return ['ok' => false, 'reason' => 'automation_reason_already_hidden', 'label' => $subject->getRawOriginal('name')];
        }

        return [
            'ok' => true,
            'label' => $subject->getRawOriginal('name'),
            'before' => ['status' => 1],
            'after' => ['status' => 0],
        ];
    }

    public function apply(object $subject, array $settings, SellerPrincipal $principal): array
    {
        $planned = $this->preview($subject, $settings);

        if (!$planned['ok']) {
            return $planned;
        }

        /** @var Product $subject */
        $subject->forceFill(['status' => 0])->save();

        $this->audit->record(
            action: 'seller.listing_hidden_by_rule',
            subject: ['type' => 'product', 'id' => $subject->id],
            before: ['status' => 1],
            after: ['status' => 0],
            context: ['actor' => $principal->actorLabel(), 'seller_id' => $principal->sellerId()],
        );

        return $planned;
    }
}
