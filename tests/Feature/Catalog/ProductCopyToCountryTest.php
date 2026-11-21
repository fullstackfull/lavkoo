<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Services\Catalog\ProductCopyRefused;
use App\Services\Catalog\ProductCopyToCountry;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\TestCase;

/**
 * B-07: the same product goes into a second country deliberately — an explicit target that is never the source's own country, the code kept
 * only when it is free there, an audit line, image files of the copy's own, and no stock or price carried across the border.
 *
 * The plan's rule is that catalogue products stay independent per country, so what is proved here is as much what a copy does NOT do.
 */
class ProductCopyToCountryTest extends TestCase
{
    use BuildsCodeScopeCatalog;

    private ProductCopyToCountry $copier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCodeScopeProducts();
        $this->buildCodeScopeRelationTables();
        $this->buildAuditLogTable();
        Storage::fake('public');
        config(['filesystems.disks.default' => 'public']);
        $this->copier = app(ProductCopyToCountry::class);
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    // ---- dormant until the markets are on ----------------------------------------------------------------------------------------

    public function test_the_action_is_not_offered_while_the_switch_is_off(): void
    {
        $source = $this->sourceProduct();

        $this->assertFalse($this->copier->isAvailable());
        $this->assertSame([], $this->copier->targetsFor($source));
    }

    public function test_a_copy_asked_for_while_the_switch_is_off_is_refused(): void
    {
        $source = $this->sourceProduct();

        $this->expectException(ProductCopyRefused::class);
        $this->copier->copy(source: $source, targetCountry: 'KW');
    }

    // ---- an explicit target, never the source's own country ----------------------------------------------------------------------

    public function test_the_targets_are_every_configured_country_except_the_products_own(): void
    {
        $this->trackCountries();
        $source = $this->sourceProduct(country: 'SA');

        $targets = $this->copier->targetsFor($source);

        $this->assertNotContains('SA', $targets);
        $this->assertContains('KW', $targets);
        $this->assertSame(count(config('gcc.storefronts.countries')) - 1, count($targets));
    }

    public function test_a_copy_into_the_source_country_is_refused(): void
    {
        $this->trackCountries();
        $source = $this->sourceProduct(country: 'SA');

        try {
            $this->copier->copy(source: $source, targetCountry: 'SA');
            $this->fail('a copy into the source country was made');
        } catch (ProductCopyRefused $refused) {
            $this->assertStringContainsString('SA', $refused->getMessage());
        }

        $this->assertSame(1, Product::query()->count(), 'nothing may be created by a refused copy');
    }

    public function test_a_copy_with_no_target_named_is_refused(): void
    {
        $this->trackCountries();
        $source = $this->sourceProduct();

        foreach ([null, '', 'ZZ', 'kw', 123] as $target) {
            try {
                $this->copier->copy(source: $source, targetCountry: $target);
                $this->fail('a copy was made for the target ' . var_export($target, true));
            } catch (ProductCopyRefused) {
                $this->assertSame(1, Product::query()->count());
            }
        }
    }

    // ---- the code is kept only when it is free there -----------------------------------------------------------------------------

    public function test_the_code_and_the_barcode_are_kept_when_the_target_country_has_neither(): void
    {
        $this->trackCountries();
        $source = $this->sourceProduct(code: 'SKU100', barcode: '6291234567890');

        $copy = $this->copier->copy(source: $source, targetCountry: 'KW');

        $this->assertSame('SKU100', $copy->code);
        $this->assertSame('6291234567890', $copy->barcode);
        $this->assertSame('KW', $copy->storefront_country);
    }

    public function test_a_code_the_target_country_already_holds_is_left_for_the_admin_to_type(): void
    {
        $this->trackCountries();
        $source = $this->sourceProduct(code: 'SKU100');
        $this->insertCodedProduct(code: 'SKU100', country: 'KW');

        $copy = $this->copier->copy(source: $source, targetCountry: 'KW');

        $this->assertNull($copy->code, 'the code is kept only when it is free in the target country');
    }

    public function test_a_barcode_the_target_country_already_holds_is_left_for_the_admin_to_type(): void
    {
        $this->trackCountries();
        $source = $this->sourceProduct(code: 'SKU100', barcode: '6291234567890');
        $this->insertCodedProduct(code: 'OTHER', country: 'KW', barcode: '6291234567890');

        $copy = $this->copier->copy(source: $source, targetCountry: 'KW');

        $this->assertSame('SKU100', $copy->code);
        $this->assertNull($copy->barcode);
    }

    public function test_the_copy_does_not_make_the_target_country_refuse_its_own_catalogue(): void
    {
        $this->trackCountries();
        $source = $this->sourceProduct(code: 'SKU100');

        $copy = $this->copier->copy(source: $source, targetCountry: 'KW');

        $this->assertNotSame($source->slug, $copy->slug, 'a slug names one product');
        $this->assertSame(2, Product::query()->where('code', 'SKU100')->count());
    }

    // ---- no stock and no price cross the border ----------------------------------------------------------------------------------

    public function test_the_copy_carries_no_stock_and_no_price(): void
    {
        $this->trackCountries();
        $source = $this->sourceProduct(extra: ['current_stock' => 42, 'unit_price' => 199, 'purchase_price' => 120, 'discount' => 15]);

        $copy = $this->copier->copy(source: $source, targetCountry: 'KW');

        $this->assertSame(0, (int) $copy->current_stock);
        $this->assertSame(0.0, (float) $copy->unit_price);
        $this->assertSame(0.0, (float) $copy->purchase_price);
        $this->assertSame(0.0, (float) $copy->discount);
    }

    public function test_the_copy_is_not_published_so_a_product_with_no_price_cannot_be_sold(): void
    {
        $this->trackCountries();
        $source = $this->sourceProduct(extra: ['status' => 1, 'featured_status' => 1]);

        $copy = $this->copier->copy(source: $source, targetCountry: 'KW');

        $this->assertSame(0, (int) $copy->status);
        $this->assertSame(0, (int) $copy->featured_status);
    }

    public function test_every_variant_of_the_copy_keeps_its_shape_and_its_sku_but_no_quantity_and_no_price(): void
    {
        $this->trackCountries();
        $variation = json_encode([['type' => 'Red', 'price' => 199, 'sku' => 'V-RED', 'qty' => 12]]);
        $source = $this->sourceProduct(extra: ['variation' => $variation]);

        $copy = $this->copier->copy(source: $source, targetCountry: 'KW');

        $this->assertSame(
            [['type' => 'Red', 'price' => 0, 'sku' => 'V-RED', 'qty' => 0]],
            array_map(static fn (array $variant): array => ['type' => $variant['type'], 'price' => $variant['price'], 'sku' => $variant['sku'], 'qty' => $variant['qty']], json_decode($copy->getAttributes()['variation'], true)),
        );
    }

    public function test_editing_the_source_after_the_copy_does_not_touch_the_copy(): void
    {
        $this->trackCountries();
        $source = $this->sourceProduct(extra: ['current_stock' => 10, 'unit_price' => 50]);
        $copy = $this->copier->copy(source: $source, targetCountry: 'KW');

        DB::table('products')->where('id', $source->id)->update(['current_stock' => 999, 'unit_price' => 777]);

        $copy->refresh();
        $this->assertSame(0, (int) $copy->current_stock);
        $this->assertSame(0.0, (float) $copy->unit_price);
    }

    // ---- the copy's image files are its own --------------------------------------------------------------------------------------

    public function test_the_copy_does_not_share_an_image_file_with_its_source(): void
    {
        $this->trackCountries();
        Storage::disk('public')->put('product/thumbnail/source-thumb.webp', 'the bytes');
        Storage::disk('public')->put('product/gallery-one.webp', 'the gallery bytes');
        $source = $this->sourceProduct(extra: [
            'thumbnail' => 'source-thumb.webp',
            'images' => json_encode([['image_name' => 'gallery-one.webp', 'storage' => 'public']]),
        ]);

        $copy = $this->copier->copy(source: $source, targetCountry: 'KW');

        $this->assertNotSame('source-thumb.webp', $copy->getAttributes()['thumbnail']);
        $this->assertTrue(Storage::disk('public')->exists('product/thumbnail/' . $copy->getAttributes()['thumbnail']));
        $this->assertSame('the bytes', Storage::disk('public')->get('product/thumbnail/' . $copy->getAttributes()['thumbnail']));

        $copiedGallery = json_decode($copy->getAttributes()['images'], true);
        $this->assertNotSame('gallery-one.webp', $copiedGallery[0]['image_name']);
        $this->assertSame('the gallery bytes', Storage::disk('public')->get('product/' . $copiedGallery[0]['image_name']));
    }

    public function test_deleting_the_sources_image_leaves_the_copys_image_alone(): void
    {
        $this->trackCountries();
        Storage::disk('public')->put('product/thumbnail/source-thumb.webp', 'the bytes');
        $source = $this->sourceProduct(extra: ['thumbnail' => 'source-thumb.webp']);
        $copy = $this->copier->copy(source: $source, targetCountry: 'KW');

        Storage::disk('public')->delete('product/thumbnail/source-thumb.webp');

        $this->assertTrue(Storage::disk('public')->exists('product/thumbnail/' . $copy->getAttributes()['thumbnail']));
    }

    public function test_the_disk_a_file_was_written_to_is_recorded_for_the_copy_as_well(): void
    {
        $this->trackCountries();
        Storage::disk('public')->put('product/thumbnail/source-thumb.webp', 'the bytes');
        $source = $this->sourceProduct(extra: ['thumbnail' => 'source-thumb.webp', 'thumbnail_storage_type' => 'public']);

        $copy = $this->copier->copy(source: $source, targetCountry: 'KW');

        $this->assertSame('public', $copy->getAttributes()['thumbnail_storage_type']);
    }

    public function test_a_source_image_that_cannot_be_read_is_recorded_as_not_copied_and_never_shared(): void
    {
        $this->trackCountries();
        $source = $this->sourceProduct(extra: ['thumbnail' => 'gone.webp']);

        $copy = $this->copier->copy(source: $source, targetCountry: 'KW');

        $this->assertNull($copy->getAttributes()['thumbnail'], 'a copy never points at a file it could not copy');
        $this->assertContains('thumbnail', $this->auditContext()['images_not_copied']);
    }

    // ---- the copy is audited -----------------------------------------------------------------------------------------------------

    public function test_a_copy_writes_an_audit_line_naming_both_countries_and_the_source(): void
    {
        $this->trackCountries();
        $source = $this->sourceProduct(code: 'SKU100', country: 'SA');

        $copy = $this->copier->copy(source: $source, targetCountry: 'KW');

        $line = DB::table('audit_logs')->where('action', 'catalog.product.copied_to_country')->first();
        $this->assertNotNull($line);
        $this->assertSame((string) $copy->id, (string) $line->subject_id);

        $context = json_decode($line->context, true);
        $this->assertSame((int) $source->id, $context['source_product_id']);
        $this->assertSame('SA', $context['source_country']);
        $this->assertSame('KW', $context['target_country']);
        $this->assertTrue($context['code_kept']);
        $this->assertFalse($context['stock_and_price_carried']);
    }

    public function test_the_audit_line_says_when_the_code_could_not_be_kept(): void
    {
        $this->trackCountries();
        $source = $this->sourceProduct(code: 'SKU100');
        $this->insertCodedProduct(code: 'SKU100', country: 'KW');

        $this->copier->copy(source: $source, targetCountry: 'KW');

        $this->assertFalse($this->auditContext()['code_kept']);
    }

    public function test_a_copy_whose_audit_line_cannot_be_written_creates_no_product(): void
    {
        $this->trackCountries();
        $source = $this->sourceProduct();
        Schema::dropIfExists('audit_logs');

        try {
            $this->copier->copy(source: $source, targetCountry: 'KW');
            $this->fail('a copy was made with no record of it');
        } catch (\RuntimeException) {
            $this->assertSame(1, Product::query()->count(), 'a copy nobody can see a record of must not exist');
        }
    }

    // ---- helpers -----------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function auditContext(): array
    {
        $line = DB::table('audit_logs')->where('action', 'catalog.product.copied_to_country')->first();

        return json_decode($line->context, true);
    }

    private function trackCountries(): void
    {
        $this->scopeCodesByCountry();
        config(['gcc.storefronts.enabled' => true]);
        StorefrontCatalog::flush();
    }

    /** @param array<string, mixed> $extra */
    private function sourceProduct(string $code = 'SKU100', string $country = 'SA', ?string $barcode = null, array $extra = []): Product
    {
        $id = $this->insertCodedProduct(code: $code, country: $country, barcode: $barcode, extra: ['slug' => 'serum-' . strtolower($code)] + $extra);

        return Product::query()->findOrFail($id);
    }

    private function buildAuditLogTable(): void
    {
        $this->refuseToDropTablesOutsideATestDatabase();
        Schema::dropIfExists('audit_logs');
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->text('before')->nullable();
            $table->text('after')->nullable();
            $table->text('context')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }
}
