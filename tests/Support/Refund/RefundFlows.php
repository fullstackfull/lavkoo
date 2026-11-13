<?php

namespace Tests\Support\Refund;

use App\Http\Controllers\Admin\Order\OrderController;
use App\Http\Controllers\Admin\Order\RefundController;
use App\Http\Requests\Admin\RefundStatusRequest;
use App\Services\CustomerWalletService;
use App\Services\DeliveryManTransactionService;
use App\Services\DeliveryManWalletService;
use App\Services\OrderEditReturnAmountService;
use App\Services\OrderStatusHistoryService;
use App\Services\RefundStatusService;
use App\Services\RefundTransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\Loyalty\LoyaltyTables;
use Tests\Support\Wallet\WalletTables;

/**
 * One customer's order line with a loyalty reward and a refund request, and the three admin actions that pay money or points
 * against it, called on the real controllers. The sequential characterization tests and the concurrency workers share it, so both
 * exercise the same code.
 */
final class RefundFlows
{
    public const CUSTOMER_ID = 1;
    public const ORDER_ID = 10;
    public const ORDER_DETAILS_ID = 20;
    public const REFUND_ID = 30;
    public const ORDER_AMOUNT = 100.0;
    public const LINE_REWARD_POINTS = 20.0;
    public const OPENING_POINTS = 50.0;
    public const RETURN_AMOUNT = 40.0;

    public static function createTables(): void
    {
        WalletTables::create();
        LoyaltyTables::create();
        RefundTables::create();
    }

    public static function dropTables(): void
    {
        RefundTables::drop();
        LoyaltyTables::drop();
        WalletTables::drop();
    }

    /** Wallet refunds and loyalty points on, amounts in one currency, and a customer holding enough points for the line's refund. */
    public static function seedSettingsAndCustomer(): void
    {
        WalletTables::setSetting('wallet_status', '1');
        WalletTables::setSetting('wallet_add_refund', '1');
        WalletTables::setSetting('currency_model', 'single_currency');
        WalletTables::setSetting('loyalty_point_for_each_order', '1');
        LoyaltyTables::enableLoyaltyPoints();
        WalletTables::seedCustomer(self::CUSTOMER_ID, 0.0);
        DB::table('users')->where('id', self::CUSTOMER_ID)->update(['loyalty_point' => self::OPENING_POINTS]);
        DB::table('admin_wallets')->insert(['admin_id' => 1, 'inhouse_earning' => 1000, 'pending_amount' => 1000]);
    }

    public static function seedOrderLine(
        string $orderStatus,
        float $editReturnAmount = 0.0,
        string $orderType = 'default_type',
        string $paymentMethod = 'cash_on_delivery',
        ?int $sellerId = null,
    ): void {
        DB::table('orders')->insert([
            'id' => self::ORDER_ID,
            'customer_id' => self::CUSTOMER_ID,
            'order_status' => $orderStatus,
            'payment_status' => 'paid',
            'payment_method' => $paymentMethod,
            'order_amount' => self::ORDER_AMOUNT,
            'edit_return_amount' => $editReturnAmount,
            'order_type' => $orderType,
            'seller_is' => 'admin',
            'seller_id' => $sellerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_details')->insert([
            'id' => self::ORDER_DETAILS_ID,
            'order_id' => self::ORDER_ID,
            'product_details' => json_encode(['id' => 1, 'product_type' => 'physical']),
            'price' => self::ORDER_AMOUNT,
            'delivery_status' => $orderStatus,
        ]);
        DB::table('order_details_rewards')->insert([
            'order_id' => self::ORDER_ID,
            'order_details_id' => self::ORDER_DETAILS_ID,
            'reward_type' => 'loyalty_point',
            'reward_amount' => self::LINE_REWARD_POINTS,
            'reward_delivered' => $orderStatus === 'delivered' ? 1 : 0,
        ]);
        if ($editReturnAmount > 0) {
            DB::table('order_edit_histories')->insert(['order_id' => self::ORDER_ID, 'order_return_payment_status' => 'pending']);
        }
    }

    public static function seedRefundRequest(): void
    {
        DB::table('refund_requests')->insert([
            'id' => self::REFUND_ID,
            'order_details_id' => self::ORDER_DETAILS_ID,
            'customer_id' => self::CUSTOMER_ID,
            'order_id' => self::ORDER_ID,
            'status' => 'approved',
            'amount' => self::ORDER_AMOUNT,
        ]);
    }

    /** @return array<string, string> the controller's JSON answer */
    public static function markRefunded(): array
    {
        $request = RefundStatusRequest::create('/admin/refund-section/refund/refund-status-update', 'POST', [
            'id' => self::REFUND_ID,
            'refund_status' => 'refunded',
            'payment_method' => 'customer_wallet',
            'payment_info' => 'wallet',
        ]);

        return app(RefundController::class)
            ->updateRefundStatus($request, app(RefundStatusService::class), app(RefundTransactionService::class))
            ->getData(true);
    }

    public static function returnAmountToWallet(string $amount): void
    {
        $request = Request::create('/admin/orders/return-amount', 'POST', [
            'order_id' => self::ORDER_ID,
            'amount' => $amount,
            'order_return_payment_method' => 'wallet',
            'order_return_payment_note' => 'returned',
        ]);

        app(OrderController::class)->orderReturnAmountToCustomer($request, app(CustomerWalletService::class), app(OrderEditReturnAmountService::class));
    }

    public static function changeOrderStatus(string $status): void
    {
        $request = Request::create('/admin/orders/status', 'POST', ['id' => self::ORDER_ID, 'order_status' => $status]);

        app(OrderController::class)->updateStatus(
            $request,
            app(DeliveryManTransactionService::class),
            app(DeliveryManWalletService::class),
            app(OrderStatusHistoryService::class),
        );
    }

    /** The posted amount as the admin form sends it: the stored value, as the driver returns it. */
    public static function storedReturnAmount(): string
    {
        return (string) DB::table('orders')->where('id', self::ORDER_ID)->value('edit_return_amount');
    }
}
