<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\DB;

/**
 * The vendor panel's shop pages act on the signed-in seller's own shop, never on an id taken from the URL or the form.
 *
 * Vendor\ShopController loaded and wrote whichever shop the URL (update/{id}) or the form (id) named, behind the sign-in guard only: any
 * approved seller, or any staff member, could open another seller's edit form, rename or re-address that shop, put it on vacation or close it,
 * and do the same to the in-house shop (seller_id 0). The v3 seller API and Vendor\ProfileController already act on the signed-in seller
 * whatever id arrives.
 *
 * The shop ids are crossed with the seller ids on purpose (seller 1 owns shop 12, seller 2 owns shop 11), so a fix that took the seller's id for
 * the shop's id would still land on somebody else's shop.
 */
class VendorShopScopeTest extends VendorPanelTestCase
{
    private const NO_SUCH_SHOP = 999;

    /** The columns the shop pages may write: a request must change none of them on a shop that is not the signed-in seller's. */
    private const SHOP_FIELDS = [
        'name', 'address', 'contact', 'image', 'banner', 'temporary_close', 'vacation_status', 'vacation_duration_type',
        'vacation_start_date', 'vacation_end_date', 'vacation_note',
    ];

    public function test_a_seller_cannot_close_another_shop_or_the_in_house_shop(): void
    {
        $this->signInAs(self::SELLER);

        foreach ([self::OTHER_SELLERS_SHOP, self::IN_HOUSE_SHOP] as $targetShopId) {
            $before = $this->shopRows();

            $this->post('/vendor/shop/close-shop-temporary', ['id' => $targetShopId, 'status' => 0]);

            $this->assertSame(0, (int) $this->shopRows()[$targetShopId]['temporary_close'], "shop {$targetShopId} is now closed for customers");
            $this->assertNoShopChangedExcept(shopId: self::SELLERS_SHOP, before: $before);
        }
    }

    public function test_a_seller_cannot_put_another_shop_or_the_in_house_shop_on_vacation(): void
    {
        $this->signInAs(self::SELLER);

        foreach ([self::OTHER_SELLERS_SHOP, self::IN_HOUSE_SHOP] as $targetShopId) {
            $before = $this->shopRows();

            $this->post('/vendor/shop/add-vacation', $this->vacationForm($targetShopId));

            $this->assertSame(0, (int) $this->shopRows()[$targetShopId]['vacation_status'], "shop {$targetShopId} is now on vacation");
            $this->assertNoShopChangedExcept(shopId: self::SELLERS_SHOP, before: $before);
        }
    }

    public function test_a_seller_cannot_rewrite_another_shop_or_the_in_house_shop(): void
    {
        $this->signInAs(self::SELLER);

        foreach ([self::OTHER_SELLERS_SHOP, self::IN_HOUSE_SHOP] as $targetShopId) {
            $before = $this->shopRows();

            $this->post('/vendor/shop/update/' . $targetShopId, $this->shopForm(name: 'HIJACKED'));

            $this->assertSame($before[$targetShopId], $this->shopRows()[$targetShopId], "shop {$targetShopId} was rewritten by another seller");
            $this->assertNoShopChangedExcept(shopId: self::SELLERS_SHOP, before: $before);
        }
    }

    public function test_a_seller_is_not_shown_another_shops_edit_form(): void
    {
        $this->signInAs(self::SELLER);

        foreach ([self::OTHER_SELLERS_SHOP, self::IN_HOUSE_SHOP] as $targetShopId) {
            $response = $this->get('/vendor/shop/update/' . $targetShopId);

            $response->assertOk();
            $response->assertDontSee('Bravo Pharmacy');
            $response->assertDontSee('Bravo Street 2, Dammam');
            $response->assertDontSee('Marketplace In-house');
            $response->assertSee('Alpha Pharmacy');
        }
    }

    public function test_a_request_naming_any_other_id_acts_on_the_callers_own_shop(): void
    {
        $this->signInAs(self::SELLER);

        foreach ([self::OTHER_SELLERS_SHOP, self::IN_HOUSE_SHOP, self::NO_SUCH_SHOP, null] as $namedShopId) {
            $before = $this->shopRows();

            $this->post('/vendor/shop/close-shop-temporary', ['id' => $namedShopId, 'status' => 0])->assertRedirect();
            $this->assertSame(1, (int) $this->shopRows()[self::SELLERS_SHOP]['temporary_close'], 'the caller\'s own shop is the one that closes');
            $this->assertNoShopChangedExcept(shopId: self::SELLERS_SHOP, before: $before);

            $this->post('/vendor/shop/close-shop-temporary', ['id' => $namedShopId, 'status' => 1])->assertRedirect();
            $this->assertSame(0, (int) $this->shopRows()[self::SELLERS_SHOP]['temporary_close']);
            $this->assertNoShopChangedExcept(shopId: self::SELLERS_SHOP, before: $before);
        }
    }

    public function test_a_seller_updates_their_own_shop(): void
    {
        $this->signInAs(self::SELLER);
        $before = $this->shopRows();

        $this->post('/vendor/shop/update/' . self::SELLERS_SHOP, $this->shopForm(name: 'Alpha Renamed'))->assertRedirect(route('vendor.shop.index'));

        $own = $this->shopRows()[self::SELLERS_SHOP];
        $this->assertSame(['Alpha Renamed', 'New Address 9, Riyadh', '0559998888'], [$own['name'], $own['address'], $own['contact']]);
        $this->assertSame(['def.png', 'def.png'], [$own['image'], $own['banner']], 'a form without files keeps the pictures');
        $this->assertNoShopChangedExcept(shopId: self::SELLERS_SHOP, before: $before);
    }

    public function test_a_seller_puts_their_own_shop_on_vacation_and_back(): void
    {
        $this->signInAs(self::SELLER);
        $before = $this->shopRows();

        $this->post('/vendor/shop/add-vacation', $this->vacationForm(self::SELLERS_SHOP))->assertRedirect();

        $own = $this->shopRows()[self::SELLERS_SHOP];
        $this->assertSame([1, 'custom', 'Back soon'], [(int) $own['vacation_status'], $own['vacation_duration_type'], $own['vacation_note']]);
        $this->assertNoShopChangedExcept(shopId: self::SELLERS_SHOP, before: $before);

        $this->post('/vendor/shop/add-vacation', ['id' => self::SELLERS_SHOP, 'vacation_duration_type' => 'until_change'])->assertRedirect();

        $this->assertSame(0, (int) $this->shopRows()[self::SELLERS_SHOP]['vacation_status']);
        $this->assertNoShopChangedExcept(shopId: self::SELLERS_SHOP, before: $before);
    }

    public function test_a_seller_shows_their_own_edit_form(): void
    {
        $response = $this->signInAs(self::SELLER)->get('/vendor/shop/update/' . self::SELLERS_SHOP);

        $response->assertOk();
        $response->assertSee('Alpha Pharmacy');
        $response->assertSee('Alpha Street 1, Jeddah');
    }

    /** @return array<int, array<string, mixed>> every shop by id, the columns these pages may write, to prove what a request did and did not touch */
    private function shopRows(): array
    {
        return DB::table('shops')->orderBy('id')->get()->keyBy('id')
            ->map(static fn (object $row): array => array_intersect_key((array) $row, array_flip(self::SHOP_FIELDS)))
            ->all();
    }

    /** @param array<int, array<string, mixed>> $before */
    private function assertNoShopChangedExcept(int $shopId, array $before): void
    {
        $after = $this->shopRows();
        unset($before[$shopId], $after[$shopId]);

        $this->assertSame($before, $after, 'a request from a seller changed a shop that is not theirs');
    }

    /** @return array<string, string> a valid shop form: the vendor panel always posts these four */
    private function shopForm(string $name): array
    {
        return ['name' => $name, 'company_phone' => '0559998888', 'country_code' => '+966', 'address' => 'New Address 9, Riyadh'];
    }

    /** @return array<string, string|int> the vacation form with the switch on */
    private function vacationForm(int $shopId): array
    {
        return [
            'id' => $shopId, 'vacation_status' => 1, 'vacation_duration_type' => 'custom',
            'vacation_start_date' => '2030-01-10 00:00:00', 'vacation_end_date' => '2030-01-20 00:00:00', 'vacation_note' => 'Back soon',
        ];
    }
}
