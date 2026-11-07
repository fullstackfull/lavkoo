<?php

namespace Tests\Support;

use App\Models\Order;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the customer's order pages (the order details and the invoice) read besides the order itself, so the real templates can be
 * rendered with an order whose address copies are exactly what a test says: the currency of the store, the order lines and
 * payments (none), and the payment settings the page asks about.
 *
 * Like the other schema builders this is INFERRED from the models and the code that reads it, not from the installation dump.
 */
trait BuildsStorefrontOrderPages
{
    protected function createOrderPageTables(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code')->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        DB::table('currencies')->insert(['id' => 1, 'name' => 'Saudi Riyal', 'code' => 'SAR', 'symbol' => 'SAR', 'exchange_rate' => 1, 'status' => 1]);

        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 16, 2)->default(0);
            $table->decimal('tax', 16, 2)->default(0);
            $table->decimal('discount', 16, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('offline_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->longText('payment_info')->nullable();
            $table->timestamps();
        });

        Schema::create('order_edit_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->decimal('order_due_amount', 16, 2)->default(0);
            $table->string('order_due_payment_status')->nullable();
            $table->decimal('order_return_amount', 16, 2)->default(0);
            $table->string('order_return_payment_status')->nullable();
            $table->timestamps();
        });

        $this->setBusinessSetting('currency_model', 'single_currency');
        $this->setBusinessSetting('system_default_currency', 1);
        foreach (['digital_payment', 'cash_on_delivery', 'offline_payment'] as $payment) {
            $this->setBusinessSetting($payment, ['status' => 0]);
        }
        session(['currency_code' => 'SAR', 'currency_exchange_rate' => 1]);
    }

    /**
     * A paid, delivered order that keeps the given copies of its addresses. An array is stored as JSON, a string as it is (so a
     * test can store a copy that was encoded twice).
     *
     * @param array<string, mixed>|string|null $shipping
     * @param array<string, mixed>|string|null $billing
     */
    protected function makeOrderKeeping(array|string|null $shipping, array|string|null $billing = null, int $customerId = 1, int $id = 100): Order
    {
        $encode = static fn (array|string|null $copy): ?string => is_array($copy) ? json_encode($copy, JSON_UNESCAPED_UNICODE) : $copy;

        DB::table('orders')->insert([
            'id' => $id, 'customer_id' => $customerId, 'is_guest' => 0, 'order_type' => 'default_type', 'order_status' => 'delivered', 'payment_status' => 'paid',
            'shipping_address_data' => $encode($shipping), 'billing_address_data' => $encode($billing ?? $shipping), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Order::findOrFail($id);
    }

    /** @param array<string, mixed> $overrides */
    protected function renderOrderDetails(Order $order): string
    {
        return view('web-views.users-profile.account-order-details', [
            'order' => $order, 'refund_day_limit' => 0, 'current_date' => now(), 'paymentGatewayList' => [], 'cashOnDeliveryStatus' => 0, 'isPhysicalProduct' => true,
            'offlinePaymentStatus' => ['status' => 0], 'digitalPayment' => 0, 'walletStatus' => 0, 'offlinePaymentMethods' => collect(), 'availablePaymentMethod' => [],
        ])->render();
    }

    protected function renderInvoice(Order $order): string
    {
        return view('web-views.order.invoice', ['order' => $order, 'invoiceSettings' => []])->render();
    }
}
