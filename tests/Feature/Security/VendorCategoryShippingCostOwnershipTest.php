<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The vendor panel's category-wise shipping cost form posts the ids of the rows it listed (the seller's own: seller_id the seller) with a cost for
 * each, and the handler updated whatever row each id named. A seller (or staff with shop_settings.manage) could post another seller's rows, or the
 * platform's own (seller_id 0), and change what customers pay for shipping, and an id that does not exist crashed the handler.
 *
 * Rows 1 and 4 are seller 1's, row 2 is seller 2's, row 3 is the platform's.
 */
class VendorCategoryShippingCostOwnershipTest extends VendorPanelTestCase
{
    private const OWN_ROW = 1;
    private const OTHER_SELLERS_ROW = 2;
    private const PLATFORM_ROW = 3;
    private const OTHER_OWN_ROW = 4;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('category_shipping_costs', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('category_id');
            $table->decimal('cost', 16, 2)->default(0);
            $table->boolean('multiply_qty')->default(false);
            $table->timestamps();
        });
        DB::table('category_shipping_costs')->insert([
            ['id' => self::OWN_ROW, 'seller_id' => self::SELLER, 'category_id' => 5, 'cost' => 10, 'multiply_qty' => 0],
            ['id' => self::OTHER_SELLERS_ROW, 'seller_id' => self::OTHER_SELLER, 'category_id' => 5, 'cost' => 20, 'multiply_qty' => 0],
            ['id' => self::PLATFORM_ROW, 'seller_id' => 0, 'category_id' => 5, 'cost' => 30, 'multiply_qty' => 0],
            ['id' => self::OTHER_OWN_ROW, 'seller_id' => self::SELLER, 'category_id' => 6, 'cost' => 40, 'multiply_qty' => 0],
        ]);
    }

    /** @return array<string, array{list<int>}> the ids a seller may not post: any that is not their own row, with or without their own beside it */
    public static function idsThatAreNotAllTheSellersOwn(): array
    {
        return [
            'another seller row' => [[self::OTHER_SELLERS_ROW]],
            'a platform row' => [[self::PLATFORM_ROW]],
            'an own row and another seller row' => [[self::OWN_ROW, self::OTHER_SELLERS_ROW]],
            'a row that does not exist' => [[999]],
        ];
    }

    /** @param list<int> $ids */
    #[DataProvider('idsThatAreNotAllTheSellersOwn')]
    public function test_a_post_naming_a_row_that_is_not_the_sellers_changes_no_row_at_all(array $ids): void
    {
        $before = $this->costRows();

        $this->signInAs(self::SELLER)->post('/vendor/business-settings/category-wise-shipping-cost/index', $this->form(ids: $ids))->assertRedirect();

        $this->assertSame(['error'], $this->toastTypes());
        $this->assertSame($before, $this->costRows());
    }

    public function test_the_sellers_own_rows_are_still_updated(): void
    {
        $this->signInAs(self::SELLER)->post('/vendor/business-settings/category-wise-shipping-cost/index', $this->form(ids: [self::OWN_ROW, self::OTHER_OWN_ROW], multiplyQuantityOf: [self::OTHER_OWN_ROW]))->assertRedirect();

        $this->assertSame(['success'], $this->toastTypes());
        $rows = $this->costRows();
        $this->assertSame([[5.0, 0], [7.0, 1]], [[(float)$rows[0]['cost'], (int)$rows[0]['multiply_qty']], [(float)$rows[3]['cost'], (int)$rows[3]['multiply_qty']]]);
        $this->assertSame([20.0, 30.0], [(float)$rows[1]['cost'], (float)$rows[2]['cost']], 'the rows of the other seller and of the platform are untouched');
    }

    public function test_a_staff_member_with_shop_settings_updates_only_the_shops_own_rows(): void
    {
        $this->buildStaffTables();
        $staffId = $this->insertStaffMember(name: 'shop', permissions: ['shop_settings.manage']);
        $before = $this->costRows();

        $this->signInAsStaff($staffId)->post('/vendor/business-settings/category-wise-shipping-cost/index', $this->form(ids: [self::PLATFORM_ROW]))->assertRedirect();

        $this->assertSame($before, $this->costRows());
    }

    /**
     * @param list<int> $ids
     * @param list<int> $multiplyQuantityOf
     * @return array<string, list<int|float>> what the form posts: a cost for every id and the ids whose switch is on
     */
    private function form(array $ids, array $multiplyQuantityOf = []): array
    {
        return ['ids' => $ids, 'cost' => array_map(static fn (int $position): float => 5.0 + 2 * $position, array_keys($ids)), 'multiplyQTY' => $multiplyQuantityOf];
    }

    /** @return list<array<string, mixed>> */
    private function costRows(): array
    {
        return DB::table('category_shipping_costs')->orderBy('id')->get()->map(static fn (object $row): array => Arr::except((array)$row, ['created_at', 'updated_at']))->all();
    }
}
