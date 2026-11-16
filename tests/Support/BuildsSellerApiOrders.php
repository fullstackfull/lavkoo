<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Orders, their lines and delivery men for the two sellers of BuildsSellerApiWorld and for the in-house shop.
 *
 * The in-house shop is the trap: its orders are stamped seller_id 1 with seller_is 'admin', its lines carry seller_id 1 and its
 * delivery men carry seller_id 0, so a handler that scopes on the id alone treats seller 1 as the in-house shop's owner.
 */
trait BuildsSellerApiOrders
{
    use BuildsSellerApiWorld;

    protected const OWN_ORDER = 100;
    protected const IN_HOUSE_ORDER = 200;
    protected const OTHER_SELLERS_ORDER = 300;

    protected const OWN_LINE = 1;
    protected const IN_HOUSE_LINE = 2;
    protected const OTHER_SELLERS_LINE = 3;

    protected const OWN_DELIVERY_MAN = 8;
    protected const OTHER_SELLERS_DELIVERY_MAN = 9;
    protected const IN_HOUSE_DELIVERY_MAN = 10;

    protected function createSellerApiOrderTables(): void
    {
        $this->createSellerApiTable('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->nullable();
            $table->unsignedBigInteger('shipping_method_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->string('delivery_type')->nullable();
            $table->string('delivery_service_name')->nullable();
            $table->string('third_party_delivery_tracking_id')->nullable();
            $table->dateTime('deliveryman_assigned_at')->nullable();
            $table->decimal('deliveryman_charge', 16, 2)->default(0);
            $table->dateTime('expected_delivery_date')->nullable();
            $table->string('order_status')->default('pending');
            $table->string('payment_status')->default('unpaid');
            $table->string('payment_method')->nullable();
            $table->string('order_type')->default('default_type');
            $table->decimal('order_amount', 16, 2)->default(0);
            $table->decimal('init_order_amount', 16, 2)->default(0);
            $table->decimal('discount_amount', 16, 2)->default(0);
            $table->decimal('refer_and_earn_discount', 16, 2)->default(0);
            $table->boolean('is_guest')->default(false);
            $table->timestamps();
        });
        $this->createSellerApiTable('order_details', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 16, 2)->default(0);
            $table->decimal('tax', 16, 2)->default(0);
            $table->decimal('discount', 16, 2)->default(0);
            $table->longText('product_details')->nullable();
            $table->string('digital_file_after_sell')->nullable();
            $table->string('variant')->nullable();
            $table->text('variation')->nullable();
            $table->string('delivery_status')->default('pending');
            $table->boolean('is_stock_decreased')->default(false);
            $table->integer('refund_request')->default(0);
            $table->timestamps();
        });
        $this->createSellerApiTable('delivery_men', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('identity_number')->nullable();
            $table->text('identity_image')->nullable();
            $table->string('image')->nullable();
            $table->string('fcm_token')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        $this->createSellerApiTable('storages', function (Blueprint $table) {
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('colors', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->timestamps();
        });
    }

    protected function seedSellerApiOrders(): void
    {
        $driver = fn (int $id, int $sellerId, string $name, string $phone) => DB::table('delivery_men')->insert([
            'id' => $id, 'seller_id' => $sellerId, 'f_name' => $name, 'phone' => $phone, 'email' => $name . '@example.test', 'identity_number' => 'ID-' . $id,
        ]);
        $driver(self::OWN_DELIVERY_MAN, self::SELLER, 'OwnDriver', '0555000008');
        $driver(self::OTHER_SELLERS_DELIVERY_MAN, self::OTHER_SELLER, 'OtherSellersDriver', '0555000009');
        $driver(self::IN_HOUSE_DELIVERY_MAN, 0, 'InHouseDriver', '0555000010');

        $order = fn (int $id, int $sellerId, string $sellerIs, ?int $deliveryManId) => DB::table('orders')->insert([
            'id' => $id, 'seller_id' => $sellerId, 'seller_is' => $sellerIs, 'delivery_man_id' => $deliveryManId, 'order_status' => 'pending', 'order_amount' => 500,
        ]);
        $order(self::OWN_ORDER, self::SELLER, 'seller', null);
        $order(self::IN_HOUSE_ORDER, self::IN_HOUSE_ID, 'admin', self::IN_HOUSE_DELIVERY_MAN);
        $order(self::OTHER_SELLERS_ORDER, self::OTHER_SELLER, 'seller', self::OTHER_SELLERS_DELIVERY_MAN);

        $line = fn (int $id, int $orderId, int $sellerId, string $name, float $price) => DB::table('order_details')->insert([
            'id' => $id, 'order_id' => $orderId, 'seller_id' => $sellerId, 'product_id' => $id * 10, 'qty' => 2, 'price' => $price, 'variation' => '[]',
            'product_details' => json_encode([
                'id' => $id * 10, 'name' => $name, 'images' => '[]', 'thumbnail' => 'thumb.png', 'colors' => '[]', 'variation' => '[]', 'choice_options' => '[]',
                'attributes' => '[]', 'category_ids' => '[]', 'unit_price' => $price,
            ]),
        ]);
        $line(self::OWN_LINE, self::OWN_ORDER, self::SELLER, 'Own item', 100);
        $line(self::IN_HOUSE_LINE, self::IN_HOUSE_ORDER, self::IN_HOUSE_ID, 'In-house secret item', 500);
        $line(self::OTHER_SELLERS_LINE, self::OTHER_SELLERS_ORDER, self::OTHER_SELLER, 'Other sellers item', 480);
    }

    /** @return array{0: int|null, 1: string|null} who delivers the order and how */
    protected function deliveryOf(int $orderId): array
    {
        $order = DB::table('orders')->where('id', $orderId)->first();

        return [$order->delivery_man_id === null ? null : (int) $order->delivery_man_id, $order->delivery_type];
    }
}
