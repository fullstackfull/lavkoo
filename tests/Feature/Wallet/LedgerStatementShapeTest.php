<?php

namespace Tests\Feature\Wallet;

use App\Models\LoyaltyPointTransaction;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMarketAccountSchema;
use Tests\TestCase;

/**
 * What the customer app is served when the expand migration has run and every switch is off, which is the state a deploy leaves behind:
 * `RestAPI/v1/UserWalletController::list()` and `RestAPI/v1/UserLoyaltyController::list()` return these models as they are, so a column
 * added to a ledger would appear in a Saudi customer's statement whatever the switches say. The contract with
 * `fullstackfull/lavkooapp` is that the keys do not move, so the migration's columns are hidden on both models.
 */
class LedgerStatementShapeTest extends TestCase
{
    use BuildsMarketAccountSchema;

    /** @return array<string, array{class-string, string}> */
    public static function statements(): array
    {
        return [
            'the wallet statement' => [WalletTransaction::class, 'wallet_transactions'],
            'the loyalty statement' => [LoyaltyPointTransaction::class, 'loyalty_point_transactions'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMarketAccountSchema();
        $this->seedCustomer(1, walletBalance: 250.5, loyaltyPoints: 80);
    }

    private function recordAStatementRowAsThePlatformWritesOneToday(string $ledger): void
    {
        DB::table($ledger)->insert([
            'user_id' => 1,
            'transaction_id' => 'e0000000-0000-4000-8000-000000000001',
            'credit' => 300,
            'debit' => 0,
            'balance' => 300,
            'transaction_type' => 'add_fund',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[DataProvider('statements')]
    public function test_a_saudi_statement_row_keeps_exactly_the_keys_it_had(string $model, string $ledger): void
    {
        $this->recordAStatementRowAsThePlatformWritesOneToday($ledger);
        $before = array_keys($model::query()->first()->toArray());

        $this->runMarketAccountMigration();

        $this->assertFalse((bool) config('gcc.storefronts.market_accounts.enabled'), 'every switch is off, as the repository ships');
        $this->assertTrue(Schema::hasColumn($ledger, 'storefront_country'), 'the migration really did add a column to this ledger');
        $this->assertSame($before, array_keys($model::query()->first()->toArray()));
    }

    #[DataProvider('statements')]
    public function test_a_row_written_after_the_migration_is_served_with_the_same_keys(string $model, string $ledger): void
    {
        $this->runMarketAccountMigration();
        $this->recordAStatementRowAsThePlatformWritesOneToday($ledger);

        $keys = array_keys($model::query()->first()->toArray());

        $this->assertNotContains('storefront_country', $keys);
        $this->assertNotContains('currency_code', $keys);
        $this->assertNotContains('legal_entity_id', $keys);
    }
}
