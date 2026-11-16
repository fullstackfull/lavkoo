<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Seller;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The vendor panel's shop pages act on the signed-in seller's own shop, never on an id taken from the URL or the form.
 *
 * Vendor\ShopController loaded and wrote whichever shop the URL (update/{id}) or the form (id) named, behind the `seller` sign-in guard
 * only. Any approved seller could open a competitor's edit form, rename or re-address that shop, put it on vacation or close it, and do the
 * same to the marketplace's own shop (seller_id 0, author_type admin). The v3 seller API and Vendor\ProfileController already act on the
 * signed-in seller whatever id arrives; this pins the vendor panel's shop pages to the same rule.
 *
 * The ids are crossed on purpose: seller 2 owns shop 3 and seller 3 owns shop 2, so a fix that took the seller's id for the shop's id would
 * still land on somebody else's shop. Shop 1 is the in-house shop. Seller 4 has no shop row yet (the shop page creates it on first visit).
 */
class VendorShopOwnershipTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private const IN_HOUSE_SHOP = 1;

    private const SELLER_A = 2;

    private const SELLER_B = 3;

    private const SELLER_WITHOUT_SHOP = 4;

    private const SHOP_OF_B = 2;

    private const SHOP_OF_A = 3;

    private const NO_SUCH_SHOP = 999;

    /** The columns the shop pages may write: a request must change none of them on a shop that is not the signed-in seller's. */
    private const SHOP_FIELDS = [
        'name', 'address', 'contact', 'image', 'banner', 'temporary_close', 'setup_guide',
        'vacation_status', 'vacation_duration_type', 'vacation_start_date', 'vacation_end_date', 'vacation_note',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        $this->buildVendorShopTables();
        $this->seedSellersAndShops();

        View::getFinder()->prependLocation(base_path('tests/Support/panel-views'));
        $this->withoutMiddleware([
            ActivationCheckMiddleware::class, MaintenanceModeMiddleware::class, Localization::class, DetectMobile::class,
            ApplySeoRedirects::class, NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function buildVendorShopTables(): void
    {
        Schema::create('sellers', static function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('image')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('approved');
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table(table: 'shops', callback: static function (Blueprint $table): void {
            $table->string('slug')->nullable();
            $table->string('address')->nullable();
            $table->string('contact')->nullable();
            $table->string('image')->nullable();
            $table->string('image_storage_type')->nullable();
            $table->string('banner')->nullable();
            $table->string('banner_storage_type')->nullable();
            $table->string('bottom_banner')->nullable();
            $table->string('bottom_banner_storage_type')->nullable();
            $table->string('offer_banner')->nullable();
            $table->string('offer_banner_storage_type')->nullable();
            $table->tinyInteger('vacation_status')->default(0);
            $table->tinyInteger('temporary_close')->default(0);
            $table->string('vacation_duration_type')->nullable();
            $table->dateTime('vacation_start_date')->nullable();
            $table->dateTime('vacation_end_date')->nullable();
            $table->string('vacation_note')->nullable();
            $table->text('setup_guide')->nullable();
            $table->text('setup_guide_app')->nullable();
        });
    }

    private function seedSellersAndShops(): void
    {
        foreach ([self::SELLER_A => 'Alpha', self::SELLER_B => 'Bravo', self::SELLER_WITHOUT_SHOP => 'Charlie'] as $sellerId => $firstName) {
            DB::table('sellers')->insert([
                'id' => $sellerId, 'f_name' => $firstName, 'phone' => '05000000' . $sellerId, 'email' => strtolower($firstName) . '@example.test',
                'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('shops')->where('id', self::IN_HOUSE_SHOP)->update([
            'seller_id' => 0, 'name' => 'Marketplace In-house', 'address' => 'Marketplace HQ, Riyadh', 'contact' => '0111111111', 'image' => 'house.png',
        ]);
        $this->insertVendorShop(shopId: self::SHOP_OF_A, sellerId: self::SELLER_A, name: 'Alpha Pharmacy', address: 'Alpha Street 1, Jeddah', contact: '0552000001');
        $this->insertVendorShop(shopId: self::SHOP_OF_B, sellerId: self::SELLER_B, name: 'Bravo Pharmacy', address: 'Bravo Street 2, Dammam', contact: '0553000002');
    }

    private function insertVendorShop(int $shopId, int $sellerId, string $name, string $address, string $contact): void
    {
        DB::table('shops')->insert([
            'id' => $shopId, 'seller_id' => $sellerId, 'author_type' => 'vendor', 'name' => $name, 'slug' => 'shop-' . $shopId, 'address' => $address,
            'contact' => $contact, 'image' => 'def.png', 'banner' => 'def.png', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function signInAs(int $sellerId): static
    {
        return $this->actingAs(Seller::findOrFail($sellerId), 'seller');
    }

    /** @return array<int, array<string, mixed>> every shop by id, the columns these pages may write, to prove what a request did and did not touch */
    private function shopRows(): array
    {
        return DB::table('shops')->orderBy('id')->get()->keyBy('id')
            ->map(static fn (object $row): array => array_intersect_key((array) $row, array_flip(self::SHOP_FIELDS)))
            ->all();
    }

    private function assertNoShopChangedExcept(int $shopId, array $before): void
    {
        $after = $this->shopRows();
        unset($before[$shopId], $after[$shopId]);

        $this->assertSame($before, $after, 'a request from a seller changed a shop that is not theirs');
    }

    /** @return array<string, string> a valid shop form: the vendor panel always posts these four */
    private function shopForm(string $name = 'Renamed Pharmacy'): array
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

    /** @return list<string> the kinds of toast (success, error...) the last response asked the next page to show */
    private function toastTypes(): array
    {
        return array_column(session('laravel-toaster-magic::messages', []), 'type');
    }

    public function test_a_seller_cannot_close_another_shop_or_the_in_house_shop(): void
    {
        $this->signInAs(self::SELLER_A);

        foreach ([self::SHOP_OF_B, self::IN_HOUSE_SHOP] as $targetShopId) {
            $before = $this->shopRows();

            $this->post('/vendor/shop/close-shop-temporary', ['id' => $targetShopId, 'status' => 0]);

            $this->assertSame(0, (int) $this->shopRows()[$targetShopId]['temporary_close'], "shop {$targetShopId} is now closed for customers");
            $this->assertNoShopChangedExcept(shopId: self::SHOP_OF_A, before: $before);
        }
    }

    public function test_a_seller_cannot_put_another_shop_or_the_in_house_shop_on_vacation(): void
    {
        $this->signInAs(self::SELLER_A);

        foreach ([self::SHOP_OF_B, self::IN_HOUSE_SHOP] as $targetShopId) {
            $before = $this->shopRows();

            $this->post('/vendor/shop/add-vacation', $this->vacationForm($targetShopId));

            $this->assertSame(0, (int) $this->shopRows()[$targetShopId]['vacation_status'], "shop {$targetShopId} is now on vacation");
            $this->assertNoShopChangedExcept(shopId: self::SHOP_OF_A, before: $before);
        }
    }

    public function test_a_seller_cannot_rewrite_another_shop_or_the_in_house_shop(): void
    {
        $this->signInAs(self::SELLER_A);

        foreach ([self::SHOP_OF_B, self::IN_HOUSE_SHOP] as $targetShopId) {
            $before = $this->shopRows();

            $this->post('/vendor/shop/update/' . $targetShopId, $this->shopForm(name: 'HIJACKED'));

            $this->assertNotSame('HIJACKED', $this->shopRows()[$targetShopId]['name'], "shop {$targetShopId} was renamed by another seller");
            $this->assertSame($before[$targetShopId], $this->shopRows()[$targetShopId]);
            $this->assertNoShopChangedExcept(shopId: self::SHOP_OF_A, before: $before);
        }
    }

    public function test_a_seller_is_not_shown_another_shops_edit_form(): void
    {
        $this->signInAs(self::SELLER_A);

        foreach ([self::SHOP_OF_B, self::IN_HOUSE_SHOP] as $targetShopId) {
            $response = $this->get('/vendor/shop/update/' . $targetShopId);

            $response->assertOk();
            $response->assertDontSee('Bravo Pharmacy');
            $response->assertDontSee('Bravo Street 2, Dammam');
            $response->assertDontSee('0553000002');
            $response->assertDontSee('Marketplace In-house');
            $response->assertDontSee('Marketplace HQ, Riyadh');
            $response->assertSee('Alpha Pharmacy');
        }
    }

    public function test_a_request_naming_any_other_id_acts_on_the_callers_own_shop(): void
    {
        $this->signInAs(self::SELLER_A);

        foreach ([self::SHOP_OF_B, self::IN_HOUSE_SHOP, self::NO_SUCH_SHOP, null] as $namedShopId) {
            $before = $this->shopRows();

            $this->post('/vendor/shop/close-shop-temporary', ['id' => $namedShopId, 'status' => 0])->assertRedirect();
            $this->assertSame(1, (int) $this->shopRows()[self::SHOP_OF_A]['temporary_close'], 'the caller\'s own shop is the one that closes');
            $this->assertNoShopChangedExcept(shopId: self::SHOP_OF_A, before: $before);

            $this->post('/vendor/shop/close-shop-temporary', ['id' => $namedShopId, 'status' => 1])->assertRedirect();
            $this->assertSame(0, (int) $this->shopRows()[self::SHOP_OF_A]['temporary_close']);
            $this->assertNoShopChangedExcept(shopId: self::SHOP_OF_A, before: $before);

            $this->post('/vendor/shop/add-vacation', ['id' => $namedShopId] + $this->vacationForm(self::SHOP_OF_A))->assertRedirect();
            $this->assertSame(1, (int) $this->shopRows()[self::SHOP_OF_A]['vacation_status']);
            $this->assertNoShopChangedExcept(shopId: self::SHOP_OF_A, before: $before);

            $this->post('/vendor/shop/update/' . ($namedShopId ?? 'none'), $this->shopForm(name: 'Alpha Renamed'))->assertRedirect(route('vendor.shop.index'));
            $this->assertSame('Alpha Renamed', $this->shopRows()[self::SHOP_OF_A]['name']);
            $this->assertNoShopChangedExcept(shopId: self::SHOP_OF_A, before: $before);

            DB::table('shops')->where('id', self::SHOP_OF_A)->update(['name' => 'Alpha Pharmacy', 'vacation_status' => 0]);
        }
    }

    public function test_a_seller_without_a_shop_cannot_reach_anyone_elses(): void
    {
        $this->signInAs(self::SELLER_WITHOUT_SHOP);
        $before = $this->shopRows();

        $this->post('/vendor/shop/close-shop-temporary', ['id' => self::SHOP_OF_B, 'status' => 0])->assertNotFound();
        $this->post('/vendor/shop/add-vacation', $this->vacationForm(self::SHOP_OF_B))->assertNotFound();
        $this->post('/vendor/shop/update/' . self::SHOP_OF_B, $this->shopForm(name: 'HIJACKED'))->assertNotFound();
        $this->get('/vendor/shop/update/' . self::SHOP_OF_B)->assertNotFound();

        $this->assertSame($before, $this->shopRows(), 'a seller without a shop changed or read a shop');
    }

    public function test_a_seller_updates_their_own_shop(): void
    {
        $this->signInAs(self::SELLER_A);
        $before = $this->shopRows();

        $this->post('/vendor/shop/update/' . self::SHOP_OF_A, $this->shopForm(name: 'Alpha Renamed'))->assertRedirect(route('vendor.shop.index'));

        $own = $this->shopRows()[self::SHOP_OF_A];
        $this->assertSame(['Alpha Renamed', 'New Address 9, Riyadh', '0559998888'], [$own['name'], $own['address'], $own['contact']]);
        $this->assertSame(['def.png', 'def.png'], [$own['image'], $own['banner']], 'a form without files keeps the pictures');
        $this->assertNotEmpty($this->toastTypes(), 'the owner is told the update worked');
        $this->assertNoShopChangedExcept(shopId: self::SHOP_OF_A, before: $before);
    }

    public function test_a_seller_puts_their_own_shop_on_vacation_and_back(): void
    {
        $this->signInAs(self::SELLER_A);
        $before = $this->shopRows();

        $this->post('/vendor/shop/add-vacation', $this->vacationForm(self::SHOP_OF_A))->assertRedirect();

        $own = $this->shopRows()[self::SHOP_OF_A];
        $this->assertSame([1, 'custom', 'Back soon'], [(int) $own['vacation_status'], $own['vacation_duration_type'], $own['vacation_note']]);
        $this->assertSame(['2030-01-10', '2030-01-20'], [substr((string) $own['vacation_start_date'], 0, 10), substr((string) $own['vacation_end_date'], 0, 10)]);
        $this->assertNoShopChangedExcept(shopId: self::SHOP_OF_A, before: $before);

        $this->post('/vendor/shop/add-vacation', ['id' => self::SHOP_OF_A, 'vacation_duration_type' => 'until_change'])->assertRedirect();

        $this->assertSame(0, (int) $this->shopRows()[self::SHOP_OF_A]['vacation_status']);
        $this->assertNoShopChangedExcept(shopId: self::SHOP_OF_A, before: $before);
    }

    public function test_a_seller_closes_and_reopens_their_own_shop_temporarily(): void
    {
        $this->signInAs(self::SELLER_A);
        $before = $this->shopRows();

        $this->post('/vendor/shop/close-shop-temporary', ['id' => self::SHOP_OF_A, 'status' => 0])->assertRedirect();
        $this->assertSame(1, (int) $this->shopRows()[self::SHOP_OF_A]['temporary_close'], 'the switch off closes the shop');
        $this->assertNoShopChangedExcept(shopId: self::SHOP_OF_A, before: $before);

        $this->post('/vendor/shop/close-shop-temporary', ['id' => self::SHOP_OF_A, 'status' => 1])->assertRedirect();
        $this->assertSame(0, (int) $this->shopRows()[self::SHOP_OF_A]['temporary_close'], 'the switch on opens it again');
        $this->assertNoShopChangedExcept(shopId: self::SHOP_OF_A, before: $before);
    }

    public function test_every_seller_manages_only_their_own_shop_through_the_same_pages(): void
    {
        foreach ([self::SELLER_B => self::SHOP_OF_B, self::SELLER_A => self::SHOP_OF_A] as $sellerId => $ownShopId) {
            $before = $this->shopRows();
            $this->signInAs($sellerId);

            $this->post('/vendor/shop/close-shop-temporary', ['id' => $ownShopId, 'status' => 0])->assertRedirect();

            $this->assertSame(1, (int) $this->shopRows()[$ownShopId]['temporary_close']);
            $this->assertNoShopChangedExcept(shopId: $ownShopId, before: $before);
        }
    }

    public function test_the_shop_pages_show_and_post_to_the_signed_in_sellers_own_shop(): void
    {
        $this->signInAs(self::SELLER_A);

        $edit = $this->get('/vendor/shop/update/' . self::SHOP_OF_A);
        $edit->assertOk();
        $edit->assertSee('Alpha Pharmacy');
        $edit->assertSee('Alpha Street 1, Jeddah');
        $edit->assertSee('0552000001');
        $edit->assertSee(route('vendor.shop.update', [self::SHOP_OF_A]));

        $index = $this->get(route('vendor.shop.index'));
        $index->assertOk();
        $index->assertSee('name="id" value="' . self::SHOP_OF_A . '"', escape: false);
        $index->assertSee(route('vendor.shop.update', [self::SHOP_OF_A]));
        $index->assertDontSee('Bravo Pharmacy');
    }

    public function test_an_unapproved_seller_still_cannot_use_the_shop_pages(): void
    {
        DB::table('sellers')->where('id', self::SELLER_A)->update(['status' => 'suspended']);
        $before = $this->shopRows();
        $this->signInAs(self::SELLER_A);

        $this->post('/vendor/shop/close-shop-temporary', ['id' => self::SHOP_OF_A, 'status' => 0])->assertRedirect(route('vendor.auth.login'));
        $this->post('/vendor/shop/update/' . self::SHOP_OF_A, $this->shopForm())->assertRedirect(route('vendor.auth.login'));

        $this->assertSame($before, $this->shopRows());
    }
}
