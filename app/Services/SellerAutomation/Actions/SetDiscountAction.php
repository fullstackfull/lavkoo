<?php

namespace App\Services\SellerAutomation\Actions;

use App\Models\Product;
use App\Models\SellerAutomationAction as ActionRecord;
use App\Services\Marketplace\PricingPolicyService;
use App\Services\Marketplace\SellerPrincipal;
use App\Services\SellerAutomation\AutomationAction;

/**
 * Mark slow stock down, within a floor the seller sets.
 *
 * The floor is required rather than optional, and the action refuses rather than clamps. A rule
 * that silently applies a smaller discount than it was told to is a rule the seller cannot reason
 * about: they set 30% off, saw 30% off in the preview, and would find 4% in the shop. Refusing with
 * a reason on the row is the only outcome that leaves them able to fix it.
 *
 * Every change made here is attributed to automation in the price history, with the rule named, so
 * a seller looking at a price they did not type can find out which of their rules moved it.
 */
class SetDiscountAction implements AutomationAction
{
    public const KEY = 'set_discount';

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
        return [
            'discount_type' => 'required|in:percent,flat',
            'discount_value' => 'required|numeric|gt:0',
            // The seller's own line in the sand, and the reason this action can be trusted to run
            // unattended. Without it a percentage rule and a cheap product make a free product.
            'min_price_after_discount' => 'required|numeric|gt:0',
        ];
    }

    public function revertibleColumns(): array
    {
        return ['discount', 'discount_type'];
    }

    public function preview(object $subject, array $settings): array
    {
        if (!$subject instanceof Product) {
            return ['ok' => false, 'reason' => 'automation_reason_wrong_subject'];
        }

        $unitPrice = (float) $subject->unit_price;
        $type = (string) $settings['discount_type'];
        $value = (float) $settings['discount_value'];
        $floor = (float) $settings['min_price_after_discount'];

        if ($unitPrice <= 0) {
            return ['ok' => false, 'reason' => 'automation_reason_no_price', 'label' => $subject->getRawOriginal('name')];
        }

        if ($type === 'percent' && $value >= 100) {
            return ['ok' => false, 'reason' => 'automation_reason_discount_not_below_price', 'label' => $subject->getRawOriginal('name')];
        }

        $discountAmount = $type === 'percent' ? $unitPrice * $value / 100 : $value;
        $priceAfter = round($unitPrice - $discountAmount, 2);

        if ($priceAfter <= 0) {
            return ['ok' => false, 'reason' => 'automation_reason_discount_not_below_price', 'label' => $subject->getRawOriginal('name')];
        }

        if ($priceAfter < $floor) {
            return ['ok' => false, 'reason' => 'automation_reason_below_floor', 'label' => $subject->getRawOriginal('name')];
        }

        // The shop's own floor as well as this rule's. A rule written before the floor was set must
        // not keep marking things down past it, and a seller who set one expects it to hold
        // everywhere rather than only where they remembered to repeat it.
        $policy = app(PricingPolicyService::class)->check($subject, $unitPrice, $value, $type);

        if (!$policy['ok']) {
            // array_merge, not `+`: `+` keeps the left operand for duplicate keys, which is how a
            // computed value silently loses to whatever was already there.
            return array_merge($policy, ['label' => $subject->getRawOriginal('name')]);
        }

        $before = ['discount' => (float) $subject->discount, 'discount_type' => (string) $subject->discount_type];
        $after = ['discount' => $value, 'discount_type' => $type === 'percent' ? 'percent' : 'flat'];

        if ($before['discount'] === $after['discount'] && $before['discount_type'] === $after['discount_type']) {
            return ['ok' => false, 'reason' => 'automation_reason_already_at_this_discount', 'label' => $subject->getRawOriginal('name')];
        }

        return [
            'ok' => true,
            'label' => $subject->getRawOriginal('name'),
            'before' => $before,
            'after' => $after + ['price_after_discount' => $priceAfter],
        ];
    }

    public function apply(object $subject, array $settings, SellerPrincipal $principal): array
    {
        $planned = $this->preview($subject, $settings);

        if (!$planned['ok']) {
            return $planned;
        }

        // The engine already has the whole run attributed to this rule in the price history, so the
        // save is all that is left to do here.
        /** @var Product $subject */
        $subject->forceFill([
            'discount' => $planned['after']['discount'],
            'discount_type' => $planned['after']['discount_type'],
        ])->save();

        return $planned;
    }
}
