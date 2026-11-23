<?php

namespace Tests\Unit\Payments;

use App\Models\PaymentRequest;
use App\Services\Payments\GatewayConfirmations;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The two questions asked of a gateway's confirmation, each on its own.
 *
 * The controller that takes a confirmation has two defences and either of them alone would stop the attack, so each is pinned
 * here where nothing else can stand in for it: a payment that is settled already is never offered again, and a transaction of
 * a gateway is held once.
 */
class GatewayConfirmationsTest extends TestCase
{
    private const GATEWAY = 'paymob_accept';

    private GatewayConfirmations $confirmations;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('payment_requests');
        Schema::create('payment_requests', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->decimal('payment_amount', 24, 2)->default(0);
            $table->string('currency_code', 8)->nullable();
            $table->string('payment_method')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->timestamps();
        });
        Schema::dropIfExists(GatewayConfirmations::TABLE);
        (require database_path('migrations/2026_11_23_100001_create_gateway_confirmations_table.php'))->up();
        $this->confirmations = app(GatewayConfirmations::class);
    }

    public function test_a_payment_that_is_settled_already_is_not_offered_again(): void
    {
        $paid = $this->aPayment(amount: 100, isPaid: true);

        $this->assertNull($this->confirmations->thePaymentItSettles(gateway: self::GATEWAY, paymentId: $paid, reference: '4455667', minorUnits: '10000', currency: 'SAR'));
    }

    public function test_a_payment_that_is_waiting_is_offered(): void
    {
        $waiting = $this->aPayment(amount: 100, isPaid: false);

        $payment = $this->confirmations->thePaymentItSettles(gateway: self::GATEWAY, paymentId: $waiting, reference: '4455667', minorUnits: '10000', currency: 'SAR');

        $this->assertInstanceOf(PaymentRequest::class, $payment);
        $this->assertSame($waiting, $payment->id);
    }

    public function test_a_payment_of_another_gateway_is_not_offered(): void
    {
        $waiting = $this->aPayment(amount: 100, isPaid: false, gateway: 'paytabs');

        $this->assertNull($this->confirmations->thePaymentItSettles(gateway: self::GATEWAY, paymentId: $waiting, reference: '4455667', minorUnits: '10000', currency: 'SAR'));
    }

    public function test_a_payment_that_does_not_exist_is_not_offered(): void
    {
        $this->assertNull($this->confirmations->thePaymentItSettles(gateway: self::GATEWAY, paymentId: (string) Str::uuid(), reference: '4455667', minorUnits: '10000', currency: 'SAR'));
    }

    /** @return array<string, array{mixed, string}> what the gateway says it took, and in which currency */
    public static function anAmountThatIsNotThePayments(): array
    {
        return [
            'one minor unit less' => ['9999', 'SAR'],
            'one minor unit more' => ['10001', 'SAR'],
            'nothing' => ['0', 'SAR'],
            'the amount in major units' => ['100', 'SAR'],
            'not a number' => ['many', 'SAR'],
            'nothing at all' => [null, 'SAR'],
            'another currency' => ['10000', 'AED'],
            'no currency' => ['10000', ''],
        ];
    }

    #[DataProvider('anAmountThatIsNotThePayments')]
    public function test_a_confirmation_that_does_not_carry_the_payments_money_is_refused(mixed $minorUnits, string $currency): void
    {
        $waiting = $this->aPayment(amount: 100, isPaid: false);

        $this->assertNull($this->confirmations->thePaymentItSettles(gateway: self::GATEWAY, paymentId: $waiting, reference: '4455667', minorUnits: $minorUnits, currency: $currency));
    }

    public function test_the_currency_is_read_whatever_its_letters_look_like(): void
    {
        $waiting = $this->aPayment(amount: 100, isPaid: false);

        $this->assertNotNull($this->confirmations->thePaymentItSettles(gateway: self::GATEWAY, paymentId: $waiting, reference: '4455667', minorUnits: '10000', currency: 'sar'));
    }

    /** Three decimals: a currency whose minor unit is a thousandth is stated by the gateway in hundredths all the same. */
    public function test_an_amount_with_a_fraction_of_a_minor_unit_is_compared_as_the_gateway_states_it(): void
    {
        $waiting = $this->aPayment(amount: 12.35, isPaid: false, currency: 'KWD');

        $this->assertNotNull($this->confirmations->thePaymentItSettles(gateway: self::GATEWAY, paymentId: $waiting, reference: '4455667', minorUnits: '1235', currency: 'KWD'));
        $this->assertNull($this->confirmations->thePaymentItSettles(gateway: self::GATEWAY, paymentId: $waiting, reference: '4455667', minorUnits: '1234', currency: 'KWD'));
    }

    public function test_a_transaction_of_a_gateway_is_held_once(): void
    {
        $first = $this->aPayment(amount: 100, isPaid: false);
        $second = $this->aPayment(amount: 100, isPaid: false);

        $this->assertTrue($this->confirmations->hold(gateway: self::GATEWAY, reference: '4455667', paymentId: $first));
        $this->assertFalse($this->confirmations->hold(gateway: self::GATEWAY, reference: '4455667', paymentId: $second));
        $this->assertSame(1, DB::table(GatewayConfirmations::TABLE)->count());
        $this->assertSame($first, DB::table(GatewayConfirmations::TABLE)->value('payment_request_id'));
    }

    public function test_two_gateways_may_each_have_a_transaction_of_the_same_name(): void
    {
        $payment = $this->aPayment(amount: 100, isPaid: false);

        $this->assertTrue($this->confirmations->hold(gateway: self::GATEWAY, reference: '4455667', paymentId: $payment));
        $this->assertTrue($this->confirmations->hold(gateway: 'paytabs', reference: '4455667', paymentId: $payment));
    }

    /** Without the register's table the question cannot be asked, and the caller is told so rather than being refused. */
    public function test_a_transaction_is_held_without_asking_while_the_register_is_not_installed(): void
    {
        Schema::drop(GatewayConfirmations::TABLE);
        $payment = $this->aPayment(amount: 100, isPaid: false);

        $this->assertTrue($this->confirmations->hold(gateway: self::GATEWAY, reference: '4455667', paymentId: $payment));
        $this->assertTrue($this->confirmations->hold(gateway: self::GATEWAY, reference: '4455667', paymentId: $payment));
    }

    private function aPayment(float $amount, bool $isPaid, string $gateway = self::GATEWAY, string $currency = 'SAR'): string
    {
        $id = (string) Str::uuid();
        DB::table('payment_requests')->insert(['id' => $id, 'payment_amount' => $amount, 'currency_code' => $currency, 'payment_method' => $gateway, 'is_paid' => $isPaid, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
