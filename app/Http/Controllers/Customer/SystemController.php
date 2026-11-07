<?php

namespace App\Http\Controllers\Customer;

use App\Models\User;
use App\Utils\Helpers;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\ShippingAddress;
use App\Models\ShippingMethod;
use App\Models\CartShipping;
use App\Services\Address\StorefrontAddressService;
use App\Services\Marketplace\ShippingRateService;
use App\Traits\CommonTrait;
use App\Utils\CartManager;
use App\Utils\OrderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SystemController extends Controller
{
    use CommonTrait;

    public function __construct(private readonly StorefrontAddressService $storefrontAddresses)
    {
    }

    public function setPaymentMethod($name): JsonResponse
    {
        if (auth('customer')->check() || session()->has('mobile_app_payment_customer_id')) {
            session()->put('payment_method', $name);
            return response()->json(['status' => 1]);
        }
        return response()->json(['status' => 0]);
    }

    public function setShippingMethod(Request $request): JsonResponse
    {
        if ($request['cart_group_id'] == 'all_cart_group') {
            foreach (CartManager::get_cart_group_ids() as $groupId) {
                $request['cart_group_id'] = $groupId;
                self::insertIntoCartShipping($request);
            }
        } else {
            self::insertIntoCartShipping($request);
        }
        return response()->json(['status' => 1]);
    }

    public static function insertIntoCartShipping($request): void
    {
        $shipping = CartShipping::where(['cart_group_id' => $request['cart_group_id']])->first();
        if (isset($shipping) == false) {
            $shipping = new CartShipping();
        }
        $shipping['cart_group_id'] = $request['cart_group_id'];
        $shipping['shipping_method_id'] = $request['id'];
        $shipping['shipping_cost'] = self::zoneShippingCost($request['cart_group_id'], (float) ShippingMethod::find($request['id'])->cost);
        $shipping->save();

        if (session('coupon_code') && session('coupon_discount')) {
            $result = OrderManager::getTotalCouponAmount(request: $request, couponCode: session('coupon_code'));
            if (!$result['status']) {
                session()->forget('coupon_code');
                session()->forget('coupon_type');
                session()->forget('coupon_bearer');
                session()->forget('coupon_discount');
                session()->forget('coupon_seller_id');
            }
        }
    }

    /**
     * The shipping cost to charge for a cart group — the chosen method's cost, unless destination-based
     * (zone) shipping is switched on and a zone serves the customer's selected address (Phase 3, Stage C).
     *
     * Off by default: with the `zone_wise_shipping` setting off (the platform default) this returns the
     * method cost unchanged, so checkout behaves exactly as before. The resolved cost lands on the same
     * CartShipping row, so order totals, tax on shipping and free-delivery are computed downstream exactly
     * as they were. Only web checkout consults this — the mobile API keeps method-based shipping, because
     * its shipping step is stateless and has no bound destination to resolve a zone against. Weight is 0
     * (the catalogue has no per-product weight), so only a zone's flat base cost and free-over threshold
     * apply; where no zone matches the destination, the chosen method's cost is used unchanged.
     */
    private static function zoneShippingCost(string|int $cartGroupId, float $methodCost): float
    {
        if (!getWebConfig(name: 'zone_wise_shipping')) {
            return $methodCost;
        }

        $address = session('address_id') ? ShippingAddress::find(session('address_id')) : null;
        if (!$address) {
            return $methodCost;
        }

        $orderValue = (float) Cart::where('cart_group_id', $cartGroupId)->get()
            ->sum(fn ($item) => ((float) $item->price - (float) $item->discount) * (int) $item->quantity);

        return (new ShippingRateService())->checkoutCost($methodCost, $address->country, 0.0, $orderValue, $address->state);
    }

    /**
     * The older name of the address step. Nothing on the site posts to it any more, but the route stays; it answers exactly like
     * getChooseShippingAddressOther, so it cannot be used to skip the address rules or to name an address that is not the caller's.
     * Its URI is excluded from the CSRF check, so a request without the session's token is refused here.
     */
    public function getChooseShippingAddress(Request $request): JsonResponse
    {
        abort_unless(hash_equals($request->session()->token(), (string) ($request->input('_token') ?? $request->header('X-CSRF-TOKEN'))), 419);

        return $this->getChooseShippingAddressOther($request);
    }

    public function getChooseShippingAddressOther(Request $request): JsonResponse
    {
        $billingInputByCustomer = getWebConfig(name: 'billing_input_by_customer');
        $productTypes = CartManager::getCartListQuery(type: 'checked')->pluck('product_type')->toArray() ?? ['physical'];
        if (!$billingInputByCustomer && !in_array('physical', $productTypes)) {
            return response()->json([
                'errors' => translate('Billing_address_is_currently_disabled') . '. ' . translate('To_purchase_digital_products,_please_include_a_physical_product_in_your_order') . '.',
            ], 403);
        }

        $shipping = [];
        $billing = [];
        parse_str($request['shipping'], $shipping);
        parse_str($request['billing'], $billing);

        if (!auth('customer')->check()) {

            if (empty($shipping['email'])) {
                return response()->json([
                    'errors' => translate('Shipping email is required.'),
                ], 403);
            }

            if (!filter_var($shipping['email'], FILTER_VALIDATE_EMAIL)) {
                return response()->json([
                    'errors' => translate('Shipping email must be a valid email address.'),
                ], 403);
            }

            if ($billingInputByCustomer && $request->billing_addresss_same_shipping == 'false') {

                if (empty($billing['billing_contact_email'])) {
                    return response()->json([
                        'errors' => translate('Billing email is required.'),
                    ], 403);
                }

                if (!filter_var($billing['billing_contact_email'], FILTER_VALIDATE_EMAIL)) {
                    return response()->json([
                        'errors' => translate('Billing email must be a valid email address.'),
                    ], 403);
                }
            }
        }

        if (isset($shipping['phone'])) {
            $shippingPhoneValue = preg_replace('/[^0-9]/', '', $shipping['phone']);
            $shippingPhoneLength = strlen($shippingPhoneValue);
            if ($shippingPhoneLength < 4) {
                return response()->json([
                    'errors' => translate('The_phone_number_must_be_at_least_4_characters')
                ], 403);
            }
            if ($shippingPhoneLength > 20) {
                return response()->json([
                    'errors' => translate('The_phone_number_may_not_be_greater_than_20_characters')
                ], 403);
            }
        }

        if ($request['billing_addresss_same_shipping'] == 'false' && isset($billing['billing_phone'])) {
            $billingPhoneValue = preg_replace('/[^0-9]/', '', $billing['billing_phone']);
            $billingPhoneLength = strlen($billingPhoneValue);
            if ($billingPhoneLength < 4) {
                return response()->json([
                    'errors' => translate('The_phone_number_must_be_at_least_4_characters')
                ], 403);
            }

            if ($billingPhoneLength > 20) {
                return response()->json([
                    'errors' => translate('The_phone_number_may_not_be_greater_than_20_characters')
                ], 403);
            }
        }

        $physicalProduct = $request['physical_product'];
        $zipRestrictStatus = getWebConfig(name: 'delivery_zip_code_area_restriction');
        $countryRestrictStatus = getWebConfig(name: 'delivery_country_restriction');
        $isGuestCustomer = !auth('customer')->check();

        // Shipping start
        $addressId = 0;
        if (isset($shipping['shipping_method_id'])) {
            if ($this->storefrontAddresses->isIncomplete(posted: $shipping, billing: false, guest: $isGuestCustomer)) {
                return response()->json([
                    'errors' => translate('Fill_all_required_fields_of_shipping_address')
                ], 403);
            } elseif ($countryRestrictStatus && !self::delivery_country_exist_check($shipping['country'])) {
                return response()->json([
                    'errors' => translate('Delivery_unavailable_in_this_country.')
                ], 403);
            } elseif ($zipRestrictStatus && !self::delivery_zipcode_exist_check($shipping['zip'] ?? null)) {
                return response()->json([
                    'errors' => translate('Delivery_unavailable_in_this_zip_code_area')
                ], 403);
            }

            try {
                $addressId = $this->storefrontAddresses->saveFromCheckout(posted: $shipping, billing: false);
            } catch (ValidationException $exception) {
                return $this->refuseAddress(exception: $exception, fieldPrefix: '');
            }
        }
        // Shipping End

        // Billing Start
        $billingAddressId = $addressId;
        if ($request['billing_addresss_same_shipping'] == 'false' && isset($billing['billing_method_id']) && $billingInputByCustomer) {
            if ($this->storefrontAddresses->isIncomplete(posted: $billing, billing: true, guest: $isGuestCustomer)) {
                return response()->json([
                    'errors' => translate('Fill_all_required_fields_of_billing_address')
                ], 403);
            } elseif ($countryRestrictStatus && !self::delivery_country_exist_check($billing['billing_country'])) {
                return response()->json([
                    'errors' => translate('Delivery_unavailable_in_this_country')
                ], 403);
            } elseif ($zipRestrictStatus && !self::delivery_zipcode_exist_check($billing['billing_zip'] ?? null)) {
                return response()->json([
                    'errors' => translate('Delivery_unavailable_in_this_zip_code_area')
                ], 403);
            }

            try {
                $billingAddressId = $this->storefrontAddresses->saveFromCheckout(posted: $billing, billing: true);
            } catch (ValidationException $exception) {
                return $this->refuseAddress(exception: $exception, fieldPrefix: 'billing_');
            }
        } elseif ($request['billing_addresss_same_shipping'] == 'false' && !isset($billing['billing_method_id']) && $physicalProduct != 'yes') {
            return response()->json([
                'errors' => translate('Fill_all_required_fields_of_billing_address')
            ], 403);
        }
        // Billing End

        session()->put('address_id', $addressId);
        session()->put('billing_address_id', $billingAddressId);

        if ($request['is_check_create_account'] && $isGuestCustomer) {
            if (empty($request['customer_password']) || empty($request['customer_confirm_password'])) {
                return response()->json([
                    'errors' => translate('The_password_or_confirm_password_can_not_be_empty')
                ], 403);
            }
            if ($request['customer_password'] != $request['customer_confirm_password']) {
                return response()->json([
                    'errors' => translate('The_password_and_confirm_password_must_match')
                ], 403);
            }
            if (strlen($request['customer_password']) < 7 || strlen($request['customer_confirm_password']) < 7) {
                return response()->json([
                    'errors' => translate('The_password_must_be_at_least_8_characters')
                ], 403);
            }
            if ($request['shipping']) {
                $newCustomerAddress = [
                    'name' => $shipping['contact_person_name'],
                    'email' => $shipping['email'],
                    'phone' => $shipping['phone'],
                    'password' => $request['customer_password'],
                ];
            } else {
                $newCustomerAddress = [
                    'name' => $billing['billing_contact_person_name'],
                    'email' => $billing['billing_contact_email'],
                    'phone' => $billing['billing_phone'],
                    'password' => $request['customer_password'],
                ];
            }

            if (User::where(['email' => $newCustomerAddress['email']])->orWhere(['phone' => $newCustomerAddress['phone']])->first()) {
                return response()->json(['errors' => translate('Already_registered')], 403);
            }else{
                $newCustomerRegister = self::getRegisterNewCustomer(request: $request, address: $newCustomerAddress);
                session()->put('newCustomerRegister', $newCustomerRegister);
            }
        } else {
            session()->forget('newCustomerRegister');
            session()->forget('newRegisterCustomerInfo');
        }

        if (!session('address_id') && !session('billing_address_id')) {
            return response()->json([
                'errors' => translate('Please_update_address_information')
            ], 403);
        }

        return response()->json([], 200);
    }

    /**
     * The checkout script prints `errors` as one message; `fields` names what to correct, field by field, and `prefix` is what the form
     * puts in front of those names (the billing form's inputs start with `billing_`).
     */
    private function refuseAddress(ValidationException $exception, string $fieldPrefix): JsonResponse
    {
        return response()->json([
            'errors' => $exception->validator->errors()->first(),
            'fields' => $exception->errors(),
            'prefix' => $fieldPrefix,
        ], 403);
    }

    function getRegisterNewCustomer($request, $address): array
    {
        return [
            'name' => $address['name'],
            'f_name' => $address['name'],
            'l_name' => '',
            'email' => $address['email'],
            'phone' => $address['phone'],
            'is_active' => 1,
            'password' => $address['password'],
            'referral_code' => Helpers::generate_referer_code(),
            'shipping_id' => session('address_id'),
            'billing_id' => session('billing_address_id'),
        ];
    }

}
