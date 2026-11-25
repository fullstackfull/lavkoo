<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use App\Services\Payments\OneAccountPerRegistration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * The register of registrations made while paying, away from the race: what it answers for one delivery, for a replay, and
 * for a guest who already has an account.
 *
 * The race itself is `tests/Feature/Concurrency/PaidCallbackConcurrencyTest`, which needs a real engine and two processes.
 * This file is the rest of the contract, and most of it is the promise that nothing moved: until the register's migration has
 * run, and for every registration the register cannot key, the settlement finds or creates a customer exactly as it always
 * did.
 */
class OneAccountPerRegistrationTest extends TestCase
{
    private const EMAIL = 'guest@example.test';

    private const PHONE = '0557654321';

    private OneAccountPerRegistration $register;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('users');
        Schema::dropIfExists(OneAccountPerRegistration::TABLE);
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('referral_code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $this->register = app(OneAccountPerRegistration::class);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists(OneAccountPerRegistration::TABLE);

        parent::tearDown();
    }

    public function test_until_the_register_is_installed_a_registration_is_the_find_or_create_it_always_was(): void
    {
        $this->assertFalse(Schema::hasTable(OneAccountPerRegistration::TABLE), 'the register is not installed in this test');

        $created = $this->registerTheGuest();

        $this->assertSame(1, DB::table('users')->count(), 'one account');
        $this->assertSame(self::EMAIL, $created->email);

        $again = $this->registerTheGuest();

        $this->assertSame(1, DB::table('users')->count(), 'and a second delivery finds it rather than inserting a second');
        $this->assertSame($created->getKey(), $again->getKey());
    }

    public function test_with_the_register_installed_one_delivery_registers_one_account_and_names_it_on_both_claims(): void
    {
        $this->installTheRegister();

        $created = $this->registerTheGuest();

        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(
            ['email:' . self::EMAIL, 'phone:' . self::PHONE],
            DB::table(OneAccountPerRegistration::TABLE)->orderBy('identity')->pluck('identity')->all(),
            'the e-mail and the phone each claimed, because the lookup this stands in for is an OR',
        );
        $this->assertSame(
            [$created->getKey(), $created->getKey()],
            DB::table(OneAccountPerRegistration::TABLE)->orderBy('identity')->pluck('user_id')->map(static fn ($id): int => (int) $id)->all(),
            'and both rows name the account they produced',
        );
    }

    public function test_a_delivery_of_the_same_callback_handed_over_again_finds_the_account(): void
    {
        $this->installTheRegister();

        $created = $this->registerTheGuest();
        $again = $this->registerTheGuest();

        $this->assertSame($created->getKey(), $again->getKey());
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(2, DB::table(OneAccountPerRegistration::TABLE)->count(), 'and no further rows were claimed');
    }

    public function test_a_guest_whose_phone_already_has_an_account_is_that_account_and_is_not_registered_again(): void
    {
        $this->installTheRegister();
        $existing = User::create(['f_name' => 'Noura', 'email' => 'someone.else@example.test', 'phone' => self::PHONE]);

        $answered = $this->registerTheGuest();

        $this->assertSame($existing->getKey(), $answered->getKey(), 'the lookup is an OR and the phone matched');
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(0, DB::table(OneAccountPerRegistration::TABLE)->count(), 'nothing was claimed, because nothing was registered');
    }

    public function test_an_email_differing_only_in_case_or_whitespace_is_the_same_claim(): void
    {
        $this->installTheRegister();

        $this->register->registerOnce(
            email: '  ' . strtoupper(self::EMAIL) . ' ',
            phone: self::PHONE,
            find: static fn () => null,
            create: static fn () => User::create(['f_name' => 'Sara', 'email' => self::EMAIL, 'phone' => self::PHONE]),
        );

        $this->assertSame(
            ['email:' . self::EMAIL, 'phone:' . self::PHONE],
            DB::table(OneAccountPerRegistration::TABLE)->orderBy('identity')->pluck('identity')->all(),
            'trimmed and lower-cased, because an address differing only in case is the same address to every mail server',
        );
    }

    public function test_a_registration_naming_neither_an_email_nor_a_phone_keeps_todays_behaviour(): void
    {
        $this->installTheRegister();

        $created = $this->register->registerOnce(
            email: '',
            phone: null,
            find: static fn () => null,
            create: static fn () => User::create(['f_name' => 'Nameless']),
        );

        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame('Nameless', $created->f_name);
        $this->assertSame(0, DB::table(OneAccountPerRegistration::TABLE)->count(), 'there is nothing to collide on, so nothing is claimed and nothing is promised');
    }

    public function test_a_registration_that_throws_takes_its_claims_back_so_a_later_delivery_can_still_register(): void
    {
        $this->installTheRegister();

        try {
            $this->register->registerOnce(
                email: self::EMAIL,
                phone: self::PHONE,
                find: static fn () => null,
                create: static fn () => throw new RuntimeException('the insert failed'),
            );
            $this->fail('the failure must not be swallowed: the settlement has to know the guest was not registered');
        } catch (RuntimeException $failure) {
            $this->assertSame('the insert failed', $failure->getMessage());
        }

        $this->assertSame(0, DB::table(OneAccountPerRegistration::TABLE)->count(), 'the claims went back with the failure');
        $this->assertSame(0, DB::table('users')->count());

        $created = $this->registerTheGuest();

        $this->assertSame(1, DB::table('users')->count(), 'and the guest can still be registered');
        $this->assertSame(self::EMAIL, $created->email);
    }

    /** The settlement hook's own two closures, with the data `PaymentController::getRegisterNewCustomer()` writes. */
    private function registerTheGuest(): User
    {
        return $this->register->registerOnce(
            email: self::EMAIL,
            phone: self::PHONE,
            find: static fn () => User::where(['email' => self::EMAIL])->orWhere(['phone' => self::PHONE])->first(),
            create: static fn () => User::create([
                'name' => 'Sara Alharbi', 'f_name' => 'Sara Alharbi', 'l_name' => '', 'email' => self::EMAIL,
                'phone' => self::PHONE, 'is_active' => 1, 'password' => bcrypt('a-password'), 'referral_code' => 'REF12345',
            ]),
        );
    }

    private function installTheRegister(): void
    {
        (require database_path('migrations/2026_12_28_100200_create_customer_registration_claims_table.php'))->up();
    }
}
