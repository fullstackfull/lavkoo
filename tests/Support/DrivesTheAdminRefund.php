<?php

namespace Tests\Support;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/**
 * The administrator's refund screen, driven as an administrator drives it.
 *
 * Marking a refund `refunded` at POST admin/refund-section/refund/refund-status-update is the one place in this application that moves money back to a
 * buyer for a refund request: it is what credits the customer's wallet, writes the refund transaction and takes the amount off the seller's earnings.
 * Nothing in the repository drove that route before, so the figures a refund actually hands back were never measured end to end.
 *
 * The real controller, the real form request, the real services and the real admin guard chain run here. Only the heavy request middleware that needs the
 * installation database is off, as every other admin test in this repository switches it off.
 */
trait DrivesTheAdminRefund
{
    protected const UPDATE_REFUND_STATUS = '/admin/refund-section/refund/refund-status-update';

    /** The tables the refund payout writes to, and the administrator who signs the decision. */
    protected function buildTheAdminRefundDesk(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('transaction_id', 36)->nullable();
            $table->decimal('credit', 24, 6)->default(0);
            $table->decimal('debit', 24, 6)->default(0);
            $table->decimal('admin_bonus', 24, 6)->default(0);
            $table->decimal('balance', 24, 6)->default(0);
            $table->string('transaction_type')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('refund_statuses');
        Schema::create('refund_statuses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('refund_request_id')->nullable();
            $table->string('change_by')->nullable();
            $table->unsignedBigInteger('change_by_id')->nullable();
            $table->string('status')->nullable();
            $table->text('message')->nullable();
            $table->text('approved_note')->nullable();
            $table->text('rejected_note')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('seller_wallets');
        Schema::create('seller_wallets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->decimal('total_earning', 24, 6)->default(0);
            $table->decimal('withdrawn', 24, 6)->default(0);
            $table->decimal('commission_given', 24, 6)->default(0);
            $table->decimal('pending_withdraw', 24, 6)->default(0);
            $table->decimal('delivery_charge_earned', 24, 6)->default(0);
            $table->decimal('collected_cash', 24, 6)->default(0);
            $table->decimal('total_tax_collected', 24, 6)->default(0);
            $table->timestamps();
        });

        $this->buildTheRefundTransactionsTable();
        $this->addTheDecisionColumns();
        $this->addTheRefundingAdmin();
        $this->switchOffTheHeavyMiddleware();
    }

    /**
     * The columns of refund_requests that the administrator's decision writes, which the customer's own refund fixture does not need.
     *
     * App\Models\RefundRequest declares all of them fillable; the installed dump has them. They are added here rather than in the shared fixture so
     * that the tests of what a refund REQUEST holds keep the narrower table they were measured against.
     */
    private function addTheDecisionColumns(): void
    {
        foreach (['approved_note' => 'text', 'rejected_note' => 'text', 'payment_info' => 'text', 'change_by' => 'string'] as $column => $type) {
            if (!Schema::hasColumn('refund_requests', $column)) {
                Schema::table('refund_requests', fn (Blueprint $table) => $type === 'text' ? $table->text($column)->nullable() : $table->string($column)->nullable());
            }
        }

        foreach (['approved_count', 'denied_count'] as $column) {
            if (!Schema::hasColumn('refund_requests', $column)) {
                Schema::table('refund_requests', fn (Blueprint $table) => $table->integer($column)->default(0));
            }
        }
    }

    /** A setting of the shop, written where getWebConfig() reads it. */
    protected function shopSetting(string $type, mixed $value): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => $type], ['value' => is_string($value) ? $value : json_encode($value)]);
        Cache::forget(CACHE_BUSINESS_SETTINGS_TABLE);
        Cache::forget($type);
        session()->flush();
    }

    /** The wallet is on and a refund marked `customer_wallet` credits it: the one path that hands a buyer their money back for a refund request. */
    protected function theWalletTakesRefunds(): void
    {
        $this->shopSetting('wallet_status', 1);
        $this->shopSetting('wallet_add_refund', 1);
    }

    /**
     * The shop keeps several currencies and converts at the rate of the day, which is the setting under which an amount stored in the platform's base
     * unit is re-converted every time it is read. Under `single_currency` no conversion happens at all, so a rate cannot move a figure.
     */
    protected function theShopConvertsAtTheRateOfTheDay(string $defaultCurrency, int $decimals = 2): void
    {
        if (!DB::table('currencies')->where('code', 'USD')->exists()) {
            // The base unit every stored amount is in. An installation has this row; the converters read it by code and fatal without it.
            DB::table('currencies')->insert(['name' => 'US Dollar', 'code' => 'USD', 'symbol' => '$', 'exchange_rate' => 1, 'status' => 1]);
            Cache::forget('active_currency_list');
        }

        $this->shopSetting('decimal_point_settings', (string) $decimals);
        $this->shopSetting('currency_model', json_encode('multi_currency'));
        $this->shopSetting('system_default_currency', json_encode((int) DB::table('currencies')->where('code', $defaultCurrency)->value('id')));
    }

    /** The rate of a currency changes, as an operator changes it on the currency screen, and everything remembered about the old one is forgotten. */
    protected function theRateOfBecomes(string $code, float|string $rate): void
    {
        DB::table('currencies')->where('code', $code)->update(['exchange_rate' => $rate]);
        session()->flush();
        Cache::forget('active_currency_list');
    }

    /** The refund transaction register of the installed dump, with the migrations that put the order's money and its payment account on it. */
    protected function buildTheRefundTransactionsTable(): void
    {
        Schema::dropIfExists('refund_transactions');
        Schema::create('refund_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('payment_for')->nullable();
            $table->unsignedBigInteger('payer_id')->nullable();
            $table->unsignedBigInteger('payment_receiver_id')->nullable();
            $table->string('paid_by')->nullable();
            $table->string('paid_to')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->string('transaction_type')->nullable();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->unsignedBigInteger('refund_id')->nullable();
            $table->timestamps();
        });

        (require database_path('migrations/2026_12_03_100000_add_order_money_to_refund_tables.php'))->up();
        (require database_path('migrations/2026_12_04_100000_add_payment_account_to_refund_tables.php'))->up();
    }

    protected function dropTheAdminRefundDesk(): void
    {
        foreach (['wallet_transactions', 'refund_statuses', 'seller_wallets', 'refund_transactions', 'admins', 'admin_roles'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function addTheRefundingAdmin(): void
    {
        Schema::dropIfExists('admin_roles');
        Schema::create('admin_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->text('module_access')->nullable();
            $table->boolean('status')->default(1);
            $table->timestamps();
        });
        DB::table('admin_roles')->insert(['id' => 1, 'name' => 'Master Admin', 'module_access' => null, 'status' => 1]);

        Schema::dropIfExists('admins');
        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('admin_role_id')->default(1);
            $table->integer('status')->default(1);
            $table->timestamps();
        });
        DB::table('admins')->insert(['id' => 1, 'name' => 'Admin', 'email' => 'master@example.test', 'admin_role_id' => 1]);
    }

    private function switchOffTheHeavyMiddleware(): void
    {
        $this->withoutMiddleware([
            ActivationCheckMiddleware::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class,
            NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
    }

    /** The administrator marks a refund request refunded, through the route their screen posts to. */
    protected function refundItThroughTheAdminScreen(int $refundId, string $paymentMethod = 'customer_wallet', string $status = 'refunded'): TestResponse
    {
        return $this->actingAs(Admin::findOrFail(1), 'admin')->postJson(self::UPDATE_REFUND_STATUS, [
            'id' => $refundId,
            'refund_status' => $status,
            'payment_method' => $paymentMethod,
            'payment_info' => 'returned to the customer',
            'approved_note' => 'agreed',
        ]);
    }
}
