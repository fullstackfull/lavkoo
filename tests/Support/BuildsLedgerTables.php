<?php

namespace Tests\Support;

use App\Repositories\LoyaltyPointTransactionRepository;
use App\Repositories\WalletTransactionRepository;
use App\Services\Storefront\LedgerOrigin;
use App\Utils\CustomerManager;
use App\Utils\OrderManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two ledgers as the migrations that define them leave them, the ledger migration of E-05, the six writers of the ledgers called the same way from a test, and a reading of
 * the newest row of a ledger that serves SQLite and MySQL alike. For a test that builds the rest of its world itself.
 */
trait BuildsLedgerTables
{
    public const LEDGER_COLUMNS = ['source_type', 'source_id', 'actor_type', 'actor_id', 'storefront_country', 'currency_code', 'legal_entity_id'];

    private const LEDGER_MIGRATION = 'migrations/2026_12_05_100000_add_attribution_to_wallet_and_loyalty_ledgers.php';

    protected function createWalletAndLoyaltyLedgers(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->uuid('transaction_id');
            $table->decimal('credit', 24, 3)->default(0);
            $table->decimal('debit', 24, 3)->default(0);
            $table->decimal('admin_bonus', 24, 3)->default(0);
            $table->decimal('balance', 24, 3)->default(0);
            $table->string('transaction_type', 191)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('reference', 191)->nullable();
            $table->timestamps();
        });
        Schema::create('loyalty_point_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->uuid('transaction_id');
            $table->decimal('credit', 24, 3)->default(0);
            $table->decimal('debit', 24, 3)->default(0);
            $table->decimal('balance', 24, 3)->default(0);
            $table->string('reference', 191)->nullable();
            $table->string('transaction_type', 191)->nullable();
            $table->timestamps();
        });
    }

    protected function ledgerMigration(): object
    {
        return require database_path(self::LEDGER_MIGRATION);
    }

    /**
     * The newest row of a ledger without its id and its uuid, with the numbers as numbers, so that one expectation serves SQLite and MySQL.
     *
     * @return array<string, mixed>
     */
    protected function newestLedgerRow(string $table = 'wallet_transactions'): array
    {
        $row = (array) DB::table($table)->orderByDesc('id')->first();
        unset($row['id']);

        $row['transaction_id'] = preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/', (string) $row['transaction_id']) === 1 ? '<uuid>' : $row['transaction_id'];

        foreach (['credit', 'debit', 'admin_bonus', 'balance'] as $money) {
            if (array_key_exists($money, $row)) {
                $row[$money] = (float) $row[$money];
            }
        }
        foreach (['user_id', 'actor_id', 'legal_entity_id'] as $number) {
            if (isset($row[$number])) {
                $row[$number] = (int) $row[$number];
            }
        }

        return $row;
    }

    /** @return array<string, null> what the attribution columns hold when nothing was recorded */
    protected function emptyAttribution(string $table = 'wallet_transactions'): array
    {
        $columns = $table === 'wallet_transactions' ? self::LEDGER_COLUMNS : array_values(array_diff(self::LEDGER_COLUMNS, ['currency_code']));

        return array_fill_keys($columns, null);
    }

    /** @return array<string, array{string}> */
    public static function walletWriterCases(): array
    {
        return ['CustomerManager' => ['CustomerManager'], 'OrderManager' => ['OrderManager'], 'CustomerTrait' => ['CustomerTrait'], 'WalletTransactionRepository' => ['WalletTransactionRepository']];
    }

    /** @return array<string, array{string}> */
    public static function loyaltyWriterCases(): array
    {
        return ['CustomerManager' => ['CustomerManager'], 'LoyaltyPointTransactionRepository' => ['LoyaltyPointTransactionRepository']];
    }

    protected function writeWalletRow(string $writer, int $customerId, float $amount, string $type, string $reference, ?LedgerOrigin $origin = null): mixed
    {
        return match ($writer) {
            'CustomerManager' => CustomerManager::create_wallet_transaction(user_id: $customerId, amount: $amount, transaction_type: $type, reference: $reference, origin: $origin),
            'OrderManager' => OrderManager::createWalletTransaction(user_id: $customerId, amount: $amount, transaction_type: $type, reference: $reference, origin: $origin),
            'CustomerTrait' => app(LedgerWritingHost::class)->write(customerId: $customerId, amount: $amount, transactionType: $type, reference: $reference, origin: $origin),
            'WalletTransactionRepository' => app(WalletTransactionRepository::class)->addWalletTransaction(user_id: (string) $customerId, amount: $amount, transactionType: $type, reference: $reference, origin: $origin),
        };
    }

    protected function writeLoyaltyRow(string $writer, int $customerId, string $reference, float $amount, string $type, ?LedgerOrigin $origin = null): mixed
    {
        return match ($writer) {
            'CustomerManager' => CustomerManager::create_loyalty_point_transaction(user_id: $customerId, reference: $reference, amount: $amount, transaction_type: $type, origin: $origin),
            'LoyaltyPointTransactionRepository' => app(LoyaltyPointTransactionRepository::class)->addLoyaltyPointTransaction(userId: $customerId, reference: $reference, amount: $amount, transactionType: $type, origin: $origin),
        };
    }
}
