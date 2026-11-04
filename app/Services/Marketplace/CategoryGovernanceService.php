<?php

namespace App\Services\Marketplace;

use App\Models\CategoryGovernance;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves the effective operational policy for a category (Phase 3, Stage A — spec item 10).
 *
 * The one rule that matters throughout: a category with no governance row, or a null field on one,
 * **inherits the global default**. So this can be adopted one category at a time, and an install that
 * sets nothing behaves exactly as it does today. Every method here is written around that fallback.
 */
class CategoryGovernanceService
{
    /**
     * The return window for a category, in days.
     *
     * Falls back to the global `refund_day_limit` when the category sets none — which is the current
     * behaviour for every product. A category may set 0 to mean "no returns", which is distinct from
     * null ("inherit"), so the caller must not treat 0 as unset.
     */
    public function returnWindowDays(int|string|null $categoryId): int
    {
        $global = (int) ($this->globalSetting('refund_day_limit') ?? 0);

        if (!$categoryId || !Schema::hasTable('category_governance')) {
            return $global;
        }

        $window = CategoryGovernance::where('category_id', $categoryId)->value('return_window_days');

        // Null (no row, or the field left blank) inherits; a set value — including 0 — is honoured.
        return $window === null ? $global : (int) $window;
    }

    /**
     * Is a refund still inside the return window for this category, counting from $startedAt?
     *
     * Central so the storefront and the API enforce the same rule instead of each re-deriving it.
     */
    public function isWithinReturnWindow(int|string|null $categoryId, ?\DateTimeInterface $startedAt, ?\DateTimeInterface $now = null): bool
    {
        if ($startedAt === null) {
            return true;    // nothing to measure against yet — the delivery clock has not started
        }

        $window = $this->returnWindowDays($categoryId);
        $daysElapsed = \Carbon\Carbon::instance($startedAt)->diffInDays($now ?? \Carbon\Carbon::now());

        return $daysElapsed <= $window;
    }

    /** Does this category force every product through moderation, whatever the seller's trust level? */
    public function requiresModeration(int|string|null $categoryId): bool
    {
        if (!$categoryId || !Schema::hasTable('category_governance')) {
            return false;
        }

        return (bool) CategoryGovernance::where('category_id', $categoryId)->value('requires_moderation');
    }

    /** Attribute keys a product in this category must fill. Empty when none are configured. */
    public function requiredAttributes(int|string|null $categoryId): array
    {
        if (!$categoryId || !Schema::hasTable('category_governance')) {
            return [];
        }

        return CategoryGovernance::where('category_id', $categoryId)->value('required_attributes') ?? [];
    }

    /**
     * Which required attributes a product is missing — the gate a moderator or a save-time check
     * uses to say "this category needs a strength and a volume before it can go live."
     *
     * @param array<string, mixed> $productAttributes the attribute keys the product actually has
     * @return array<int, string> the missing keys, empty when the product satisfies the category
     */
    public function missingRequiredAttributes(int|string|null $categoryId, array $productAttributes): array
    {
        $required = $this->requiredAttributes($categoryId);

        return array_values(array_filter($required, function ($key) use ($productAttributes) {
            return !isset($productAttributes[$key]) || $productAttributes[$key] === '' || $productAttributes[$key] === null;
        }));
    }

    public function taxClass(int|string|null $categoryId): ?string
    {
        if (!$categoryId || !Schema::hasTable('category_governance')) {
            return null;
        }

        return CategoryGovernance::where('category_id', $categoryId)->value('tax_class');
    }

    private function globalSetting(string $name): mixed
    {
        try {
            return getWebConfig(name: $name);
        } catch (\Throwable) {
            return null;
        }
    }
}
