<?php

namespace App\Services\Shipping;

use App\Models\ProductDestinationPermission as PermissionRow;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a product may go to a destination country (H1-03).
 *
 * **No record means no.** A product with no row for a destination outside its own country may not go there, and that is a decision rather
 * than an oversight: the three permissions this reads -- carrying a thing, taking it out of one country, bringing it into another -- are
 * granted by three different authorities, and a platform that assumes them is a platform that ships a medicine into a country that bans it.
 * The requirement says it in as many words: the system never assumes a product may ship abroad.
 *
 * A DOMESTIC destination needs no record at all. A product sold in a country and delivered in that country crosses no border, asks no
 * customs officer and needs nobody's permission, which is why the Saudi platform is unchanged by this and why the table ships empty.
 *
 * It is silent before its migration runs: a shop that has not run it has no permissions table, and a domestic shop needs none, so the
 * answer is the same as it has always been. A foreign destination on such a shop is refused, which is the safe direction.
 */
final class ProductDestinationPermission
{
    /** @var bool|null whether the table exists; a schema read is not repeated for every line of a cart */
    private static ?bool $tableReady = null;

    /** Whether this product may reach this destination from this source. Domestic is always allowed; anything else needs a record. */
    public static function allows(int|string|null $productId, ?string $source, ?string $destination): bool
    {
        return self::refusalFor($productId, $source, $destination) === null;
    }

    /**
     * Why this product may not reach this destination, as a translation key, or null when it may.
     *
     * The reasons are told apart because they are acted on differently: a missing record is somebody's to grant, while a record that
     * withholds one of the three is a refusal that has already been considered.
     */
    public static function refusalFor(int|string|null $productId, ?string $source, ?string $destination): ?string
    {
        $from = self::country($source);
        $to = self::country($destination);

        if ($from === null || $to === null) {
            return ShippingRefusal::DESTINATION_UNKNOWN;
        }

        if ($from === $to) {
            return null;
        }

        if ($productId === null || !self::tableReady()) {
            return ShippingRefusal::NOT_PERMITTED_ABROAD;
        }

        $row = PermissionRow::query()->where('product_id', $productId)->where('destination_country', $to)->first();

        return match (true) {
            $row === null => ShippingRefusal::NOT_PERMITTED_ABROAD,
            !$row->may_ship => ShippingRefusal::MAY_NOT_SHIP,
            !$row->may_export => ShippingRefusal::MAY_NOT_BE_EXPORTED,
            !$row->may_import => ShippingRefusal::MAY_NOT_BE_IMPORTED,
            default => null,
        };
    }

    /** Tests build and drop the table inside one process, so what was read once is not remembered. */
    public static function forget(): void
    {
        self::$tableReady = null;
    }

    private static function tableReady(): bool
    {
        return self::$tableReady ??= Schema::hasTable('product_destination_permissions');
    }

    private static function country(?string $code): ?string
    {
        // Through the one normaliser, because the values do not agree: a product's market is a code and the address book holds the English
        // NAME. Comparing the two directly made a Riyadh-to-Riyadh order read as crossing a border.
        return DestinationCountryCode::of($code);
    }
}
