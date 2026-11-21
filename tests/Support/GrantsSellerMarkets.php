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

    /** The columns the stock screens read, and the product's own market from the real catalogue migration. */
    protected function giveProductsTheirStockColumns(bool $withTheMarketColumn = true): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('added_by')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->integer('current_stock')->default(0);
            $table->decimal('unit_price', 24, 3)->default(0);
            $table->unsignedTinyInteger('status')->default(1);
        });

        if ($withTheMarketColumn) {
            (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        }
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

    protected function dropTheMarketGrantTable(): void
    {
        Schema::dropIfExists(SellerMarketScope::TABLE);
        Schema::dropIfExists('vendor_ledger_entries');
    }
}
