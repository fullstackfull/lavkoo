<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSellerApiWorld;
use Tests\TestCase;

/**
 * What the seller app's point of sale is told about a customer (E-04).
 *
 * The lookup answered `User::get()->toArray()`: every column of a customer the model does not hide, to a seller who typed three letters of
 * a name into a till. A seller needs to tell one customer from another and see what they can pay with; they were also being sent the
 * customer's street address, their city, their postcode, their referral codes, their verification flags, their temporary token and their
 * loyalty points.
 *
 * The app reads seven fields (`lib/features/pos/domain/models/customer_model.dart`), so naming those seven takes nothing away from it and
 * stops sending the rest. Whether a seller at a till should be told a customer's e-mail address at all is the owner's to decide; this test
 * pins what the app has always been sent, so the day that decision narrows it further, it narrows here.
 */
class SellerApiPosCustomerFieldsTest extends TestCase
{
    use BuildsSellerApiWorld;

    private const WALK_IN = 1;

    private const CUSTOMER = 2;

    /** Exactly what `Customers.fromJson` in the seller app reads, and nothing else. */
    private const FIELDS_THE_APP_READS = ['id', 'f_name', 'l_name', 'phone', 'image', 'email', 'wallet_balance'];

    /** Columns a customer's row holds that a till has no use for, and was being sent. */
    private const WHAT_IT_USED_TO_LEAK = [
        'street_address', 'city', 'zip', 'referral_code', 'temporary_token', 'is_phone_verified', 'loyalty_point', 'cm_firebase_token',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerApiWorld();
        $this->createSellerApiTable('users', function (Blueprint $table): void {
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('image')->nullable();
            $table->decimal('wallet_balance', 40, 20)->default(0);
            $table->decimal('loyalty_point', 40, 20)->default(0);
            $table->string('street_address')->nullable();
            $table->string('city')->nullable();
            $table->string('zip')->nullable();
            $table->string('referral_code')->nullable();
            $table->string('temporary_token')->nullable();
            $table->boolean('is_phone_verified')->default(0);
            $table->string('cm_firebase_token')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        foreach ([self::WALK_IN, self::CUSTOMER] as $id) {
            DB::table('users')->insert([
                'id' => $id,
                'f_name' => 'Customer',
                'l_name' => 'Number ' . $id,
                'phone' => '05512345' . $id . $id,
                'email' => 'customer' . $id . '@example.test',
                'image' => 'customer-' . $id . '.png',
                'wallet_balance' => 125.5,
                'loyalty_point' => 340,
                'street_address' => '12 Olaya Example Road',
                'city' => 'Riyadh',
                'zip' => '11564',
                'referral_code' => 'REF' . $id,
                'temporary_token' => 'tok-' . $id,
                'is_phone_verified' => 1,
                'cm_firebase_token' => 'firebase-' . $id,
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerApiWorld();

        parent::tearDown();
    }

    /** @return array<string, mixed> the first customer of the answer */
    private function firstCustomerOf(array $query = ['type' => 'all']): array
    {
        $response = $this->sellerApi('GET', 'pos/customers?' . http_build_query($query));
        $response->assertOk();

        return $response->json('customers.0');
    }

    public function test_the_lookup_answers_the_seven_fields_the_app_reads_and_nothing_else(): void
    {
        $customer = $this->firstCustomerOf();

        $this->assertSame(self::FIELDS_THE_APP_READS, array_keys($customer), 'a key more is a field the app did not ask for; a key fewer breaks the till');
        $this->assertSame(self::WALK_IN, $customer['id']);
        $this->assertSame('Customer', $customer['f_name']);
        $this->assertSame('Number 1', $customer['l_name']);
        $this->assertSame('customer1@example.test', $customer['email']);
        $this->assertSame('customer-1.png', $customer['image']);
        $this->assertSame(125.5, (float) $customer['wallet_balance'], 'what the customer can pay with, which is why a till is told it');
    }

    public function test_nothing_of_the_customers_own_business_is_sent_to_a_till(): void
    {
        $answer = (string) $this->sellerApi('GET', 'pos/customers?type=all')->getContent();

        foreach (self::WHAT_IT_USED_TO_LEAK as $column) {
            $this->assertStringNotContainsString('"' . $column . '"', $answer, "{$column} reached a seller who searched for a name");
        }

        $this->assertStringNotContainsString('11564', $answer, 'nor the value of one, under any other name');
        $this->assertStringNotContainsString('Olaya', $answer);
        $this->assertStringNotContainsString('tok-1', $answer);
    }

    /** The lookup drops the first row unless the caller asks for everyone. Inherited, and pinned so that naming the fields did not change it. */
    public function test_a_lookup_that_is_not_for_everyone_still_drops_the_first_row(): void
    {
        $this->assertSame(self::CUSTOMER, $this->firstCustomerOf(['type' => 'pos'])['id']);
        $this->assertSame(self::WALK_IN, $this->firstCustomerOf(['type' => 'all'])['id']);
    }

    public function test_a_search_still_finds_a_customer_by_either_part_of_their_name(): void
    {
        $found = $this->sellerApi('GET', 'pos/customers?' . http_build_query(['type' => 'all', 'name' => 'Number 2']));

        $found->assertOk();
        $this->assertSame([self::CUSTOMER], array_column($found->json('customers'), 'id'));
    }
}
