<?php

namespace App\Services\Shipping;

use App\Models\Admin;
use App\Models\ProductDestinationPermission as PermissionRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A regulated product does not cross a border because a courier happens to fly there (H1-04).
 *
 * A medicine, a supplement or a medical device needs somebody's licence before it leaves one country and somebody else's before it enters
 * another, and a carrier's route map says nothing about either. The requirement is written as a sentence about the world: a carrier that
 * covers the route never overrides this. So this gate sits beside the carrier check rather than behind it, and asks a different question of
 * the same shipment.
 *
 * It **fails closed**, in the same three ways {@see \App\Services\SalesChannels\Listings\RegulatedProductGate} does for a channel listing,
 * because the reasoning is the same and a second pattern for the same problem is a second set of mistakes:
 *
 *  - `mode` `off`, which is how it ships: the gate stops nothing. A platform selling inside one country has no export licence to hold, and
 *    the Saudi storefront must behave to the byte as it does today. Off is the owner's position, not an empty list;
 *  - `mode` `strict`: a product nobody has classified -- no categories at all -- is REFUSED for a foreign destination. "We could not tell
 *    what this is" is not "it is fine to export";
 *  - a product the owner's lists name needs a **recorded clearance** for that destination: a reference somebody can be asked to produce
 *    (the licence, the registration, the decision) and the person who recorded it, whose role must carry the module that governs this.
 *    An admin id on a row is not a clearance, and neither is a reference nobody is answerable for.
 *
 * The clearance lives on the destination permission row (H1-03) rather than in a table of its own: one place saying what a product may do
 * and on whose authority is one place to read before a parcel moves and one place to withdraw when a licence lapses. Withdrawing the row
 * withdraws the clearance with it, which is the behaviour an expiring licence needs.
 *
 * The lists are per destination as well as global, because what one country closes at its border is not what the next one closes. Which
 * classes may cross which border, and who is entitled to approve that list, is the owner's open question Q-13; this gate holds whatever
 * answer is given and refuses in the meantime.
 */
final class RegulatedShippingGate
{
    public const NEEDS_CLEARANCE = ShippingRefusal::REGULATED_NEEDS_CLEARANCE;
    public const CLASSIFICATION_MISSING = ShippingRefusal::REGULATED_CLASSIFICATION_MISSING;
    public const CLEARANCE_NOT_PERMITTED = ShippingRefusal::CLEARANCE_NOT_PERMITTED;

    public const MODE_OFF = 'off';
    public const MODE_STRICT = 'strict';

    /** Which admin module a person must hold to clear a regulated export. Part of Q-13, so it is a setting and not a constant. */
    private const DEFAULT_MODULE = 'catalog';

    /** @var array<int, bool> whether an admin may clear, remembered per request so a cart of twenty lines asks once */
    private static array $mayClear = [];

    /** @var bool|null whether the permission table exists */
    private static ?bool $tableReady = null;

    /**
     * Why this product may not be shipped to this destination, as a translation key, or null when this gate does not stop it.
     *
     * A domestic shipment is not this gate's business: nothing is exported, nothing is imported, and the product's own country has already
     * decided it may be sold there.
     */
    public function refusalFor(int|string|null $productId, ?string $source, ?string $destination): ?string
    {
        $to = self::country($destination);
        $from = self::country($source);

        if ($to === null || $from === null || $from === $to || $this->modeFor($to) !== self::MODE_STRICT) {
            return null;
        }

        $product = $productId === null ? null : $this->productOf($productId);

        if ($product === null || !$this->isClassified($product)) {
            return self::CLASSIFICATION_MISSING;
        }

        if (!$this->matchesTheOwnersRules($product, $to)) {
            return null;
        }

        return $this->clearanceRefusal(productId: $productId, destination: $to);
    }

    /**
     * Does this product need a clearance before it may be shipped to this destination? For a screen, so that the reference is asked for
     * while a person is recording the permission rather than discovered by a refused customer.
     */
    public function needsClearance(int|string|null $productId, ?string $source, ?string $destination): bool
    {
        $to = self::country($destination);
        $from = self::country($source);

        if ($to === null || $from === null || $from === $to || $this->modeFor($to) !== self::MODE_STRICT) {
            return false;
        }

        $product = $productId === null ? null : $this->productOf($productId);

        return $product === null || !$this->isClassified($product) || $this->matchesTheOwnersRules($product, $to);
    }

    /** May this admin record a clearance? Their role has to carry the module; an id that belongs to no admin never may. */
    public function mayRecordClearance(?int $adminId): bool
    {
        if ($adminId === null || $adminId < 1 || !Schema::hasTable('admins')) {
            return false;
        }

        return self::$mayClear[$adminId] ??= $this->roleCarriesTheModule($adminId);
    }

    /** `off` or `strict`, the destination's own setting first. */
    public function modeFor(?string $destination): string
    {
        $country = self::country($destination);
        $perDestination = (string) ($country === null ? '' : config('gcc.shipping.regulated.destinations.' . $country . '.mode', ''));

        if (in_array($perDestination, [self::MODE_OFF, self::MODE_STRICT], true)) {
            return $perDestination;
        }

        return config('gcc.shipping.regulated.mode') === self::MODE_STRICT ? self::MODE_STRICT : self::MODE_OFF;
    }

    /** Tests build and drop the table and sign in as different admins inside one process; what was read once is not remembered. */
    public static function forget(): void
    {
        self::$mayClear = [];
        self::$tableReady = null;
    }

    /**
     * The clearance on the permission row, or the reason it is not one.
     *
     * Three things have to hold together: a row exists for this destination, it carries a reference, and the person who recorded it was
     * entitled to. Missing any of them, the product stays where it is.
     */
    private function clearanceRefusal(int|string $productId, string $destination): ?string
    {
        if (!self::tableReady()) {
            return self::NEEDS_CLEARANCE;
        }

        $row = PermissionRow::query()
            ->where('product_id', $productId)
            ->where('destination_country', $destination)
            ->first(['clearance_reference', 'recorded_by']);

        if ($row === null || trim((string) $row->clearance_reference) === '') {
            return self::NEEDS_CLEARANCE;
        }

        return $this->mayRecordClearance($row->recorded_by === null ? null : (int) $row->recorded_by)
            ? null
            : self::CLEARANCE_NOT_PERMITTED;
    }

    private function roleCarriesTheModule(int $adminId): bool
    {
        $admin = Admin::with('role')->find($adminId);

        if ($admin === null) {
            return false;
        }

        if ((int) $admin->admin_role_id === 1) {
            return true;
        }

        $role = $admin->role;
        $module = (string) config('gcc.shipping.regulated.clearance_module', self::DEFAULT_MODULE);

        return (int) ($role->status ?? 0) === 1
            && in_array($module, (array) json_decode((string) ($role->module_access ?? ''), true), true);
    }

    private function productOf(int|string $productId): ?object
    {
        return DB::table('products')->where('id', $productId)->first(['id', 'name', 'brand_id', 'category_ids']);
    }

    /** Has anybody said what this product IS? Without a category there is nothing to compare with the owner's list. */
    private function isClassified(object $product): bool
    {
        return $this->categoryIdsOf($product) !== [];
    }

    private function matchesTheOwnersRules(object $product, string $destination): bool
    {
        return array_intersect($this->categoryIdsOf($product), $this->rule('category_ids', $destination)) !== []
            || $this->nameMatches((string) ($product->name ?? ''), $this->rule('name_patterns', $destination))
            || in_array((string) ($product->brand_id ?? ''), $this->rule('brand_ids', $destination), true);
    }

    /**
     * One rule list: the destination's own entries and the global ones together, so a global rule is never lost by naming a country.
     *
     * @return list<string>
     */
    private function rule(string $name, string $destination): array
    {
        $global = (array) config('gcc.shipping.regulated.' . $name, []);
        $perDestination = (array) config('gcc.shipping.regulated.destinations.' . $destination . '.' . $name, []);

        return array_values(array_unique(array_filter(
            array_map('strval', [...$global, ...$perDestination]),
            static fn (string $value): bool => $value !== '',
        )));
    }

    /**
     * The platform keeps a product's categories as JSON (`[{"id":"5","position":1}]`), and an id there may be a string or a number; both
     * are compared as text.
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

    private static function tableReady(): bool
    {
        return self::$tableReady ??= Schema::hasTable('product_destination_permissions')
            && Schema::hasColumn('product_destination_permissions', 'clearance_reference');
    }

    private static function country(?string $code): ?string
    {
        // Through the one normaliser, because the values do not agree: a product's market is a code and the address book holds the English
        // NAME. Comparing the two directly made a Riyadh-to-Riyadh order read as crossing a border.
        return DestinationCountryCode::of($code);
    }
}
