<?php

namespace App\Services\SalesChannels\Listings;

use App\Models\Admin;
use App\Models\ChannelListingDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A pharmacy's catalogue is not an ordinary one: a medicine, a supplement or a medical device can need a licence, a registration number or a document before a channel may show
 * it, and some categories and brands are closed on some channels altogether.
 *
 * This gate does NOT decide what the rules are — nobody can read them out of the code of a shop — and it does not pretend that a person clicking is a regulator. It holds the
 * owner's own rules, per channel, and **fails closed**:
 *
 *  - `mode` `off` (how it ships): the gate stops nothing. It is the owner's deliberate choice, not an accident of empty lists;
 *  - `mode` `strict`: a product this gate cannot classify — no categories recorded at all — is REFUSED, because "we could not tell" is not "it is fine";
 *  - a product the owner's lists name needs a **recorded clearance** on the draft: a reference (the licence, the decision, the file) and the person who recorded it. An
 *    `--admin=` on a command line is not that, and neither is a request that merely carries an admin id;
 *  - the person who records a clearance must hold the `sales_channels` module, which is checked against their role, not taken on trust.
 *
 * Rules are per channel as well as global, because what Amazon closes and what Trendyol closes are different lists.
 */
class RegulatedProductGate
{
    public const NEEDS_CLEARANCE = 'regulated_product_needs_recorded_clearance';
    public const CLASSIFICATION_MISSING = 'regulated_classification_missing';
    public const CLEARANCE_NOT_PERMITTED = 'regulatory_clearance_needs_the_sales_channels_permission';

    public const MODE_OFF = 'off';
    public const MODE_STRICT = 'strict';

    private const MODULE = 'sales_channels';

    /** Why this draft may not be listed, or null when nothing here stops it. */
    public function refusalFor(ChannelListingDraft $draft): ?string
    {
        $channel = (string) $draft->sales_channel;
        if ($this->modeFor($channel) !== self::MODE_STRICT) {
            return null;
        }

        $product = $this->productOf((int) $draft->product_id);
        if ($product === null || !$this->isClassified($product)) {
            // Fail closed: a product nobody has put in a category cannot be judged against the owner's list.
            return self::CLASSIFICATION_MISSING;
        }
        if (!$this->matchesTheOwnersRules($product, $channel)) {
            return null;
        }

        return $draft->hasRecordedClearance() ? null : self::NEEDS_CLEARANCE;
    }

    /** Does this product need a clearance before it may be listed on this channel? For a screen, so that the clearance is asked for before the attempt. */
    public function needsClearance(int $productId, string $channel): bool
    {
        if ($this->modeFor($channel) !== self::MODE_STRICT) {
            return false;
        }
        $product = $this->productOf($productId);

        return $product === null || !$this->isClassified($product) || $this->matchesTheOwnersRules($product, $channel);
    }

    /** May this admin record a clearance? Their role has to carry the module; an id that is not an admin's never may. */
    public function mayRecordClearance(?int $adminId): bool
    {
        if ($adminId === null || $adminId < 1 || !Schema::hasTable('admins')) {
            return false;
        }
        $admin = Admin::with('role')->find($adminId);
        if ($admin === null) {
            return false;
        }
        if ((int) $admin->admin_role_id === 1) {
            return true;
        }
        $role = $admin->role;

        return (int) ($role->status ?? 0) === 1 && in_array(self::MODULE, (array) json_decode((string) ($role->module_access ?? ''), true), true);
    }

    /** `off` or `strict`, the channel's own setting first. */
    public function modeFor(string $channel): string
    {
        $perChannel = (string) (config('sales_channels.listing.regulated.channels.' . $channel . '.mode') ?? '');

        return in_array($perChannel, [self::MODE_OFF, self::MODE_STRICT], true)
            ? $perChannel
            : (config('sales_channels.listing.regulated.mode') === self::MODE_STRICT ? self::MODE_STRICT : self::MODE_OFF);
    }

    private function productOf(int $productId): ?object
    {
        return DB::table('products')->where('id', $productId)->first(['id', 'name', 'brand_id', 'category_ids']);
    }

    /** Has anybody said what this product IS? Without a category there is nothing to compare with the owner's list. */
    private function isClassified(object $product): bool
    {
        return $this->categoryIdsOf($product) !== [];
    }

    private function matchesTheOwnersRules(object $product, string $channel): bool
    {
        return $this->inARegulatedCategory($product, $this->rule('category_ids', $channel))
            || $this->nameMatches((string) ($product->name ?? ''), $this->rule('name_patterns', $channel))
            || in_array((string) ($product->brand_id ?? ''), $this->rule('brand_ids', $channel), true);
    }

    /**
     * One rule list: the channel's own entries and the global ones together, so a global rule is never lost by naming a channel.
     *
     * @return list<string>
     */
    private function rule(string $name, string $channel): array
    {
        $global = (array) config('sales_channels.listing.regulated.' . $name, []);
        $perChannel = (array) config('sales_channels.listing.regulated.channels.' . $channel . '.' . $name, []);

        return array_values(array_unique(array_filter(array_map('strval', [...$global, ...$perChannel]), static fn (string $value): bool => $value !== '')));
    }

    /**
     * @param list<string> $regulated
     */
    private function inARegulatedCategory(object $product, array $regulated): bool
    {
        return $regulated !== [] && array_intersect($this->categoryIdsOf($product), $regulated) !== [];
    }

    /**
     * The platform keeps a product's categories as JSON (`[{"id":"5","position":1}]`), and an id there may be a string or a number; both are compared as text.
     *
     * @return list<string>
     */
    private function categoryIdsOf(object $product): array
    {
        $decoded = json_decode((string) ($product->category_ids ?? ''), true);
        $ids = [];
        foreach (is_array($decoded) ? $decoded : [] as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : $entry;
            if ($id !== null && (string) $id !== '') {
                $ids[] = (string) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param list<string> $patterns */
    private function nameMatches(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && mb_stripos($name, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
