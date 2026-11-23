<?php

namespace Tests\Feature\Storefront;

use App\Models\LoyaltyPointTransaction;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Storefront\LedgerOrigin;
use App\Utils\CustomerManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsLedgerTables;

/**
 * What stands behind a ledger row is for the books, not for the customer: the statements the apps read (REST v1 wallet and loyalty lists) serialize the rows whole, so the
 * new columns are hidden on the models and a customer's statement keeps exactly the keys it had. The columns are still on the rows for the code that wants them.
 */
class LedgerStatementPayloadTest extends ApiAddressTestCase
{
    use BuildsLedgerTables;

    /** @var list<string> */
    private const WALLET_KEYS = ['id', 'user_id', 'transaction_id', 'credit', 'debit', 'admin_bonus', 'balance', 'transaction_type', 'payment_method', 'reference', 'created_at', 'updated_at'];

    /** @var list<string> */
    private const LOYALTY_KEYS = ['id', 'user_id', 'transaction_id', 'credit', 'debit', 'balance', 'reference', 'transaction_type', 'created_at', 'updated_at'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createWalletAndLoyaltyLedgers();
        foreach (['2026_11_26_100000_add_storefront_snapshot_to_orders_table.php', '2026_11_27_100100_add_legal_entity_to_orders_table.php'] as $migration) {
            (require database_path('migrations/' . $migration))->up();
        }
        Schema::table('users', fn (Blueprint $table) => $table->decimal('loyalty_point', 18, 4)->nullable()->default(0));
        $this->ledgerMigration()->up();
        foreach (['wallet_status' => '1', 'loyalty_point_status' => '1', 'loyalty_point_exchange_rate' => '100', 'loyalty_point_item_purchase_point' => '10'] as $type => $value) {
            DB::table('business_settings')->insert(['type' => $type, 'value' => $value]);
        }
        cache()->flush();
    }

    /** A customer with a row of each ledger that names its order, the way the writers now write them. */
    private function aCustomerWithAttributedRows(): int
    {
        $customer = $this->makeCustomer(['wallet_balance' => 100]);
        DB::table('orders')->insert(['id' => 5, 'order_group_id' => 'G-1', 'storefront_country' => 'KW', 'currency_code' => 'KWD', 'legal_entity_id' => 3, 'order_amount' => 10]);

        CustomerManager::create_wallet_transaction(user_id: $customer->id, amount: 10.0, transaction_type: 'order_place', reference: 'order payment', origin: LedgerOrigin::order(id: 5));
        CustomerManager::create_loyalty_point_transaction(user_id: $customer->id, reference: '5', amount: 100.0, transaction_type: 'order_place', origin: LedgerOrigin::order(id: 5));

        return $customer->id;
    }

    public function test_the_row_carries_what_stands_behind_it(): void
    {
        $this->aCustomerWithAttributedRows();

        $wallet = DB::table('wallet_transactions')->first();
        $points = DB::table('loyalty_point_transactions')->first();

        $this->assertSame(['order', '5', 'KW', 'KWD', 3], [$wallet->source_type, $wallet->source_id, $wallet->storefront_country, $wallet->currency_code, (int) $wallet->legal_entity_id]);
        $this->assertSame(['order', '5', 'KW', 3], [$points->source_type, $points->source_id, $points->storefront_country, (int) $points->legal_entity_id]);
    }

    public function test_the_models_keep_it_out_of_their_array_and_their_json_but_still_hold_it(): void
    {
        $this->aCustomerWithAttributedRows();

        $wallet = WalletTransaction::query()->firstOrFail();
        $points = LoyaltyPointTransaction::query()->firstOrFail();

        $this->assertSame(self::WALLET_KEYS, array_keys($wallet->toArray()));
        $this->assertSame(self::LOYALTY_KEYS, array_keys($points->toArray()));
        $this->assertSame(self::WALLET_KEYS, array_keys(json_decode($wallet->toJson(), true)));
        $this->assertSame(['order', '5', 'KW', 'KWD', 3], [$wallet->source_type, $wallet->source_id, $wallet->storefront_country, $wallet->currency_code, (int) $wallet->legal_entity_id]);
        $this->assertSame(['order', '5', 'KW'], [$points->source_type, $points->source_id, $points->storefront_country]);
    }

    public function test_the_wallet_statement_of_the_rest_api_keeps_the_keys_it_had(): void
    {
        $customerId = $this->aCustomerWithAttributedRows();

        $json = $this->asCustomer(User::findOrFail($customerId))->getJson('/api/v1/customer/wallet/list?limit=10&offset=1')->assertOk()->json();

        $this->assertCount(1, $json['wallet_transaction_list']);
        $this->assertSame(self::WALLET_KEYS, array_keys($json['wallet_transaction_list'][0]));
    }

    public function test_the_loyalty_statement_of_the_rest_api_keeps_the_keys_it_had(): void
    {
        $customerId = $this->aCustomerWithAttributedRows();

        $json = $this->asCustomer(User::findOrFail($customerId))->getJson('/api/v1/customer/loyalty/list?limit=10&offset=1')->assertOk()->json();

        $this->assertCount(1, $json['loyalty_point_list']);
        $this->assertSame(self::LOYALTY_KEYS, array_keys($json['loyalty_point_list'][0]));
    }
}
