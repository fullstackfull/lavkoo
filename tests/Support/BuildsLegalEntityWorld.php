<?php

namespace Tests\Support;

use App\Models\LegalEntity;
use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The tables a sold order and the registry of selling companies need, the real migrations that add the company to them, and the switches turned on. */
trait BuildsLegalEntityWorld
{
    use BuildsStorefrontCatalog;

    /** @var list<string> */
    private array $legalEntityWorldTables = ['order_details', 'orders', 'products', 'legal_entities', 'business_settings'];

    protected function buildLegalEntityWorld(): void
    {
        foreach ($this->legalEntityWorldTables as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->decimal('unit_price', 16, 3)->default(0);
        });
        $this->runStorefrontCountryMigration();
        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->decimal('order_amount', 16, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
        });

        (require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php'))->up();
        $this->runLegalEntityMigrations();
        $this->turnLegalEntitiesOn();
    }

    protected function tearDownLegalEntityWorld(): void
    {
        foreach ($this->legalEntityWorldTables as $table) {
            Schema::dropIfExists($table);
        }
        StorefrontCatalog::flush();
    }

    protected function runLegalEntityMigrations(): void
    {
        foreach (['2026_11_27_100000_create_legal_entities_table.php', '2026_11_27_100100_add_legal_entity_to_orders_table.php'] as $file) {
            (require database_path('migrations/' . $file))->up();
        }
    }

    protected function turnLegalEntitiesOn(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.legal_entities.enabled' => true,
            'gcc.storefronts.legal_entities.required_to_sell' => false,
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.countries.QA.active' => true,
            'gcc.storefronts.countries.BH.active' => true,
            'gcc.storefronts.countries.OM.active' => true,
        ]);
        StorefrontCatalog::flush();
        app(LegalEntityRegistry::class)->flush();
    }

    /** @param array<string, mixed> $overrides */
    protected function registerEntity(string $country, array $overrides = []): LegalEntity
    {
        $entity = LegalEntity::create($overrides + [
            'country' => $country,
            'legal_name' => "Lavkoo {$country} Trading Co.",
            'trade_name' => "Lavkoo {$country}",
            'tax_registration_number' => "TAX-{$country}-001",
            'commercial_registration_number' => "CR-{$country}-777",
            'invoice_series_key' => "INV-{$country}",
            'address' => "1 Example Street, {$country}",
            'status' => true,
        ]);
        app(LegalEntityRegistry::class)->flush();

        return $entity;
    }

    /** An order of the given product countries, one line for each. */
    protected function orderOf(int $orderId, string ...$countries): int
    {
        DB::table('orders')->insert(['id' => $orderId, 'order_amount' => 10]);

        foreach ($countries as $index => $country) {
            $productId = $orderId * 10 + $index;
            DB::table('products')->insert(['id' => $productId, 'name' => "Product {$country}", 'unit_price' => 10, 'storefront_country' => $country]);
            DB::table('order_details')->insert(['order_id' => $orderId, 'product_id' => $productId]);
        }

        return $orderId;
    }
}
