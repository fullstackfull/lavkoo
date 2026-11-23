<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The tables behind the two ledgers a store keeps, for tests that exercise the code that reads and writes them. The installation dump that owns them is not in the repository, so none of these is a copy of the
 * live schema: they hold the columns the code reads and writes, and prove the logic of the code, not the schema.
 *
 * The customer's wallet and loyalty ledgers have migrations (2022_05_12_104247, 2023_08_26_173523, 2022_05_14_063309, widened to DECIMAL(40,20) by 2026_05_07_000001); the tables are built here from the columns
 * they leave, because the older migrations are named classes, which PHP cannot load again for the next test of the same process. The seller ledger, `vendor_ledger_entries`, has no migration at all: its columns
 * are the ones `VendorLedgerEntry` makes fillable and `VendorLedger` writes, with the widths the values need; its real column types, defaults and indexes are not known here. The same holds for
 * `vendor_settlements`, whose columns are the ones `VendorSettlement` makes fillable and `SettlementEngine` writes: whether its two balance columns accept NULL in a real installation is not known here either.
 */
trait BuildsWalletAndVendorLedgerSchema
{
    /**
     * The customer endpoints read the user, count the orders, look up the profile image and ask the settings whether the wallet and the loyalty points are switched on. Account deletion reads the two order columns
     * that say whether an order is still on its way (`is_guest`, `order_status`), and `referral_customers` holds what a referral still owes a customer, which the same deletion leaves behind.
     */
    protected function buildCustomerLedgerTables(): void
    {
        $this->dropEveryTableOfTheTestDatabase();
        $this->createBusinessSettingsTable();

        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('image')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_phone_verified')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('email_verification_token')->nullable();
            $table->decimal('wallet_balance', 40, 20)->nullable();
            $table->decimal('loyalty_point', 40, 20)->nullable()->default(0);
            $table->string('referral_code')->nullable();
            $table->integer('referred_by')->nullable();
            // What deleting an account scrubs. The handler anonymises the row in ONE update, so a column it names and this table does not
            // have fails the whole statement -- and the deletion answered 500 before it touched either ledger, which is what every
            // assertion here is about. Keep this list and the handler's together.
            $table->string('remember_token')->nullable();
            $table->string('temporary_token')->nullable();
            $table->string('street_address')->nullable();
            $table->string('city')->nullable();
            $table->string('zip')->nullable();
            $table->string('country')->nullable();
            $table->string('house_no')->nullable();
            $table->string('apartment_no')->nullable();
            $table->string('cm_firebase_token')->nullable();
            $table->string('cm_firebase_token_web')->nullable();
            $table->string('login_medium')->nullable();
            $table->string('social_id')->nullable();
            $table->string('payment_card_last_four')->nullable();
            $table->string('payment_card_brand')->nullable();
            $table->string('payment_card_fawry_token')->nullable();
            $table->boolean('is_email_verified')->default(false);
            $table->timestamps();
        });

        // Deleting an account revokes that customer's access tokens, so the handler writes this table. Without it the delete answered 500
        // on a PDOException ("no such table: oauth_access_tokens") before it reached anything about the ledger, which is what every
        // assertion in AccountDeletionKeepsLedgerTest was waiting to read. Columns as the real migration leaves them
        // (2016_06_01_000002_create_oauth_access_tokens_table), and as BuildsCustomerLedgerSchema already builds them.
        Schema::create('oauth_access_tokens', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('name')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked')->default(false);
            $table->timestamps();
            $table->dateTime('expires_at')->nullable();
        });

        Schema::create('orders', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('order_status')->default('pending');
            $table->timestamps();
        });

        Schema::create('referral_customers', static function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id');
            $table->bigInteger('refer_by');
            $table->decimal('ref_by_earning_amount', 21, 12)->default(0);
            $table->decimal('customer_discount_amount', 21, 12)->default(0);
            $table->string('customer_discount_amount_type')->nullable();
            $table->boolean('is_used')->default(false);
            $table->timestamps();
        });

        Schema::create('storages', static function (Blueprint $table): void {
            $table->id();
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_transactions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->uuid('transaction_id');
            $table->decimal('credit', 40, 20)->default(0);
            $table->decimal('debit', 40, 20)->default(0);
            $table->decimal('admin_bonus', 40, 20)->default(0);
            $table->decimal('balance', 40, 20)->default(0);
            $table->string('transaction_type', 191)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('reference', 191)->nullable();
            $table->timestamps();
        });

        Schema::create('loyalty_point_transactions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->uuid('transaction_id');
            $table->decimal('credit', 40, 20)->default(0);
            $table->decimal('debit', 40, 20)->default(0);
            $table->decimal('balance', 40, 20)->default(0);
            $table->string('reference', 191)->nullable();
            $table->string('transaction_type', 191)->nullable();
            $table->timestamps();
        });
    }

    /** The seller ledger, and the two tables `VendorLedger::baseCurrency()` reads to label an entry that is given no currency. */
    protected function buildVendorLedgerTables(): void
    {
        $this->dropEveryTableOfTheTestDatabase();
        $this->createBusinessSettingsTable();

        Schema::create('currencies', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol', 20)->nullable();
            $table->string('code', 20)->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('status')->default(true);
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
            $table->unsignedBigInteger('settlement_id')->nullable();
            $table->unsignedBigInteger('legal_entity_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * The seller ledger and the settlements drawn from it. A settlement of a ledger that mixes currencies records no opening and no closing balance, so the two columns
     * accept NULL here; `$balancesAcceptNull: false` builds the table that refuses it, for the test of what the engine does about such a table.
     */
    protected function buildVendorSettlementTables(bool $balancesAcceptNull = true): void
    {
        $this->buildVendorLedgerTables();

        Schema::create('vendor_settlements', static function (Blueprint $table) use ($balancesAcceptNull): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->unsignedBigInteger('seller_id');
            $table->string('seller_is', 20)->default('seller');
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->decimal('opening_balance', 24, 4)->nullable($balancesAcceptNull);
            $table->decimal('total_credits', 24, 4)->default(0);
            $table->decimal('total_debits', 24, 4)->default(0);
            $table->decimal('net_amount', 24, 4)->default(0);
            $table->decimal('closing_balance', 24, 4)->nullable($balancesAcceptNull);
            $table->unsignedInteger('entry_count')->default(0);
            $table->string('currency', 20)->nullable();
            $table->unsignedBigInteger('legal_entity_id')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('calculated_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->string('payout_reference')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * The seller ledger, the settlements drawn from it, and the frozen commission snapshot a refund is reversed against. `order_item_commissions` has no migration in this
     * repository either, so its columns here are the ones `OrderItemCommission` makes fillable and `RefundReversalService` reads and writes.
     */
    protected function buildRefundReversalTables(): void
    {
        $this->buildVendorSettlementTables();

        Schema::create('order_item_commissions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_details_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is', 20)->default('seller');
            $table->decimal('commissionable_amount', 24, 4)->default(0);
            $table->decimal('commission_amount', 24, 4)->default(0);
            $table->decimal('seller_net_amount', 24, 4)->default(0);
            $table->decimal('reversed_amount', 24, 4)->default(0);
            $table->string('currency', 20)->nullable();
            $table->timestamps();
        });
    }

    private function createBusinessSettingsTable(): void
    {
        Schema::create('business_settings', static function (Blueprint $table): void {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });
    }

    /** Real engines keep their tables between tests, so the database is emptied first, and only if it is unmistakably a throwaway one. */
    private function dropEveryTableOfTheTestDatabase(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            return;
        }

        $database = (string) $connection->getDatabaseName();
        if (!app()->environment('testing') || !preg_match('/(^|_)test(s|ing)?($|_)/i', $database)) {
            throw new RuntimeException("Refusing to drop every table of '{$database}': the test database name must contain 'test' and APP_ENV must be 'testing'.");
        }

        Schema::dropAllTables();
    }
}
