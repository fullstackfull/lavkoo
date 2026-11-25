<?php

namespace App\Services\SalesChannels\Listings;

use App\Models\Admin;
use App\Models\ChannelListingDraft;
use App\Services\SalesChannels\Support\ChannelAccountCountry;
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
 * Rules are per channel as well as global, because what Amazon closes and what Trendyol closes are different lists -- and per MARKET
 * since H2-09, because what a regulator closes is a country's decision and not a marketplace's: the Saudi list is not the Emirati one,
 * and one product may need a licence on noon Saudi Arabia and none on noon in the Emirates.
 *
 * The market of a listing is the country the ACCOUNT sells in ({@see ChannelAccountCountry}), because a regulator cares where a thing is
 * sold, not where it came from. An account that names no country is judged by the global and per-channel rules alone, which is every
 * account of this installation today -- and the gate still fails closed in `strict` mode for all of them, so no rule is weakened by a
 * market nobody named.
 *
 * A mode is the most specific one anybody set: (market, channel), then market, then channel, then global. A MARKET's mode beats a
 * CHANNEL's, because a country's regulator outranks a marketplace's policy. The four lists are ADDED together, so naming a market never
 * loses a global rule.
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
        $market = $this->marketOf($draft);
        if ($this->modeFor($channel, $market) !== self::MODE_STRICT) {
            return null;
        }

        $product = $this->productOf((int) $draft->product_id);
        if ($product === null || !$this->isClassified($product)) {
            // Fail closed: a product nobody has put in a category cannot be judged against the owner's list.
            return self::CLASSIFICATION_MISSING;
        }
        if (!$this->matchesTheOwnersRules($product, $channel, $market)) {
            return null;
        }

        return $draft->hasRecordedClearance() ? null : self::NEEDS_CLEARANCE;
    }

    /**
     * Does this product need a clearance before it may be listed on this channel, in this market? For a screen, so that the clearance is
     * asked for before the attempt. The market is the account's own; a caller that does not know it asks the global and channel rules,
     * which is what this answered before markets existed.
     */
    public function needsClearance(int $productId, string $channel, ?string $market = null): bool
    {
        if ($this->modeFor($channel, $market) !== self::MODE_STRICT) {
            return false;
        }
        $product = $this->productOf($productId);

        return $product === null || !$this->isClassified($product) || $this->matchesTheOwnersRules($product, $channel, $market);
    }

    /** The market a draft is judged in: the country its account sells in, or null when the account names none (or is gone). */
    public function marketOf(ChannelListingDraft $draft): ?string
    {
        $account = $draft->account;

        return $account === null ? null : app(ChannelAccountCountry::class)->of($account);
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

    /**
     * `off` or `strict`: the most specific setting anybody made, which is (market, channel), then market, then channel, then global.
     *
     * A market's setting outranks a channel's on purpose. A country's regulator decides what may be sold in that country, and a
     * marketplace's policy cannot open what the regulator closed -- so an operator who puts one market in `strict` has put it in
     * `strict` on every channel, including one whose own entry says `off`.
     */
    public function modeFor(string $channel, ?string $market = null): string
    {
        foreach ([$this->marketKey($market) . '.channels.' . $channel, $this->marketKey($market), 'channels.' . $channel] as $path) {
            $mode = (string) (config('sales_channels.listing.regulated.' . $path . '.mode') ?? '');

            if (in_array($mode, [self::MODE_OFF, self::MODE_STRICT], true)) {
                return $mode;
            }
        }

        return config('sales_channels.listing.regulated.mode') === self::MODE_STRICT ? self::MODE_STRICT : self::MODE_OFF;
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

    private function matchesTheOwnersRules(object $product, string $channel, ?string $market = null): bool
    {
        return $this->inARegulatedCategory($product, $this->rule('category_ids', $channel, $market))
            || $this->nameMatches((string) ($product->name ?? ''), $this->rule('name_patterns', $channel, $market))
            || in_array((string) ($product->brand_id ?? ''), $this->rule('brand_ids', $channel, $market), true);
    }

    /**
     * One rule list: the global entries, the channel's own, the market's own and the market's own for that channel, all together -- so
     * naming a channel never loses a global rule and naming a market never loses either.
     *
     * @return list<string>
     */
    private function rule(string $name, string $channel, ?string $market = null): array
    {
        $entries = [];
        foreach (['', 'channels.' . $channel . '.', $this->marketKey($market) . '.', $this->marketKey($market) . '.channels.' . $channel . '.'] as $path) {
            $entries = [...$entries, ...(array) config('sales_channels.listing.regulated.' . $path . $name, [])];
        }

        return array_values(array_unique(array_filter(array_map('strval', $entries), static fn (string $value): bool => $value !== '')));
    }

    /**
     * The configuration path of a market's own rules. A caller that names no market gets a path that cannot exist -- `markets.` with
     * nothing after it -- so `config()` answers null for it and the market level is simply skipped, rather than every market's rules
     * being read for an account that belongs to none.
     */
    private function marketKey(?string $market): string
    {
        $code = strtoupper(trim((string) $market));

        return 'markets.' . ($code === '' ? '-' : $code);
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
