<?php

namespace App\Services;

use App\Models\User;
use App\Models\ShippingAddress;
use Modules\TaxModule\app\Traits\VatTaxManagement;

class OrderService
{
    use VatTaxManagement;

    public function __construct()
    {
    }

    public function getPOSOrderData(
        int|string $orderId,
        array $cart,
        float $amount,
        float $totalTaxAmount,
        float $paidAmount,
        string $paymentType,
        string $addedBy,
        int $userId,
        string $fulfillment = 'instant'
    ): array {
        $taxConfig = self::getTaxSystemType();

        // Delivery POS orders follow the normal delivery lifecycle.
        $isDelivery = $fulfillment === 'delivery';
        $isCashOnDelivery = $isDelivery && $paymentType === 'cash';

        $shippingAddress = null;
        $shippingAddressData = null;

        $billingAddress = null;
        $billingAddressData = null;

        $shippingResponsibility = null;

        /*
         * Get the selected POS customer directly from users table.
         */
        $customer = $userId != 0
            ? User::find($userId)
            : null;

        if ($customer) {

            /*
             * First try to use the customer's latest saved shipping address.
             */
            $shippingAddress = ShippingAddress::where(
                'customer_id',
                $customer->id
            )
                ->orderByDesc('id')
                ->first();

            if ($shippingAddress) {

                /*
                 * Customer has a saved shipping address.
                 */
                $shippingAddressData = $shippingAddress->toArray();

                /*
                 * POS billing address uses the same address by default.
                 */
                $billingAddress = $shippingAddress->id;
                $billingAddressData = $shippingAddress->toArray();

            } else {

                /*
                 * No row exists in shipping_addresses.
                 * Build shipping and billing information directly
                 * from the users table.
                 */
                $customerName = trim(
                    ($customer->f_name ?? '') . ' ' . ($customer->l_name ?? '')
                );

                /*
                 * If f_name/l_name are empty, use name as fallback.
                 */
                if ($customerName === '') {
                    $customerName = $customer->name ?? '';
                }

                $customerAddressData = [
                    'contact_person_name' => $customerName,

                    'phone' => $customer->phone ?? null,
                    'email' => $customer->email ?? null,

                    'address' => $customer->street_address ?? null,
                    'city' => $customer->city ?? null,
                    'zip' => $customer->zip ?? null,
                    'country' => $customer->country ?? null,

                    'house_no' => $customer->house_no ?? null,
                    'apartment_no' => $customer->apartment_no ?? null,
                ];

                $shippingAddressData = $customerAddressData;
                $billingAddressData = $customerAddressData;
            }
        }

        if ($isDelivery) {
            $shippingResponsibility = getWebConfig(
                name: 'shipping_method'
            );
        }

        return [
            'id' => $orderId,

            /*
             * Customer
             */
            'customer_id' => $userId,
            'customer_type' => 'customer',

            /*
             * Payment / order status
             */
            'payment_status' => $isCashOnDelivery
                ? 'unpaid'
                : 'paid',

            'order_status' =>'pending',

            /*
             * Seller / admin
             */
            'seller_id' => $addedBy == 'seller'
                ? auth('seller')->id()
                : auth('admin')->id(),

            'seller_is' => $addedBy,

            /*
             * Payment method
             */
            'payment_method' => $isCashOnDelivery
                ? 'cash_on_delivery'
                : $paymentType,

            'order_type' => 'POS',

            /*
             * Shipping
             */
            'shipping_address' => $shippingAddress?->id,
            'shipping_address_data' => $shippingAddressData,

            /*
             * Billing
             */
            'billing_address' => $billingAddress,
            'billing_address_data' => $billingAddressData,

            'shipping_responsibility' => $shippingResponsibility,

            /*
             * Verification
             */
            'verification_code' => $isDelivery
                ? rand(100000, 999999)
                : '0',

            'checked' => 1,

            /*
             * Tax
             */
            'total_tax_amount' => $totalTaxAmount,
            'tax_type' => $taxConfig['SystemTaxVatType'],
            'tax_model' => $taxConfig['is_included']
                ? 'include'
                : 'exclude',

            /*
             * Discounts
             */
            'extra_discount' => $cart['ext_discount'] ?? 0,
            'extra_discount_type' => $cart['ext_discount_type'] ?? null,

            'discount_amount' => $cart['coupon_discount'] ?? 0,
            'coupon_code' => $cart['coupon_code'] ?? null,

            'discount_type' => !empty($cart['coupon_code'])
                ? 'coupon_discount'
                : null,

            'coupon_discount_bearer' =>
                $cart['coupon_bearer'] ?? 'inhouse',

            /*
             * Amount
             */
            'order_amount' => currencyConverter(
                amount: $amount
            ),

            'init_order_amount' => currencyConverter(
                amount: $amount
            ),

            'paid_amount' => currencyConverter(
                amount: $paidAmount
            ),

            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function getCheckIsOrderOnlyDigital(object $order): bool
    {
        $isOrderOnlyDigital = true;

        if ($order->orderDetails) {
            foreach ($order->orderDetails as $detail) {

                $product = json_decode(
                    $detail->product_details
                );

                if (
                    isset($product->product_type) &&
                    $product->product_type == 'physical'
                ) {
                    $isOrderOnlyDigital = false;
                }
            }
        }

        return $isOrderOnlyDigital;
    }
}