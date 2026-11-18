<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\TaxModule\app\Models\SystemTaxSetup;

/** The tax module as an order is placed under it: its setup, its rates and the rows an order leaves in `order_taxes`. */
trait BuildsTaxModuleWorld
{
    protected const VAT = 1;

    protected const MUNICIPALITY_FEE = 2;

    protected const RETIRED_LEVY = 3;

    /** Three rates of the tax table: a VAT of 15, a fee of 5 and a levy that is switched off. */
    protected function seedTheTaxRates(): void
    {
        DB::table('taxes')->insert([
            ['id' => self::VAT, 'name' => 'VAT', 'tax_rate' => 15, 'is_active' => 1, 'is_default' => 1],
            ['id' => self::MUNICIPALITY_FEE, 'name' => 'Municipality fee', 'tax_rate' => 5, 'is_active' => 1, 'is_default' => 1],
            ['id' => self::RETIRED_LEVY, 'name' => 'Retired levy', 'tax_rate' => 10, 'is_active' => 0, 'is_default' => 1],
        ]);
    }

    /** The tables the tax module reads and writes at placement; `taxes` and `taxables` come with the order pipeline tables. */
    protected function createTaxModuleTables(): void
    {
        if (!Schema::hasTable('system_tax_setups')) {
            Schema::create('system_tax_setups', function (Blueprint $table): void {
                $table->id();
                $table->string('tax_type', 100)->default('order_wise');
                $table->string('country_code', 20)->nullable()->index();
                $table->string('tax_payer', 20)->nullable()->default('vendor');
                $table->text('tax_ids')->nullable();
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->boolean('is_included')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('tax_additional_setups')) {
            Schema::create('tax_additional_setups', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->index();
                $table->unsignedBigInteger('system_tax_setup_id')->nullable();
                $table->text('tax_ids')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('order_taxes')) {
            Schema::create('order_taxes', function (Blueprint $table): void {
                $table->id();
                $table->string('tax_name');
                $table->string('tax_type');
                $table->string('tax_on');
                $table->double('tax_rate', 23, 8)->default(0);
                $table->double('tax_amount', 23, 8)->default(0);
                $table->double('before_tax_amount', 23, 8)->default(0);
                $table->double('after_tax_amount', 23, 8)->default(0);
                $table->string('tax_payer')->nullable();
                $table->integer('order_id')->nullable();
                $table->string('order_type')->nullable();
                $table->integer('quantity')->default(1)->nullable();
                $table->integer('tax_id');
                $table->integer('taxable_id')->nullable();
                $table->string('taxable_type')->nullable();
                $table->integer('seller_id')->nullable();
                $table->string('seller_type')->nullable();
                $table->integer('system_tax_setup_id');
                $table->string('tax_for', 30)->default('tax_for_orders')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * The setup in force: one active default row. The platform keeps its setup with no country (the tax module is a single-country module on `main`); a row that names a
     * country is what a storefront with a tax of its own would read.
     *
     * @param array<int> $taxIds
     */
    protected function openTheTaxSetup(string $type = 'order_wise', array $taxIds = [self::VAT, self::MUNICIPALITY_FEE], bool $included = false, ?string $country = null): SystemTaxSetup
    {
        $setup = SystemTaxSetup::create([
            'tax_type' => $type,
            'country_code' => $country,
            'tax_payer' => 'vendor',
            'tax_ids' => $taxIds,
            'is_default' => 1,
            'is_active' => 1,
            'is_included' => $included ? 1 : 0,
        ]);
        Cache::flush();

        return $setup;
    }
}
