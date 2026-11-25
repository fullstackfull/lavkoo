<?php

namespace Tests\Feature\Storefront;

use App\Models\ProductDestinationPermission as PermissionRow;
use App\Services\Shipping\ProductDestinationPermission;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * H1-03: a product may go abroad only where somebody has said it may.
 *
 * The platform recorded none of the three permissions a border asks about -- carrying a thing, taking it out of one country, bringing it
 * into another. They are granted by three different authorities, so a platform that assumes them is a platform that ships a medicine into
 * a country that bans it. The requirement puts it plainly: the system never assumes a product may ship abroad.
 *
 * So **no record means no**, and the first test here is the one that matters: with the table empty, which is how it ships, nothing may
 * cross a border. A DOMESTIC destination is the exception and needs no record at all -- a product sold and delivered in one country asks no
 * customs officer -- which is why the Saudi platform is untouched by all of this.
 *
 * The refusals are told apart because they are acted on differently: a missing record is somebody's to grant, while a record that withholds
 * one of the three has already been considered and refused.
 */
class ProductDestinationPermissionTest extends TestCase
{
    private const PRODUCT = 11;

    private const HOME = 'SA';

    private const ABROAD = 'KW';

    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_12_20_100000_create_product_destination_permissions_table.php'))->up();
        ProductDestinationPermission::forget();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('product_destination_permissions');
        ProductDestinationPermission::forget();

        parent::tearDown();
    }

    private function permit(array $permissions, string $destination = self::ABROAD, int $product = self::PRODUCT): void
    {
        PermissionRow::create($permissions + [
            'product_id' => $product,
            'destination_country' => $destination,
            'may_ship' => false,
            'may_export' => false,
            'may_import' => false,
        ]);
    }

    public function test_with_the_table_empty_nothing_crosses_a_border(): void
    {
        $this->assertFalse(ProductDestinationPermission::allows(self::PRODUCT, self::HOME, self::ABROAD));
        $this->assertSame('shipping_product_not_permitted_abroad', ProductDestinationPermission::refusalFor(self::PRODUCT, self::HOME, self::ABROAD));
    }

    /** And the reason the Saudi platform is unchanged: a sale inside one country needs nobody's permission. */
    public function test_a_domestic_destination_needs_no_record(): void
    {
        $this->assertTrue(ProductDestinationPermission::allows(self::PRODUCT, self::HOME, self::HOME));
        $this->assertNull(ProductDestinationPermission::refusalFor(self::PRODUCT, self::HOME, self::HOME));
        $this->assertTrue(ProductDestinationPermission::allows(self::PRODUCT, 'kw', 'KW'), 'the codes are read whatever case they arrive in');
    }

    public function test_all_three_permissions_are_needed(): void
    {
        $this->permit(['may_ship' => true, 'may_export' => true, 'may_import' => true]);

        $this->assertTrue(ProductDestinationPermission::allows(self::PRODUCT, self::HOME, self::ABROAD));
    }

    public static function withheldPermissions(): array
    {
        return [
            'it may not be carried' => [['may_ship' => false, 'may_export' => true, 'may_import' => true], 'shipping_product_may_not_ship'],
            'it may not leave its country' => [['may_ship' => true, 'may_export' => false, 'may_import' => true], 'shipping_product_may_not_be_exported'],
            'it may not enter the destination' => [['may_ship' => true, 'may_export' => true, 'may_import' => false], 'shipping_product_may_not_be_imported'],
        ];
    }

    #[DataProvider('withheldPermissions')]
    public function test_a_record_that_withholds_one_permission_refuses_and_says_which(array $permissions, string $reason): void
    {
        $this->permit($permissions);

        $this->assertFalse(ProductDestinationPermission::allows(self::PRODUCT, self::HOME, self::ABROAD));
        $this->assertSame($reason, ProductDestinationPermission::refusalFor(self::PRODUCT, self::HOME, self::ABROAD));
    }

    /** A grant is for the destination it names and for no other: permitting Kuwait does not permit Bahrain. */
    public function test_a_grant_permits_only_the_destination_it_names(): void
    {
        $this->permit(['may_ship' => true, 'may_export' => true, 'may_import' => true]);

        $this->assertTrue(ProductDestinationPermission::allows(self::PRODUCT, self::HOME, self::ABROAD));
        $this->assertFalse(ProductDestinationPermission::allows(self::PRODUCT, self::HOME, 'BH'));
    }

    /** And for the product it names: one product's permission is not another's. */
    public function test_a_grant_permits_only_the_product_it_names(): void
    {
        $this->permit(['may_ship' => true, 'may_export' => true, 'may_import' => true]);

        $this->assertFalse(ProductDestinationPermission::allows(99, self::HOME, self::ABROAD));
    }

    /** One row per product and destination: a second grant for the same pair would make "may it go there" ambiguous. */
    public function test_one_product_and_destination_can_hold_only_one_record(): void
    {
        $this->permit(['may_ship' => true, 'may_export' => true, 'may_import' => true]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->permit(['may_ship' => false, 'may_export' => false, 'may_import' => false]);
    }

    public function test_a_destination_or_a_source_nobody_knows_is_refused(): void
    {
        $this->assertSame('shipping_destination_unknown', ProductDestinationPermission::refusalFor(self::PRODUCT, self::HOME, null));
        $this->assertSame('shipping_destination_unknown', ProductDestinationPermission::refusalFor(self::PRODUCT, null, self::ABROAD));
        $this->assertSame('shipping_destination_unknown', ProductDestinationPermission::refusalFor(self::PRODUCT, self::HOME, '  '));
    }

    /** A shop that has not run the migration has no permissions table, and a domestic shop needs none: the answer does not change. */
    public function test_a_shop_without_the_table_still_sells_at_home_and_refuses_abroad(): void
    {
        Schema::dropIfExists('product_destination_permissions');
        ProductDestinationPermission::forget();

        $this->assertTrue(ProductDestinationPermission::allows(self::PRODUCT, self::HOME, self::HOME));
        $this->assertFalse(ProductDestinationPermission::allows(self::PRODUCT, self::HOME, self::ABROAD));
    }

    /** Every refusal this class can give has text in each locale the platform ships, so nothing reaches a customer as a bare key. */
    public function test_every_refusal_reason_has_text_in_every_locale(): void
    {
        $reasons = ['shipping_destination_unknown', 'shipping_product_not_permitted_abroad', 'shipping_product_may_not_ship', 'shipping_product_may_not_be_exported', 'shipping_product_may_not_be_imported'];

        foreach (['en', 'sa', 'ae'] as $locale) {
            $messages = require base_path("resources/lang/{$locale}/new-messages.php");
            $missing = array_values(array_diff($reasons, array_keys($messages)));

            $this->assertSame([], $missing, "missing from {$locale}: " . implode(', ', $missing));
        }
    }

    /** The customs facts a border asks about, added by the second migration and empty until somebody fills them. */
    public function test_a_product_can_carry_its_tariff_code_origin_and_weight(): void
    {
        if (!Schema::hasTable('products')) {
            Schema::create('products', fn (Blueprint $table) => $table->id());
        }
        (require database_path('migrations/2026_12_20_100100_add_customs_facts_to_products_table.php'))->up();

        foreach (['hs_code', 'origin_country', 'weight_grams'] as $column) {
            $this->assertTrue(Schema::hasColumn('products', $column), "products.{$column}");
        }
    }
}
