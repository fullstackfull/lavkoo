<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\Concurrency\ConcurrentRunner;

/**
 * D1-328: two deliveries of ONE paid gateway callback, at the same instant, in their own PHP processes, against a real engine. One order.
 *
 * The settlement hook refuses a payment whose order exists, and that check is a read. Before this guard, two deliveries both passed the
 * read before either wrote and both built a full order: two orders for one payment, the stock taken twice, the commission charged twice,
 * and nothing in the record saying which one the customer paid for. The workers here go straight to the order writer, so the hook's read
 * cannot answer the question for them and the only thing between them and two orders is the claim made inside the order's transaction.
 *
 * Needs a real MySQL or MariaDB: `lockForUpdate` does nothing on SQLite, and SQLite in memory is private to one process anyway.
 */
class PaidCallbackConcurrencyTest extends ApiAddressTestCase
{
    private const REFERENCE = 'TXN-RACE-ONE';

    private const PAYMENT_ROW = '7c1d9a04-55b2-4a7e-8f3d-2e6b0c914fab';

    private const NEW_CUSTOMER_EMAIL = 'guest-who-registered@example.test';

    private const NEW_CUSTOMER_PHONE = '0557654321';

    private const GUEST_ID = 9001;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
    }

    public function test_two_simultaneous_deliveries_of_one_paid_callback_build_one_order(): void
    {
        $customer = $this->makeCustomer();
        $this->putOneProductInTheCart(callerId: $customer->id, stock: 10);
        $addressId = $this->makeLegacyAddress(customerId: $customer->id)->id;
        DB::table('payment_requests')->insert([
            'id' => self::PAYMENT_ROW, 'payment_method' => 'ssl_commerz', 'transaction_id' => self::REFERENCE, 'payment_amount' => 100,
            'currency_code' => 'SAR', 'is_paid' => 1, 'additional_data' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $delivery = ['customer' => $customer->id, 'address' => $addressId, 'reference' => self::REFERENCE, 'payment' => self::PAYMENT_ROW];

        $reports = ConcurrentRunner::run('paid_settlement.settle', array_fill(0, 2, $delivery));

        $this->assertCount(2, array_unique(array_column($reports, 'pid')), 'two different processes');
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the deliveries ran at the same time');
        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "delivery {$index} let an exception escape: " . ($report['error'] ?? '') . ' ' . ($report['message'] ?? ''));
        }

        $built = array_filter(array_column($reports, 'result'), static fn (array $orderIds): bool => $orderIds !== []);
        $this->assertCount(1, $built, 'exactly one delivery built the orders of this payment');
        $this->assertSame(1, DB::table('orders')->count(), 'one payment, one order');
        $this->assertSame(1, DB::table('orders')->where('transaction_ref', self::REFERENCE)->count());
        $this->assertSame(1, (int) DB::table('order_details')->sum('qty'), 'and the stock was taken once');
        $this->assertSame(9, (int) DB::table('products')->where('id', 1)->value('current_stock'));
    }

    /**
     * The other half of the same callback: the guest who asked to be registered while paying.
     *
     * `digital_payment_place_orders` creates that customer with
     * `User::where(email)->orWhere(phone)->first() ?? User::create(...)`, BEFORE and OUTSIDE any transaction, so the claim
     * that makes one order per payment cannot answer for it -- the orders came out single while the customer came out
     * double. This runs the whole hook in two processes at one instant, which is the only thing that shows both halves at
     * once.
     */
    public function test_two_simultaneous_deliveries_of_one_paid_callback_register_one_customer(): void
    {
        $this->installTheRegistrationRegister();
        $guest = $this->aGuestWhoIsPaying();
        $delivery = [
            'payment' => self::PAYMENT_ROW, 'reference' => self::REFERENCE, 'guest' => $guest['guest'], 'address' => $guest['address'],
            'email' => self::NEW_CUSTOMER_EMAIL, 'phone' => self::NEW_CUSTOMER_PHONE,
        ];

        $reports = ConcurrentRunner::run('paid_settlement.register_and_settle', array_fill(0, 2, $delivery));

        $this->assertCount(2, array_unique(array_column($reports, 'pid')), 'two different processes');
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the deliveries ran at the same time');
        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "delivery {$index} let an exception escape: " . ($report['error'] ?? '') . ' ' . ($report['message'] ?? ''));
        }

        $this->assertSame(
            1,
            DB::table('users')->where('email', self::NEW_CUSTOMER_EMAIL)->count(),
            'one guest asked to be registered once and has ONE account; two means both deliveries missed the lookup and both inserted',
        );
        $this->assertSame(1, DB::table('orders')->where('transaction_ref', self::REFERENCE)->count(), 'and the order is still single');
        $this->assertSame(
            [self::NEW_CUSTOMER_EMAIL],
            DB::table('users')->where('email', self::NEW_CUSTOMER_EMAIL)->pluck('email')->all(),
            'the account that exists carries the e-mail the guest gave',
        );
    }

    /**
     * The measured negative control: WITHOUT the register, the same two deliveries register the guest TWICE.
     *
     * This is the figure the fix is read against. A guard test that passes because the state it needs cannot be reached
     * proves nothing, so the defect is measured here on the same world, the same payload and the same two processes --
     * only the register's migration is left unrun, which is also exactly how the platform behaves until it is applied.
     */
    public function test_without_the_register_the_same_two_deliveries_register_the_guest_twice(): void
    {
        $guest = $this->aGuestWhoIsPaying();
        $delivery = [
            'payment' => self::PAYMENT_ROW, 'reference' => self::REFERENCE, 'guest' => $guest['guest'], 'address' => $guest['address'],
            'email' => self::NEW_CUSTOMER_EMAIL, 'phone' => self::NEW_CUSTOMER_PHONE,
        ];

        $reports = ConcurrentRunner::run('paid_settlement.register_and_settle', array_fill(0, 2, $delivery));

        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the deliveries ran at the same time');
        $this->assertSame(
            2,
            DB::table('users')->where('email', self::NEW_CUSTOMER_EMAIL)->count(),
            'the defect: both deliveries missed the lookup and both inserted. If this is 1 the race did not happen and the test above proves nothing',
        );
        $this->assertSame(1, DB::table('orders')->where('transaction_ref', self::REFERENCE)->count(), 'while the order was already single, which is the half D1-328 closed');
    }

    /** The register of registrations, from its real migration, so a wrong migration fails a test. */
    private function installTheRegistrationRegister(): void
    {
        (require database_path('migrations/2026_12_28_100200_create_customer_registration_claims_table.php'))->up();
    }

    /**
     * A guest with a cart, an address of their own and a paid payment that carries the registration they asked for -- the
     * payload `PaymentController::getCustomerPaymentRequest()` writes for a guest who ticked "create an account".
     *
     * @return array{guest: int, address: int}
     */
    private function aGuestWhoIsPaying(): array
    {
        // A guest's id and nothing else: this platform's guest carries no row of their own that the settlement reads, and
        // `guest_users` is not one of the tables this world builds.
        $guestId = self::GUEST_ID;
        $this->putOneProductInTheCart(callerId: $guestId, isGuest: true, stock: 10);
        $addressId = (int) $this->makeLegacyAddress(customerId: $guestId, isGuest: true, overrides: [
            'email' => self::NEW_CUSTOMER_EMAIL, 'phone' => self::NEW_CUSTOMER_PHONE,
        ])->id;

        DB::table('payment_requests')->insert([
            'id' => self::PAYMENT_ROW, 'payment_method' => 'ssl_commerz', 'transaction_id' => self::REFERENCE, 'payment_amount' => 100,
            'currency_code' => 'SAR', 'is_paid' => 1, 'payer_id' => (string) $guestId, 'created_at' => now(), 'updated_at' => now(),
            'additional_data' => json_encode([
                'payment_mode' => 'web',
                'customer_id' => $guestId,
                'is_guest' => 1,
                'is_guest_in_order' => 1,
                'address_id' => $addressId,
                'billing_address_id' => null,
                'order_note' => null,
                'coupon_code' => null,
                'new_customer_info' => [
                    'name' => 'Sara Alharbi', 'f_name' => 'Sara Alharbi', 'l_name' => '',
                    'email' => self::NEW_CUSTOMER_EMAIL, 'phone' => self::NEW_CUSTOMER_PHONE,
                    'is_active' => 1, 'password' => 'a-password', 'referral_code' => 'REF12345',
                    'shipping_id' => $addressId, 'billing_id' => null,
                ],
            ]),
        ]);

        return ['guest' => $guestId, 'address' => $addressId];
    }
}
