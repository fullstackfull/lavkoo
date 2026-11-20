<?php

namespace Tests\Feature\Customer;

use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\LoyaltyPointTransaction;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\BuildsCustomerLedgerSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Base for the tests of the customer's two money ledgers: the ledgers built by their OWN migrations, the store tables around them, and
 * both ways a customer closes an account — the mobile app's REST v1 route and the website's.
 */
abstract class CustomerLedgerTestCase extends TestCase
{
    use BuildsCustomerLedgerSchema;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);

        $this->buildCustomerLedgerSchema();
        $this->turnTheWalletAndPointsOn();
        $this->useThrowawayPassportKeys();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** A customer with money in the wallet, points on the account and a movement of each already recorded. */
    protected function makeCustomerWithMoneyHistory(float $walletBalance = 150.25, float $loyaltyPoints = 400.0): User
    {
        $customer = $this->makeCustomer(['wallet_balance' => $walletBalance, 'loyalty_point' => $loyaltyPoints, 'image' => 'sara.webp']);
        $this->appendWalletMovement($customer, credit: 200.25, debit: 0.0, balance: 200.25, type: 'add_fund');
        $this->appendWalletMovement($customer, credit: 0.0, debit: 50.0, balance: $walletBalance, type: 'order_place');
        $this->appendLoyaltyMovement($customer, credit: 500.0, debit: 0.0, balance: 500.0, type: 'order_place');
        $this->appendLoyaltyMovement($customer, credit: 0.0, debit: 100.0, balance: $loyaltyPoints, type: 'point_to_wallet');

        return $customer;
    }

    protected function appendWalletMovement(User $customer, float $credit, float $debit, float $balance, string $type): WalletTransaction
    {
        return WalletTransaction::create([
            'user_id' => $customer->id, 'transaction_id' => (string) Str::uuid(), 'reference' => 'reference-' . $type,
            'transaction_type' => $type, 'credit' => $credit, 'debit' => $debit, 'balance' => $balance, 'admin_bonus' => 0,
        ]);
    }

    protected function appendLoyaltyMovement(User $customer, float $credit, float $debit, float $balance, string $type): LoyaltyPointTransaction
    {
        return LoyaltyPointTransaction::create([
            'user_id' => $customer->id, 'transaction_id' => (string) Str::uuid(), 'reference' => 'reference-' . $type,
            'transaction_type' => $type, 'credit' => $credit, 'debit' => $debit, 'balance' => $balance,
        ]);
    }

    /** @return array{users: int, wallet: int, loyalty: int} the rows that hold the customer's money and its history */
    protected function moneyRowsOf(int $customerId): array
    {
        return [
            'users' => DB::table('users')->where('id', $customerId)->count(),
            'wallet' => DB::table('wallet_transactions')->where('user_id', $customerId)->count(),
            'loyalty' => DB::table('loyalty_point_transactions')->where('user_id', $customerId)->count(),
        ];
    }

    protected function deleteThroughTheApi(User $customer, ?int $id = null): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($customer, 'api');

        return $this->withHeader('Authorization', 'Bearer test-token')->getJson('api/v1/customer/account-delete/' . ($id ?? $customer->id));
    }

    protected function deleteThroughTheWebsite(User $customer, ?int $id = null): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($customer, 'customer');

        return $this->get(route('account-delete', $id ?? $customer->id));
    }

    /** @var array{private: string, public: string}|null generated once per run: making a key pair is the slowest step of a test */
    private static ?array $passportKeys = null;

    /** A throwaway RSA key pair: Passport's token guard refuses to exist without one. */
    private function useThrowawayPassportKeys(): void
    {
        if (self::$passportKeys === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$passportKeys = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
        }

        config(['passport.private_key' => self::$passportKeys['private'], 'passport.public_key' => self::$passportKeys['public']]);
    }
}
