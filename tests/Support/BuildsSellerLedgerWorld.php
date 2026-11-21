<?php

namespace Tests\Support;

use App\Services\Marketplace\VendorLedger;
use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\OrderLegalEntitySnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tables the seller ledger and the payouts drawn on it are written to, for the tests of who a ledger row's money belongs to (GCC-13, D1-03).
 *
 * `vendor_ledger_entries` has NO migration in this repository: the live table came from the installation dump, which is not in the tree, so the table
 * here is built from the columns App\Models\VendorLedgerEntry makes fillable and App\Services\Marketplace\VendorLedger writes. It is not a copy of the
 * live schema and makes no claim to be one; it proves the logic of the code, not the schema. The company column is added by its real migration, so
 * that migration is exercised rather than imitated.
 */
trait BuildsSellerLedgerWorld
{
    /** @var list<string> */
    private array $sellerLedgerWorldTables = [
        'vendor_ledger_entries', 'vendor_payout_requests', 'order_item_commissions', 'orders', 'sellers', 'currencies', 'business_settings',
    ];

    /**
     * Tables the writers under test look for with Schema::hasTable() and read when they find one. They are dropped so that what the code
     * does here does not depend on whether another test file of the same job left one behind with columns of its own: without rules the
     * commission is the store's own legacy percentage, and without a bank-change log no payout is in a cooling period, which is the state
     * of an installation that has configured neither.
     *
     * @var list<string>
     */
    private array $sellerLedgerWorldAbsentTables = ['commission_rules', 'vendor_bank_change_logs', 'category_governance'];

    protected function buildSellerLedgerWorld(): void
    {
        foreach ([...$this->sellerLedgerWorldTables, ...$this->sellerLedgerWorldAbsentTables] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', static function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('currencies', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol', 20)->nullable();
            $table->string('code', 20)->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        DB::table('currencies')->insert(['id' => 1, 'name' => 'Saudi riyal', 'symbol' => 'SAR', 'code' => 'SAR', 'exchange_rate' => 1]);
        DB::table('business_settings')->insert(['type' => 'system_default_currency', 'value' => '1']);

        // CommissionEngine reads the seller's own legacy percentage before it falls back to the store's. Left null here, so the figure is the
        // store's setting and the real fallback path runs rather than being skipped for want of a table.
        Schema::create('sellers', static function (Blueprint $table): void {
            $table->id();
            $table->decimal('sales_commission_percentage', 12, 4)->nullable();
            $table->string('status')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('orders', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->decimal('order_amount', 24, 4)->default(0);
            $table->timestamps();
        });

        $this->createSellerLedgerTables();

        Schema::create('vendor_payout_requests', static function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 60);
            $table->unsignedBigInteger('seller_id');
            $table->string('seller_is', 20)->default('seller');
            $table->decimal('amount', 24, 4)->default(0);
            $table->string('currency', 20)->nullable();
            $table->string('status', 20)->default('requested');
            $table->string('method', 40)->nullable();
            $table->text('method_details')->nullable();
            $table->unsignedBigInteger('reserve_entry_id')->nullable();
            $table->unsignedBigInteger('payout_entry_id')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->string('payout_currency', 20)->nullable();
            $table->decimal('payout_amount', 24, 4)->nullable();
            $table->decimal('exchange_rate', 24, 8)->nullable();
            $table->timestamps();
        });

        $this->flushCompanyAnswers();
    }

    /**
     * The two tables the seller's side of an order line is written to, without the rest of the world around them, for a test case that already has
     * its own orders, sellers, currencies and settings.
     */
    protected function createSellerLedgerTables(): void
    {
        Schema::create('order_item_commissions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is', 20)->default('seller');
            $table->unsignedBigInteger('commission_rule_id')->nullable();
            $table->string('rule_scope_type', 40)->nullable();
            $table->string('rule_label')->nullable();
            $table->string('rate_type', 20)->nullable();
            $table->decimal('percentage', 12, 4)->default(0);
            $table->decimal('fixed_amount', 24, 4)->default(0);
            $table->decimal('commissionable_amount', 24, 4)->default(0);
            $table->decimal('commission_amount', 24, 4)->default(0);
            $table->decimal('seller_net_amount', 24, 4)->default(0);
            $table->string('currency', 20)->nullable();
            $table->decimal('reversed_amount', 24, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('vendor_ledger_entries', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('seller_is', 20)->default('seller');
            $table->string('entry_type', 40);
            $table->decimal('debit', 24, 4)->default(0);
            $table->decimal('credit', 24, 4)->default(0);
            $table->decimal('balance_after', 24, 4)->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamp('available_at')->nullable();
            $table->string('currency', 20)->nullable();
            $table->string('reference_type', 60)->nullable();
            $table->string('reference_id', 100)->nullable();
            $table->string('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_type', 20)->nullable();
            $table->timestamps();
        });
    }

    protected function tearDownSellerLedgerWorld(): void
    {
        foreach ($this->sellerLedgerWorldTables as $table) {
            Schema::dropIfExists($table);
        }


        $this->flushCompanyAnswers();
    }

    /** The company column of the seller ledger and of the orders, added by their own migrations rather than imitated here. */
    protected function runCompanyMigrations(): void
    {
        foreach ([
            'migrations/2026_11_27_100100_add_legal_entity_to_orders_table.php',
            'migrations/2026_11_27_100300_add_legal_entity_to_vendor_ledger_entries_table.php',
        ] as $file) {
            (require database_path($file))->up();
        }

        $this->flushCompanyAnswers();
    }

    protected function turnSellingCompaniesOn(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.legal_entities.enabled' => true]);
        $this->flushCompanyAnswers();
    }

    /** An order already stamped with the company that sold it, the way OrderLegalEntitySnapshot stamps one. */
    protected function orderStampedWith(int $orderId, ?int $company): void
    {
        DB::table('orders')->insert(['id' => $orderId, 'order_amount' => 100]);

        if ($company !== null) {
            DB::table('orders')->where('id', $orderId)->update(['legal_entity_id' => $company]);
        }
    }

    /** @return array<int, ?int> the company of each ledger row, keyed by entry id */
    protected function companiesOnTheLedger(): array
    {
        return DB::table('vendor_ledger_entries')
            ->orderBy('id')
            ->pluck(LegalEntityRegistry::COLUMN, 'id')
            ->map(fn ($company) => $company === null ? null : (int) $company)
            ->all();
    }

    protected function ledger(): VendorLedger
    {
        return app(VendorLedger::class);
    }

    private function flushCompanyAnswers(): void
    {
        app(LegalEntityRegistry::class)->flush();
        app()->forgetInstance(OrderLegalEntitySnapshot::class);
    }
}
