<?php

namespace Tests\Support;

use App\Services\Marketplace\SellerMarketScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The grant of markets, on top of the seller world that already signs a caller in behind the real seller API (K-09).
 *
 * The grant table is built by its own real migration, not by hand, so a test proves the shape that ships. The rows are written
 * straight into it because that is the only way a grant is ever created: no route writes one, which is the property under test.
 *
 * The products table of the seller world is the two columns the order tests needed; the stock screens read a few more, and the
 * product's own market column comes from the real catalogue migration.
 */
trait GrantsSellerMarkets
{
    /** A market no test grants, so a request for it is always a request outside the grant. */
    protected const UNGRANTED_MARKET = 'QA';

    protected function createTheMarketGrantTable(): void
    {
        Schema::dropIfExists(SellerMarketScope::TABLE);

        (require database_path('migrations/2026_12_10_100000_create_seller_market_scopes_table.php'))->up();
    }

    /** Grants a market to a shop's own account, or to one of its staff, optionally with the role that applies in that market. */
    protected function grantMarket(int $sellerId, string $market, ?int $staffId = null, ?int $roleId = null): void
    {
        DB::table(SellerMarketScope::TABLE)->insert([
            'seller_id' => $sellerId,
            'staff_id' => $staffId ?? SellerMarketScope::OWNER_STAFF_ID,
            'country' => $market,
            'seller_role_id' => $roleId,
            'created_at' => '2026-11-01 09:00:00',
            'updated_at' => '2026-11-01 09:00:00',
        ]);
    }

    /** Markets are enforced, and every market the platform knows serves a storefront, so a test is not about which one is open. */
    protected function switchMarketScopesOn(): void
    {
        config([SellerMarketScope::SWITCH => true]);
        $this->switchStorefrontsOn();
    }

    /**
     * The columns the stock screens read and the seller app's own product insert writes, and the product's market from the real
     * catalogue migration. The list is the insert of `POST v3/seller/products/add` as the controller builds it, so the real route
     * completes and the row it wrote can be read back.
     */
    protected function giveProductsTheirStockColumns(bool $withTheMarketColumn = true): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('added_by')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->integer('current_stock')->default(0);
            $table->decimal('unit_price', 24, 3)->default(0);
            $table->decimal('purchase_price', 24, 3)->default(0);
            $table->decimal('discount', 24, 3)->default(0);
            $table->decimal('shipping_cost', 24, 3)->default(0);
            $table->unsignedTinyInteger('status')->default(1);
            $table->unsignedTinyInteger('request_status')->default(1);
            $table->unsignedTinyInteger('multiply_qty')->default(0);
            $table->unsignedInteger('minimum_order_qty')->default(1);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('sub_category_id')->nullable();
            $table->unsignedBigInteger('sub_sub_category_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();

            foreach ([
                'slug', 'category_ids', 'unit', 'product_type', 'digital_product_type', 'code', 'details', 'images', 'color_image',
                'thumbnail', 'thumbnail_storage_type', 'colors', 'choice_options', 'variation', 'discount_type', 'attributes',
                'video_provider', 'video_url', 'digital_product_file_types', 'digital_product_extensions', 'preview_file',
                'preview_file_storage_type', 'digital_file_ready', 'digital_file_ready_storage_type',
            ] as $column) {
                $table->text($column)->nullable();
            }
        });

        if ($withTheMarketColumn) {
            (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        }
    }

    /** The two tables the Add Product route clears for a product it has just written, and nothing of the digital catalogue beside. */
    protected function createTheDigitalCatalogueTables(): void
    {
        foreach ([
            'digital_product_authors', 'digital_product_publishing_houses', 'product_seos', 'product_tag', 'tags', 'translations',
            'system_tax_setups', 'product_vat_taxes', 'reviews', 'vendor_payout_requests', 'stock_clearance_products', 'stock_clearance_setups',
        ] as $table) {
            Schema::dropIfExists($table);

            Schema::create($table, function (Blueprint $blueprint) {
                $blueprint->id();
                $blueprint->unsignedBigInteger('product_id');
                $blueprint->unsignedBigInteger('author_id')->nullable();
                $blueprint->unsignedBigInteger('publishing_house_id')->nullable();
                $blueprint->unsignedBigInteger('tag_id')->nullable();
                $blueprint->unsignedBigInteger('setup_id')->nullable();
                $blueprint->unsignedBigInteger('translationable_id')->nullable();
                $blueprint->string('translationable_type')->nullable();
                $blueprint->string('locale')->nullable();
                $blueprint->string('key')->nullable();
                $blueprint->text('value')->nullable();
                $blueprint->string('name')->nullable();
                $blueprint->string('slug')->nullable();
                $blueprint->text('title')->nullable();
                $blueprint->text('description')->nullable();
                $blueprint->text('keywords')->nullable();
                $blueprint->text('image')->nullable();
                $blueprint->text('index')->nullable();
                $blueprint->text('no_follow')->nullable();
                $blueprint->text('no_image_index')->nullable();
                $blueprint->text('no_archive')->nullable();
                $blueprint->text('no_snippet')->nullable();
                $blueprint->text('max_snippet')->nullable();
                $blueprint->text('max_video_preview')->nullable();
                $blueprint->text('max_image_preview')->nullable();
                $blueprint->text('max_snippet_value')->nullable();
                $blueprint->text('max_video_preview_value')->nullable();
                $blueprint->text('max_image_preview_value')->nullable();
                $blueprint->unsignedBigInteger('seller_id')->nullable();
                $blueprint->string('added_by')->nullable();
                $blueprint->decimal('discount_amount', 24, 3)->default(0);
                $blueprint->string('discount_type')->nullable();
                $blueprint->unsignedBigInteger('customer_id')->nullable();
                $blueprint->unsignedTinyInteger('rating')->default(0);
                $blueprint->decimal('amount', 24, 3)->default(0);
                $blueprint->string('status')->nullable();
                $blueprint->string('reference')->nullable();
                $blueprint->unsignedTinyInteger('is_active')->default(0);
                $blueprint->unsignedTinyInteger('is_default')->default(0);
                $blueprint->unsignedBigInteger('tax_id')->nullable();
                $blueprint->timestamps();
            });
        }
    }

    /**
     * The body the seller app's Add Product screen sends, with every list as the JSON text the controller decodes.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function addProductBody(array $overrides = []): array
    {
        return $overrides + [
            'name' => json_encode(['A product from the app']),
            'lang' => json_encode(['en']),
            'description' => json_encode(['Added from the seller app']),
            'colors' => json_encode([]),
            'color_image' => json_encode([]),
            'tags' => json_encode([]),
            'choice' => json_encode([]),
            'choice_no' => json_encode([]),
            'choice_attributes' => json_encode([]),
            'images' => json_encode(['product.webp']),
            'thumbnail' => 'product.webp',
            'slug' => 'a-product-from-the-app',
            'category_id' => 1,
            'product_type' => 'physical',
            'unit' => 'pc',
            'discount_type' => 'flat',
            'unit_price' => 10,
            'discount' => 0,
            'shipping_cost' => 0,
            'code' => 'FROMAPP01',
            'minimum_order_qty' => 1,
            'current_stock' => 5,
        ];
    }

    protected function insertSellerProduct(int $id, int $sellerId, ?string $market, int $stock, float $unitPrice): void
    {
        $market = $market === null || !Schema::hasColumn('products', 'storefront_country') ? [] : ['storefront_country' => $market];

        DB::table('products')->insert([
            'id' => $id, 'name' => 'Product ' . $id, 'added_by' => 'seller', 'user_id' => $sellerId,
            'current_stock' => $stock, 'unit_price' => $unitPrice, 'status' => 1,
            'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00',
        ] + $market);
    }

    /** The seller ledger the statement reads: the columns the statement and the balances ask for, and nothing else. */
    protected function createTheSellerLedger(): void
    {
        Schema::dropIfExists('vendor_ledger_entries');

        Schema::create('vendor_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('seller_is')->default('seller');
            $table->string('entry_type');
            $table->string('status');
            $table->decimal('credit', 24, 3)->default(0);
            $table->decimal('debit', 24, 3)->default(0);
            $table->decimal('balance_after', 24, 3)->default(0);
            $table->string('currency')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamps();
        });
    }

    protected function insertLedgerEntry(int $sellerId, string $currency, float $credit, float $debit, string $status = 'available'): void
    {
        DB::table('vendor_ledger_entries')->insert([
            'seller_id' => $sellerId, 'seller_is' => 'seller', 'entry_type' => 'order_earning', 'status' => $status,
            'credit' => $credit, 'debit' => $debit, 'balance_after' => $credit - $debit, 'currency' => $currency,
            'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00',
        ]);
    }

    /** Only the grant table, so a test can be the database that never ran its migration without losing the rest of the world. */
    protected function dropOnlyTheMarketGrantTable(): void
    {
        Schema::dropIfExists(SellerMarketScope::TABLE);
    }

    protected function dropTheMarketGrantTable(): void
    {
        Schema::dropIfExists(SellerMarketScope::TABLE);
        Schema::dropIfExists('vendor_ledger_entries');
        foreach ([
            'digital_product_authors', 'digital_product_publishing_houses', 'product_seos', 'product_tag', 'tags', 'translations',
            'system_tax_setups', 'product_vat_taxes', 'reviews', 'vendor_payout_requests', 'stock_clearance_products', 'stock_clearance_setups',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
