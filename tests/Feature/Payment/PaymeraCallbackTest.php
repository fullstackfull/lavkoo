<?php

namespace Tests\Feature\Payment;

use App\Http\Controllers\Payment_Methods\PaymeraController;
use App\Models\PaymentRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

function paymera_test_succeeding_hook(PaymentRequest $payment): void
{
    PaymeraCallbackTest::$succeeded++;
}

function paymera_test_failing_hook(PaymentRequest $payment): void
{
    throw new RuntimeException('personal data of payer ' . $payment->payer_id . ' is in this message');
}

/**
 * The Paymera callback writes no payload to any file or log, logs a failing success hook by class and location only, and sends
 * the header secret from configuration, never without one.
 */
class PaymeraCallbackTest extends TestCase
{
    public static int $succeeded = 0;

    private const SECRET = 'test-only-paymera-secret';
    private const TOKEN = 'test-only-paymera-token';
    private const PAYMERA_PAYMENT_ID = 'PM-TEST-1';
    private const RRN = 'RRN-TEST-7F3A';
    private const SUCCEEDING_HOOK = __NAMESPACE__ . '\paymera_test_succeeding_hook';
    private const FAILING_HOOK = __NAMESPACE__ . '\paymera_test_failing_hook';
    private const DEBUG_FILES = ['paymera_callback_test.txt', 'paymera_hook_test.txt'];

    protected function setUp(): void
    {
        parent::setUp();
        self::$succeeded = 0;
        $this->createSchema();
        config(['services.paymera.secret_key' => self::SECRET]);
        DB::table('addon_settings')->insert([
            'key_name' => 'paymera',
            'settings_type' => 'payment_config',
            'mode' => 'test',
            'test_values' => json_encode(['terminal_id' => 'TERM-1', 'token' => self::TOKEN]),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_callback_writes_nothing_to_the_debug_files(): void
    {
        Http::fake(['*/api/get-payment-status/*' => Http::response($this->paymeraStatus('A'))]);
        $before = $this->debugFileSizes();

        $this->runCallback($this->payment()->id);

        $this->assertSame($before, $this->debugFileSizes(), 'the callback wrote to a debug file');
    }

    public function test_an_accepted_callback_runs_the_success_hook_once_and_logs_nothing(): void
    {
        Http::fake(['*/api/get-payment-status/*' => Http::response($this->paymeraStatus('A'))]);
        $this->expectNoLogging();
        $payment = $this->payment();

        $this->runCallback($payment->id);

        $this->assertSame(1, self::$succeeded);
        $this->assertSame(1, (int) $this->stored($payment->id, 'is_paid'));
        $this->assertSame(self::RRN, $this->stored($payment->id, 'transaction_id'));
    }

    public function test_a_failing_success_hook_is_logged_by_class_and_location_only(): void
    {
        Http::fake(['*/api/get-payment-status/*' => Http::response($this->paymeraStatus('A'))]);
        $payment = $this->payment(successHook: self::FAILING_HOOK);
        Log::shouldReceive('error')->once()->with(
            'Paymera success hook failed',
            Mockery::on(fn (array $context): bool => $context['payment_id'] === $payment->id
                && $context['exception'] === RuntimeException::class
                && array_keys($context) === ['payment_id', 'exception', 'file', 'line']),
        );
        $this->expectNoLogging(except: 'error');

        $this->runCallback($payment->id);

        $this->assertSame(1, (int) $this->stored($payment->id, 'is_paid'), 'a failing hook must not undo an accepted payment');
    }

    public function test_the_status_request_sends_the_secret_from_configuration(): void
    {
        Http::fake(['*/api/get-payment-status/*' => Http::response($this->paymeraStatus('P'))]);

        $this->runCallback($this->payment()->id);

        Http::assertSent(fn (OutgoingRequest $request): bool => $request->hasHeader('X-Secret-Key', self::SECRET)
            && $request->hasHeader('Authorization', 'Bearer ' . self::TOKEN));
    }

    public function test_the_create_payment_request_sends_the_secret_from_configuration(): void
    {
        Http::fake(['*/api/create-payment' => Http::response([
            'ErrorCode' => 0,
            'Data' => ['url' => 'https://pay.example/session', 'paymentId' => self::PAYMERA_PAYMENT_ID],
        ])]);
        $payment = $this->payment();

        app(PaymeraController::class)->index(Request::create('/paymera/pay', 'POST', ['payment_id' => $payment->id]));

        Http::assertSent(fn (OutgoingRequest $request): bool => $request->hasHeader('X-Secret-Key', self::SECRET));
    }

    public function test_without_a_secret_no_request_is_sent_and_the_payment_fails(): void
    {
        config(['services.paymera.secret_key' => '']);
        Http::fake();
        $payment = $this->payment();

        $response = $this->runCallback($payment->id);

        Http::assertNothingSent();
        $this->assertSame(0, (int) $this->stored($payment->id, 'is_paid'));
        $this->assertStringStartsWith(route('payment-fail'), $response->getTargetUrl());
    }

    public function test_the_controller_has_no_debug_writes_dumps_or_secret_literal(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Payment_Methods/PaymeraController.php'));

        $this->assertStringNotContainsString('file_put_contents', $source);
        $this->assertStringNotContainsString('paymera_callback_test', $source);
        $this->assertStringNotContainsString('paymera_hook_test', $source);
        $this->assertDoesNotMatchRegularExpression('/\bdd\(/', $source);
        $this->assertDoesNotMatchRegularExpression("/'X-Secret-Key'\s*=>\s*'/", $source);
    }

    private function runCallback(string $paymentId): mixed
    {
        return app(PaymeraController::class)->callback(
            Request::create('/paymera/callback', 'GET', ['payment_id' => $paymentId])
        );
    }

    /**
     * Reloads the row: HasUuid assigns a UUID object to the key on create, and the stored string is what the code under test reads.
     */
    private function payment(string $successHook = self::SUCCEEDING_HOOK): PaymentRequest
    {
        return PaymentRequest::create([
            'payer_id' => 'customer-42',
            'payment_amount' => 25,
            'currency_code' => 'SYP',
            'payment_method' => 'paymera',
            'payment_platform' => 'web',
            'success_hook' => $successHook,
            'failure_hook' => '',
            'additional_data' => json_encode(['paymera_payment_id' => self::PAYMERA_PAYMENT_ID]),
            'is_paid' => 0,
        ])->refresh();
    }

    private function paymeraStatus(string $status): array
    {
        return ['ErrorCode' => 0, 'Data' => ['status' => $status, 'rrn' => self::RRN]];
    }

    private function stored(string $paymentId, string $column): mixed
    {
        return DB::table('payment_requests')->where('id', $paymentId)->value($column);
    }

    /** @return array<string, int|null> the size of each debug file, or null when it does not exist */
    private function debugFileSizes(): array
    {
        $sizes = [];
        foreach (self::DEBUG_FILES as $file) {
            $path = storage_path('logs/' . $file);
            $sizes[$file] = is_file($path) ? filesize($path) : null;
        }

        return $sizes;
    }

    private function expectNoLogging(?string $except = null): void
    {
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
            if ($level !== $except) {
                Log::shouldReceive($level)->never();
            }
        }
    }

    private function createSchema(): void
    {
        Schema::create('addon_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key_name')->nullable();
            $table->text('live_values')->nullable();
            $table->text('test_values')->nullable();
            $table->string('settings_type')->nullable();
            $table->string('mode')->default('test');
            $table->integer('is_active')->default(0);
            $table->timestamps();
        });
        Schema::create('payment_requests', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('payer_id')->nullable();
            $table->string('receiver_id')->nullable();
            $table->decimal('payment_amount', 24, 2)->default(0);
            $table->string('currency_code', 8)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_platform')->nullable();
            $table->string('success_hook')->nullable();
            $table->string('failure_hook')->nullable();
            $table->text('additional_data')->nullable();
            $table->text('external_redirect_link')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->string('transaction_id')->nullable();
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('transaction_ref')->nullable();
            $table->timestamps();
        });
    }
}
