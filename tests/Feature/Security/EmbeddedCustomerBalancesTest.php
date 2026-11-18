<?php

namespace Tests\Feature\Security;

use App\Models\Chatting;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderTransaction;
use App\Models\RefundRequest;
use App\Models\RestockProductCustomer;
use App\Models\Review;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A2-15 of the master plan: the seller app and the courier app receive whole orders, refund requests, reviews and chats with their customer embedded, and the customer
 * carried the wallet balance, the loyalty points and the referral code of a person who is neither the seller's nor the courier's to know. An embedded customer is now
 * serialized without them (App\Traits\HidesCustomerBalances); the attributes stay readable by the code, and the customer's own payload keeps them.
 */
class EmbeddedCustomerBalancesTest extends TestCase
{
    private const HIDDEN = ['wallet_balance', 'loyalty_point', 'referral_code'];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('storages');
        Schema::create('storages', function ($table): void {
            $table->id();
            $table->string('data_type')->nullable();
            $table->unsignedBigInteger('data_id')->nullable();
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('storages');

        parent::tearDown();
    }

    private function customer(): User
    {
        return (new User())->forceFill(['id' => 7, 'f_name' => 'Test', 'wallet_balance' => 125.5, 'loyalty_point' => 300, 'referral_code' => 'TESTCODE']);
    }

    /** @return array<string, array{class-string<Model>}> */
    public static function modelsThatEmbedACustomer(): array
    {
        return [
            'an order' => [Order::class],
            'a refund request' => [RefundRequest::class],
            'a review' => [Review::class],
            'a chat message' => [Chatting::class],
            'an order transaction' => [OrderTransaction::class],
            'a restock request' => [RestockProductCustomer::class],
            'a support ticket' => [SupportTicket::class],
        ];
    }

    /** @param class-string<Model> $class */
    #[DataProvider('modelsThatEmbedACustomer')]
    public function test_a_customer_embedded_in_a_payload_carries_no_balance_and_no_referral_code(string $class): void
    {
        $record = (new $class())->setRelation('customer', $this->customer());

        $embedded = $record->toArray()['customer'];

        foreach (self::HIDDEN as $attribute) {
            $this->assertArrayNotHasKey($attribute, $embedded, $attribute);
            $this->assertStringNotContainsString($attribute, (string) $record->toJson());
        }
        $this->assertSame([7, 'Test'], [$embedded['id'], $embedded['f_name']], 'who the customer is stays, which is what the seller and the courier need');
    }

    public function test_the_code_that_reads_the_attribute_still_reads_it(): void
    {
        $order = (new Order())->setRelation('customer', $this->customer());

        $this->assertEquals([125.5, 300, 'TESTCODE'], [$order->customer->wallet_balance, $order->customer->loyalty_point, $order->customer->referral_code]);
    }

    public function test_the_customers_own_payload_keeps_them(): void
    {
        $payload = $this->customer()->toArray();

        foreach (self::HIDDEN as $attribute) {
            $this->assertArrayHasKey($attribute, $payload, $attribute);
        }
    }

    public function test_a_customer_embedded_two_levels_down_is_cleaned_too(): void
    {
        $line = (new OrderDetail())->setRelation('order', (new Order())->setRelation('customer', $this->customer()));

        $embedded = $line->toArray()['order']['customer'];

        $this->assertSame([], array_intersect(self::HIDDEN, array_keys($embedded)));
    }

    public function test_a_record_with_no_customer_loaded_adds_nothing(): void
    {
        $this->assertArrayNotHasKey('customer', (new Order())->toArray());
        $this->assertNull((new Order())->setRelation('customer', null)->toArray()['customer']);
    }

    public function test_every_model_with_a_customer_relation_uses_the_trait(): void
    {
        $missing = [];

        foreach (glob(base_path('app/Models/*.php')) as $file) {
            $source = (string) file_get_contents($file);

            if (basename($file) !== 'User.php' && str_contains($source, 'public function customer(') && !str_contains($source, 'use HidesCustomerBalances;')) {
                $missing[] = basename($file);
            }
        }

        $this->assertSame([], $missing, 'a model that embeds a customer has to use App\Traits\HidesCustomerBalances');
    }
}
