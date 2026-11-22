<?php

namespace Tests\Support;

use App\Models\LegalEntity;
use App\Models\PaymentGatewayAccount;
use App\Models\User;
use App\Services\Payments\GatewayAccountRegistry;
use App\Services\Payments\GatewayAccountSecrets;
use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A shop that keeps several currencies and converts at the rate of the day — the shape in which an amount stored in the platform's base unit is converted
 * again every time it is read, and so the shape in which a refund paid out after a rate change gives back the wrong money.
 *
 * The storefront registry is on (so a payment can be stamped with the merchant account that takes it) and storefront currencies are OFF, which is the
 * platform's own configuration: a storefront that trades in its own currency answers `wallet_status` closed (StorefrontCurrency::CLOSED_SETTINGS) and
 * `currency_model` single (so no rate is applied at all), and neither the wallet refund nor the re-conversion exists there.
 *
 * Every order is paid through the real payment route and built by the gateway's own success hook, so the payment record a refund is worked out from is
 * the one production wrote.
 */
trait BuildsTheRefundPayoutWorld
{
    use BuildsStorefrontRefundWorld;
    use DrivesTheAdminRefund;

    protected const GATEWAY = 'paymob_accept';

    protected const START_PAYMENT = '/api/v1/digital-payment';

    /** The six storefronts, the refund tables, the administrator's desk, and the platform's own money settings in force. */
    protected function buildTheRefundPayoutWorld(): void
    {
        $this->buildTheSixStorefrontsWorld();
        (require database_path('migrations/2026_11_27_100100_add_legal_entity_to_orders_table.php'))->up();
        $this->buildTheRefundTables();
        (require database_path('migrations/2026_12_04_100000_add_payment_account_to_refund_tables.php'))->up();
        $this->installTheCompanyAndAccountTables();
        $this->buildTheAdminRefundDesk();
        $this->addTheOrderEditColumns();
        // The shop's own earnings row, which an installation has and which the refund desk reduces by the amount of a refund.
        DB::table('admin_wallets')->updateOrInsert(['admin_id' => 1], ['inhouse_earning' => 0]);
        (require database_path('migrations/2026_12_05_100000_add_original_payment_to_refund_transactions_table.php'))->up();

        // The platform's own money, not a storefront's: the configuration every shop runs today and the one a Saudi storefront runs.
        config(['gcc.storefronts.currency.enabled' => false]);
        $this->forgetWhatWasRemembered();
        $this->theWalletTakesRefunds();
    }

    /**
     * What an order edit leaves on the order, which the installed database has (database/migrations/2026_01_13_165849) and the shared fixture does not.
     *
     * An order edited after it was paid for no longer says what its payment covered, so the refund payout has to be able to see that it was edited.
     */
    protected function addTheOrderEditColumns(): void
    {
        if (Schema::hasColumn('orders', 'edited_status')) {
            return;
        }

        Schema::table('orders', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->tinyInteger('edited_status')->default(0);
            $table->decimal('edit_due_amount', 16, 2)->default(0);
            $table->decimal('edit_return_amount', 16, 2)->default(0);
        });
    }

    protected function dropTheRefundPayoutWorld(): void
    {
        $this->dropTheRefundTables();
        $this->dropTheAdminRefundDesk();
        Schema::dropIfExists('payment_gateway_accounts');
        Schema::dropIfExists('legal_entities');
        $this->forgetWhatWasRemembered();
    }

    protected function installTheCompanyAndAccountTables(): void
    {
        Schema::dropIfExists('payment_gateway_accounts');
        Schema::dropIfExists('legal_entities');

        foreach (['2026_11_27_100000_create_legal_entities_table.php', '2026_11_28_100000_create_payment_gateway_accounts_table.php'] as $migration) {
            (require database_path('migrations/' . $migration))->up();
        }
    }

    protected function forgetWhatWasRemembered(): void
    {
        StorefrontCatalog::flush();
        app(LegalEntityRegistry::class)->flush();
        app(GatewayAccountRegistry::class)->flush();
    }

    /** Per-company gateway accounts switched on for Paymob, which is off by default (GCC_PAYMENT_ACCOUNTS). */
    protected function turnCompanyAccountsOn(): void
    {
        config([
            'gcc.storefronts.legal_entities.enabled' => true,
            'gcc.storefronts.legal_entities.required_to_sell' => false,
            'gcc.storefronts.payment_accounts.enabled' => true,
            'gcc.storefronts.payment_accounts.gateways' => [self::GATEWAY],
        ]);
        $this->forgetWhatWasRemembered();
    }

    /** The refund payout mechanism, which config/gcc.php leaves off. */
    protected function turnTheOriginalPaymentPayoutOn(): void
    {
        config(['gcc.storefronts.refunds.from_original_payment' => true]);
    }

    protected function aCompanyIn(string $country): LegalEntity
    {
        $entity = LegalEntity::create([
            'country' => $country,
            'legal_name' => "Lavkoo {$country} Trading Co.",
            'trade_name' => "Lavkoo {$country}",
            'tax_registration_number' => "TAX-{$country}-001",
            'commercial_registration_number' => "CR-{$country}-777",
            'invoice_series_key' => "INV-{$country}",
            'address' => "1 Example Street, {$country}",
            'status' => true,
        ]);
        $this->forgetWhatWasRemembered();

        return $entity;
    }

    protected function anAccountFor(string $country, ?int $company): PaymentGatewayAccount
    {
        $account = PaymentGatewayAccount::create([
            'storefront_country' => $country,
            'gateway' => self::GATEWAY,
            'environment' => 'test',
            'legal_entity_id' => $company,
            'label' => "{$country} paymob test",
            'webhook_path_token' => Str::random(48),
            'status' => true,
        ]);

        app(GatewayAccountSecrets::class)->put(account: $account, secrets: ['secret_key' => "sk-{$country}", 'integration_id' => '2222', 'public_key' => "pk-{$country}", 'hmac' => "hmac-{$country}"]);
        $this->forgetWhatWasRemembered();

        return $account->refresh();
    }

    /**
     * A paid order of that storefront, placed the way the app places one: the customer fills a cart, starts a real gateway payment (where the account
     * stamp and the converted payment amount are written), the gateway reports the money arrived, and the success hook builds the order.
     *
     * @param list<int> $productIds one order line per product
     *
     * @return array{0: User, 1: int, 2: list<int>} who ordered, the order, and its lines in the order they were created
     */
    protected function payFrom(string $country, array $productIds, string $transaction, string $gateway = self::GATEWAY, ?string $currentCurrency = null): array
    {
        $customer = $this->makeCustomer();

        foreach ($productIds as $productId) {
            $this->fillTheCart($customer, $country, $productId);
        }

        $this->asCustomer($customer)->withHeader('X-Storefront', $country)
            ->postJson(self::START_PAYMENT, array_filter([
                'payment_method' => $gateway, 'payment_platform' => 'app', 'payment_request_from' => 'app', 'customer_id' => $customer->id, 'is_guest' => 0,
                // What the app sends for the money the customer is shopping in; the gateway takes that currency when it is one of the gateway's own.
                'current_currency_code' => $currentCurrency,
            ], static fn (mixed $value): bool => $value !== null))
            ->assertOk();

        $payment = DB::table('payment_requests')->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();

        // What every gateway controller writes when the gateway says the money arrived, and the only step of this test the application does not take.
        DB::table('payment_requests')->where('id', $payment->id)->update(['payment_method' => $gateway, 'is_paid' => 1, 'transaction_id' => $transaction]);
        $payment = DB::table('payment_requests')->where('id', $payment->id)->first();

        request()->replace([]);
        digital_payment_success(['is_paid' => 1, 'payment_method' => $gateway, 'transaction_id' => $transaction, 'additional_data' => $payment->additional_data]);

        $orderId = (int) DB::table('orders')->where('customer_id', $customer->id)->value('id');

        return [$customer, $orderId, DB::table('order_details')->where('order_id', $orderId)->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all()];
    }

    /**
     * What the gateway record says the customer paid, and in which currency.
     *
     * The amount is given at the decimals of that currency because the two engines hand a decimal column back differently (SQLite as 375, MariaDB as
     * 375.00000000); the figure is the same and it is compared at the scale the money has.
     */
    protected function whatWasPaid(string $transaction, int $decimals = 2): array
    {
        $payment = DB::table('payment_requests')->where('transaction_id', $transaction)->firstOrFail();

        return [self::money($payment->payment_amount, $decimals), (string) $payment->currency_code];
    }

    /** A stored money value at a fixed scale, so an assertion reads the same on SQLite and on MariaDB. */
    protected static function money(mixed $value, int $decimals): string
    {
        return number_format((float) $value, $decimals, '.', '');
    }

    /** @return list<array<string, mixed>> the wallet credits the customer was given, oldest first */
    protected function theWalletCreditsOf(User $customer): array
    {
        return DB::table('wallet_transactions')->where('user_id', $customer->id)->where('transaction_type', 'order_refund')->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all();
    }
}
