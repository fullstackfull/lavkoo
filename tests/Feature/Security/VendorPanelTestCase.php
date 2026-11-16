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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Two sellers and the in-house shop behind the real vendor panel routes (the `seller` and `seller_staff_access` middleware stay on), on an
 * INFERRED minimal schema: the installation dump that owns the store tables is not in the repository, so only the tables and columns the
 * handlers under test read or write are built. BuildsFulfillmentSchema refuses to drop anything unless APP_ENV is `testing` and the database
 * name says "test".
 *
 * The ids are crossed on purpose. Seller 1 is the caller and its id is also the user_id the in-house shop's rows carry (added_by 'admin'), so a
 * lookup that scopes on the id alone reaches the in-house shop. Seller 1 owns shop 12 and seller 2 owns shop 11, so a lookup that takes the
 * seller's id for the shop's id lands on somebody else's shop.
 *
 * translate() writes a placeholder for a key it does not know into the tracked language files; they are put back after every test.
 */
abstract class VendorPanelTestCase extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    protected const SELLER = 1;
    protected const OTHER_SELLER = 2;
    protected const IN_HOUSE_SHOP = 1;
    protected const OTHER_SELLERS_SHOP = 11;
    protected const SELLERS_SHOP = 12;

    protected const OWN_PRODUCT = 10;
    protected const OTHER_SELLERS_PRODUCT = 20;
    protected const IN_HOUSE_PRODUCT = 30;

    protected const OWN_PASSWORD = 'Owner-Original-1!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Storage::fake('public');
        $this->buildCoreSchema();
        $this->buildVendorPanelTables();
        $this->seedSellersShopsAndProducts();

        View::getFinder()->prependLocation(base_path('tests/Support/panel-views'));
        View::getFinder()->prependLocation(base_path('tests/Support/vendor-panel-views'));
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

    private function buildVendorPanelTables(): void
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
            $table->tinyInteger('pos_status')->default(1);
            $table->integer('stock_limit')->default(0);
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

        Schema::table(table: 'products', callback: static function (Blueprint $table): void {
            $table->string('preview_file')->nullable();
        });

        Schema::table(table: 'reviews', callback: static function (Blueprint $table): void {
            $table->unsignedBigInteger('order_id')->nullable();
            $table->text('comment')->nullable();
            $table->text('attachment')->nullable();
            $table->integer('rating')->default(5);
            $table->boolean('is_saved')->default(false);
        });

        Schema::create('review_replies', static function (Blueprint $table): void {
            $table->id();
            $table->integer('review_id');
            $table->integer('added_by_id')->nullable();
            $table->string('added_by');
            $table->text('reply_text')->nullable();
            $table->timestamps();
        });

        Schema::create('digital_product_variations', static function (Blueprint $table): void {
            $table->id();
            $table->integer('product_id');
            $table->string('variant_key')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('price', 24, 8)->nullable();
            $table->string('file')->nullable();
            $table->timestamps();
        });

        foreach (['wishlists', 'flash_deal_products', 'deal_of_the_days'] as $tableName) {
            Schema::create($tableName, static function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('product_id');
                $table->timestamps();
            });
        }
    }

    private function seedSellersShopsAndProducts(): void
    {
        foreach ([self::SELLER => 'Alpha', self::OTHER_SELLER => 'Bravo'] as $sellerId => $firstName) {
            DB::table('sellers')->insert([
                'id' => $sellerId, 'f_name' => $firstName, 'l_name' => 'Pharmacy', 'phone' => '05000000' . $sellerId,
                'email' => strtolower($firstName) . '@example.test', 'password' => bcrypt(self::OWN_PASSWORD), 'status' => 'approved',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('shops')->where('id', self::IN_HOUSE_SHOP)->update([
            'seller_id' => 0, 'name' => 'Marketplace In-house', 'address' => 'Marketplace HQ, Riyadh', 'contact' => '0111111111',
        ]);
        $this->insertShop(shopId: self::SELLERS_SHOP, sellerId: self::SELLER, name: 'Alpha Pharmacy', address: 'Alpha Street 1, Jeddah', contact: '0552000001');
        $this->insertShop(shopId: self::OTHER_SELLERS_SHOP, sellerId: self::OTHER_SELLER, name: 'Bravo Pharmacy', address: 'Bravo Street 2, Dammam', contact: '0553000002');

        $this->insertProduct(id: self::OWN_PRODUCT, userId: self::SELLER, addedBy: 'seller', name: 'Own product');
        $this->insertProduct(id: self::OTHER_SELLERS_PRODUCT, userId: self::OTHER_SELLER, addedBy: 'seller', name: 'Other sellers product');
        $this->insertProduct(id: self::IN_HOUSE_PRODUCT, userId: self::SELLER, addedBy: 'admin', name: 'In-house product');
    }

    protected function insertShop(int $shopId, int $sellerId, string $name, string $address, string $contact): void
    {
        DB::table('shops')->insert([
            'id' => $shopId, 'seller_id' => $sellerId, 'author_type' => 'vendor', 'name' => $name, 'slug' => 'shop-' . $shopId, 'address' => $address,
            'contact' => $contact, 'image' => 'def.png', 'banner' => 'def.png', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function insertProduct(int $id, int $userId, string $addedBy, string $name, array $overrides = []): void
    {
        DB::table('products')->insert($overrides + [
            'id' => $id, 'user_id' => $userId, 'added_by' => $addedBy, 'name' => $name, 'slug' => 'product-' . $id, 'code' => 'CODE' . $id,
            'images' => '[]', 'thumbnail' => 'thumbnail.png', 'category_ids' => '[]', 'colors' => '[]', 'variation' => '[]', 'choice_options' => '[]',
            'attributes' => '[]', 'unit_price' => 100, 'current_stock' => 5, 'status' => 1, 'request_status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function signInAs(int $sellerId): static
    {
        return $this->actingAs(Seller::findOrFail($sellerId), 'seller');
    }

    /** @return list<string> the kinds of toast (success, error...) the last response asked the next page to show */
    protected function toastTypes(): array
    {
        return array_column(session('laravel-toaster-magic::messages', []), 'type');
    }
}
