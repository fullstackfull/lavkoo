<?php

namespace App\Http\Controllers\Customer;

use App\Models\User;
use App\Utils\Helpers;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\ShippingAddress;
use App\Models\ShippingMethod;
use App\Models\CartShipping;
use App\Services\Marketplace\ShippingRateService;
use App\Traits\CommonTrait;
use App\Utils\CartManager;
use App\Utils\OrderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemController extends Controller
{
    use CommonTrait;

    /**
     * Fetch an address by id ONLY when it belongs to the current actor (logged-in customer or the
     * session's guest). Returns null otherwise so an update can never touch another user's row —
     * the address id arrives from client input (shipping_method_id / billing_method_id).
     */
    private function getOwnedAddress(int|string|null $addressId): ?ShippingAddress
    {
        if (!$addressId) {
            return null;
        }

        $query = ShippingAddress::where('id', $addressId);
        if (auth('customer')->check()) {
            $query->where('customer_id', auth('customer')->id())->where('is_guest', 0);
        } elseif (session()->has('guest_id')) {
            $query->where('customer_id', session('guest_id'))->where('is_guest', 1);
        } else {
            return null;
        }

        return $query->first();
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

    /*
     * default theme
     * @return json
     */
    public function getChooseShippingAddress(Request $request): JsonResponse
    {
        $zip_restrict_status = getWebConfig(name: 'delivery_zip_code_area_restriction');
        $country_restrict_status = getWebConfig(name: 'delivery_country_restriction');

        $physical_product = $request['physical_product'];
        $shipping = [];
        $billing = [];

        parse_str($request['shipping'], $shipping);
        parse_str($request['billing'], $billing);
        $is_guest = !auth('customer')->check();

        if (isset($shipping['save_address']) && $shipping['save_address'] == 'on') {

            if ($shipping['contact_person_name'] == null || $shipping['address'] == null || $shipping['city'] == null || $shipping['country'] == null || ($is_guest && $shipping['email'] == null)) {
                return response()->json([
                    'errors' => translate('Fill_all_required_fields_of_shipping_address')
                ], 403);
            }
            elseif ($country_restrict_status && !self::delivery_country_exist_check($shipping['country'])) {
                return response()->json([
                    'errors' => translate('Delivery_unavailable_in_this_country.')
                ], 403);
            }
            elseif ($zip_restrict_status && !self::delivery_zipcode_exist_check($shipping['zip'])) {
                return response()->json([
                    'errors' => translate('Delivery_unavailable_in_this_zip_code_area')
                ], 403);
            }

            $address_id = DB::table('shipping_addresses')->insertGetId([
                'customer_id' => auth('customer')->id() ?? ((session()->has('guest_id') ? session('guest_id'):0)),
                'is_guest' => auth('customer')->check() ? 0 : (session()->has('guest_id') ? 1:0),
                'contact_person_name' => $shipping['contact_person_name'],
                'address_type' => $shipping['address_type'],
                'address' => $shipping['address'],
                'city' => $shipping['city'],
                'zip' => $shipping['zip'],
                'country' => $shipping['country'],
                'phone' => $shipping['phone'],
                'email' => auth('customer')->check() ? null : $shipping['email'],
                'latitude' => $shipping['latitude'],
                'longitude' => $shipping['longitude'],
                'is_billing' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        }
        else if (isset($shipping['shipping_method_id']) && $shipping['shipping_method_id'] == 0) {

            if ($shipping['contact_person_name'] == null || $shipping['address'] == null || $shipping['city'] == null || $shipping['country'] == null || ($is_guest && $shipping['email'] == null)) {
                return response()->json([
                    'errors' => translate('Fill_all_required_fields_of_shipping/billing_address')
                ], 403);
            }
            elseif ($country_restrict_status && !self::delivery_country_exist_check($shipping['country'])) {
                return response()->json([
                    'errors' => translate('Delivery_unavailable_in_this_country')
                ], 403);
            }
            elseif ($zip_restrict_status && !self::delivery_zipcode_exist_check($shipping['zip'])) {
                return response()->json([
                    'errors' => translate('Delivery_unavailable_in_this_zip_code_area')
                ], 403);
            }

            $address_id = DB::table('shipping_addresses')->insertGetId([
                'customer_id' => auth('customer')->id() ?? ((session()->has('guest_id') ? session('guest_id'):0)),
                'is_guest' => auth('customer')->check() ? 0 : (session()->has('guest_id') ? 1:0),
                'contact_person_name' => $shipping['contact_person_name'],
                'address_type' => $shipping['address_type'],
                'address' => $shipping['address'],
                'city' => $shipping['city'],
                'zip' => $shipping['zip'],
                'country' => $shipping['country'],
                'phone' => $shipping['phone'],
                'email' => auth('customer')->check() ? null : $shipping['email'],
                'latitude' => $shipping['latitude'],
                'longitude' => $shipping['longitude'],
                'is_billing' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        else {
            if (isset($shipping['shipping_method_id'])) {
                $address = ShippingAddress::find($shipping['shipping_method_id']);
                if (!$address->country) {
                    return response()->json([
                        'errors' => translate('Please_update_country_and_zip_for_this_shipping_address')
                    ], 403);
                }
                elseif ($country_restrict_status && !self::delivery_country_exist_check($address->country)) {
                    return response()->json([
                        'errors' => translate('Delivery_unavailable_in_this_country')
                    ], 403);
                }
                elseif ($zip_restrict_status && !self::delivery_zipcode_exist_check($address->zip)) {
                    return response()->json([
                        'errors' => translate('Delivery_unavailable_in_this_zip_code_area')
                    ], 403);
                }
                $address_id = $shipping['shipping_method_id'];
            }else{
                $address_id =  0;
            }
        }

        if ($request->billing_addresss_same_shipping == 'false') {
            if (isset($billing['save_address_billing']) && $billing['save_address_billing'] == 'on') {

                if ($billing['billing_contact_person_name'] == null || $billing['billing_address'] == null || $billing['billing_city'] == null || $billing['billing_country'] == null || ($is_guest && $billing['billing_contact_email'] == null)) {
                    return response()->json([
                        'errors' => translate('Billing_address_is_currently_disabled') . '. ' . translate('To_purchase_digital_products,_please_include_a_physical_product_in_your_order') . '.',
                    ], 403);
                }
                elseif ($country_restrict_status && !self::delivery_country_exist_check($billing['billing_country'])) {
                    return response()->json([
                        'errors' => translate('Delivery_unavailable_in_this_country')
                    ], 403);
                }
                elseif ($zip_restrict_status && !self::delivery_zipcode_exist_check($billing['billing_zip'])) {
                    return response()->json([
                        'errors' => translate('Delivery_unavailable_in_this_zip_code_area')
                    ], 403);
                }

                $billing_address_id = DB::table('shipping_addresses')->insertGetId([
                    'customer_id' => auth('customer')->id() ?? ((session()->has('guest_id') ? session('guest_id'):0)),
                    'is_guest' => auth('customer')->check() ? 0 : (session()->has('guest_id') ? 1:0),
                    'contact_person_name' => $billing['billing_contact_person_name'],
                    'address_type' => $billing['billing_address_type'],
                    'address' => $billing['billing_address'],
                    'city' => $billing['billing_city'],
                    'zip' => $billing['billing_zip'],
                    'country' => $billing['billing_country'],
                    'phone' => $billing['billing_phone'],
                    'email' => auth('customer')->check() ? null : $billing['billing_contact_email'],
                    'latitude' => $billing['billing_latitude'],
                    'longitude' => $billing['billing_longitude'],
                    'is_billing' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);


            }
            elseif ($billing['billing_method_id'] == 0) {

                if ($billing['billing_contact_person_name'] == null || $billing['billing_address'] == null || $billing['billing_city'] == null || $billing['billing_country'] == null || ($is_guest && $billing['billing_contact_email'] == null)) {
                    return response()->json([
                        'errors' => translate('Fill_all_required_fields_of_billing_address')
                    ], 403);
                }
                elseif ($country_restrict_status && !self::delivery_country_exist_check($billing['billing_country'])) {
                    return response()->json([
                        'errors' => translate('Delivery_unavailable_in_this_country')
                    ], 403);
                }
                elseif ($zip_restrict_status && !self::delivery_zipcode_exist_check($billing['billing_zip'])) {
                    return response()->json([
                        'errors' => translate('Delivery_unavailable_in_this_zip_code_area')
                    ], 403);
                }

                $billing_address_id = DB::table('shipping_addresses')->insertGetId([
                    'customer_id' => auth('customer')->id() ?? ((session()->has('guest_id') ? session('guest_id'):0)),
                    'is_guest' => auth('customer')->check() ? 0 : (session()->has('guest_id') ? 1:0),
                    'contact_person_name' => $billing['billing_contact_person_name'],
                    'address_type' => $billing['billing_address_type'],
                    'address' => $billing['billing_address'],
                    'city' => $billing['billing_city'],
                    'zip' => $billing['billing_zip'],
                    'country' => $billing['billing_country'],
                    'phone' => $billing['billing_phone'],
                    'email' => auth('customer')->check() ? null : $billing['billing_contact_email'],
                    'latitude' => $billing['billing_latitude'],
                    'longitude' => $billing['billing_longitude'],
                    'is_billing' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            else {
                $address = ShippingAddress::find($billing['billing_method_id']);
                if ($physical_product == 'yes') {
                    if (!$address->country) {
                        return response()->json([
                            'errors' => translate('Update_country_and_zip_for_this_billing_address')
                        ], 403);
                    }
                    elseif ($country_restrict_status && !self::delivery_country_exist_check($address->country)) {
                        return response()->json([
                            'errors' => translate('Delivery_unavailable_in_this_country')
                        ], 403);
                    }
                    elseif ($zip_restrict_status && !self::delivery_zipcode_exist_check($address->zip)) {
                        return response()->json([
                            'errors' => translate('Delivery_unavailable_in_this_zip_code_area')
                        ], 403);
                    }
                }
                $billing_address_id = $billing['billing_method_id'];
            }
        }
        else {
            $billing_address_id = $address_id;
        }

        session()->put('address_id', $address_id);
        session()->put('billing_address_id', $billing_address_id);

        return response()->json([], 200);
    }


    /**
     * Unsaved checkout addresses are stored anonymously (customer_id 0) so they
     * never appear in an address book — but each proceed used to insert a fresh
     * row. An identical row is reused; null fields need whereNull, since a
     * where() against null compiles to "= null" and matches nothing.
     */
    private function reuseOrCreateAnonymousAddress(array $fields): int
    {
        $query = ShippingAddress::query();
        foreach ($fields as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }
        return (int) ($query->value('id') ?? ShippingAddress::insertGetId($fields));
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
        $addressId = $shipping['shipping_method_id'] ?? 0;

        if (isset($shipping['shipping_method_id'])) {
            if ($shipping['contact_person_name'] == null || !isset($shipping['address_type']) || $shipping['address'] == null || $shipping['city'] == null || !isset($shipping['country']) || $shipping['country'] == null || $shipping['phone'] == null || ($isGuestCustomer && $shipping['email'] == null)) {
                return response()->json([
                    'errors' => translate('Fill_all_required_fields_of_shipping_address')
                ], 403);
            } elseif ($countryRestrictStatus && !self::delivery_country_exist_check($shipping['country'])) {
                return response()->json([
                    'errors' => translate('Delivery_unavailable_in_this_country.')
                ], 403);
            } elseif ($zipRestrictStatus && !self::delivery_zipcode_exist_check($shipping['zip'])) {
                return response()->json([
                    'errors' => translate('Delivery_unavailable_in_this_zip_code_area')
                ], 403);
            }
        }

        if (isset($shipping['save_address']) && $shipping['save_address'] == 'on') {
            $addressId = ShippingAddress::insertGetId([
                'customer_id' => auth('customer')->id() ?? ((session()->has('guest_id') ? session('guest_id') : 0)),
                'is_guest' => auth('customer')->check() ? 0 : (session()->has('guest_id') ? 1 : 0),
                'contact_person_name' => $shipping['contact_person_name'],
                'address_type' => $shipping['address_type'],
                'address' => $shipping['address'],
                'city' => $shipping['city'],
                'zip' => $shipping['zip'],
                'country' => $shipping['country'],
                'phone' => $shipping['phone'],
                'latitude' => $shipping['latitude'] ?? "",
                'longitude' => $shipping['longitude'] ?? "",
                'email' => auth('customer')->check() ? null : $shipping['email'],
                'is_billing' => 0,
            ]);

        } elseif (isset($shipping['update_address']) && $shipping['update_address'] == 'on') {
            $getShipping = $this->getOwnedAddress($addressId);
            if (!$getShipping) {
                return response()->json([
                    'errors' => translate('Address_not_found')
                ], 403);
            }
            $getShipping->contact_person_name = $shipping['contact_person_name'];
            $getShipping->address_type = $shipping['address_type'];
            $getShipping->address = $shipping['address'];
            $getShipping->city = $shipping['city'];
            $getShipping->zip = $shipping['zip'];
            $getShipping->country = $shipping['country'];
            $getShipping->phone = $shipping['phone'];
            $getShipping->latitude = $shipping['latitude'] ?? "";
            $getShipping->longitude = $shipping['longitude'] ?? "";
            $getShipping->save();

        } elseif (isset($shipping['shipping_method_id']) && !isset($shipping['update_address']) && !isset($shipping['save_address'])) {
            // Unsaved addresses are stored anonymously so they stay out of the
            // address book — but every proceed used to mint a fresh row, growing
            // the table forever. An identical anonymous row is reused instead.
            $shippingFields = [
                'customer_id' => auth('customer')->check() ? 0 : ((session()->has('guest_id') ? session('guest_id') : 0)),
                'is_guest' => auth('customer')->check() ? 0 : (session()->has('guest_id') ? 1 : 0),
                'contact_person_name' => $shipping['contact_person_name'],
                'address_type' => $shipping['address_type'],
                'address' => $shipping['address'],
                'city' => $shipping['city'],
                'zip' => $shipping['zip'],
                'country' => $shipping['country'],
                'phone' => $shipping['phone'],
                'email' => auth('customer')->check() ? null : $shipping['email'],
                'latitude' => $shipping['latitude'] ?? '',
                'longitude' => $shipping['longitude'] ?? '',
                'is_billing' => 0,
            ];
            $addressId = $this->reuseOrCreateAnonymousAddress($shippingFields);
        }
        // Shipping End

        // Billing Start
        $billingAddressId = $addressId ?? 0;
        if ($request['billing_addresss_same_shipping'] == 'false' && isset($billing['billing_method_id']) && $billingInputByCustomer) {
            $billingAddressId = $billing['billing_method_id'];
            if ($billing['billing_contact_person_name'] == null || !isset($billing['billing_address_type']) || !isset($billing['billing_address']) || $billing['billing_address'] == null || $billing['billing_city'] == null || !isset($billing['billing_country']) || $billing['billing_country'] == null || $billing['billing_phone'] == null || ($isGuestCustomer && $billing['billing_contact_email'] == null)) {
                return response()->json([
                    'errors' => translate('Fill_all_required_fields_of_billing_address')
                ], 403);
            } elseif ($countryRestrictStatus && !self::delivery_country_exist_check($billing['billing_country'])) {
                return response()->json([
                    'errors' => translate('Delivery_unavailable_in_this_country')
                ], 403);
            } elseif ($zipRestrictStatus && !self::delivery_zipcode_exist_check($billing['billing_zip'])) {
                return response()->json([
                    'errors' => translate('Delivery_unavailable_in_this_zip_code_area')
                ], 403);
            }

            if (isset($billing['save_address_billing']) && $billing['save_address_billing'] == 'on') {
                $billingAddressId = ShippingAddress::insertGetId([
                    'customer_id' => auth('customer')->id() ?? ((session()->has('guest_id') ? session('guest_id') : 0)),
                    'is_guest' => auth('customer')->check() ? 0 : (session()->has('guest_id') ? 1 : 0),
                    'contact_person_name' => $billing['billing_contact_person_name'],
                    'address_type' => $billing['billing_address_type'],
                    'address' => $billing['billing_address'],
                    'city' => $billing['billing_city'],
                    'zip' => $billing['billing_zip'],
                    'country' => $billing['billing_country'],
                    'phone' => $billing['billing_phone'],
                    'email' => auth('customer')->check() ? null : $billing['billing_contact_email'],
                    'latitude' => $billing['billing_latitude'] ?? '',
                    'longitude' => $billing['billing_longitude'] ?? '',
                    'is_billing' => 1,
                ]);
            } elseif (isset($billing['update_billing_address']) && $billing['update_billing_address'] == 'on') {
                $getBilling = $this->getOwnedAddress($billingAddressId);
                if (!$getBilling) {
                    return response()->json([
                        'errors' => translate('Address_not_found')
                    ], 403);
                }
                $getBilling->contact_person_name = $billing['billing_contact_person_name'];
                $getBilling->address_type = $billing['billing_address_type'];
                $getBilling->address = $billing['billing_address'];
                $getBilling->city = $billing['billing_city'];
                $getBilling->zip = $billing['billing_zip'];
                $getBilling->country = $billing['billing_country'];
                $getBilling->phone = $billing['billing_phone'];
                $getBilling->latitude = $billing['billing_latitude'] ?? '';
                $getBilling->longitude = $billing['billing_longitude'] ?? '';
                $getBilling->save();
            } elseif (!isset($billing['update_billing_address']) && !isset($billing['save_address_billing'])) {
                $billingFields = [
                    'customer_id' => auth('customer')->check() ? 0 : ((session()->has('guest_id') ? session('guest_id') : 0)),
                    'is_guest' => auth('customer')->check() ? 0 : (session()->has('guest_id') ? 1 : 0),
                    'contact_person_name' => $billing['billing_contact_person_name'],
                    'address_type' => $billing['billing_address_type'],
                    'address' => $billing['billing_address'],
                    'city' => $billing['billing_city'],
                    'zip' => $billing['billing_zip'],
                    'country' => $billing['billing_country'],
                    'phone' => $billing['billing_phone'],
                    'email' => auth('customer')->check() ? null : $billing['billing_contact_email'],
                    'latitude' => $billing['billing_latitude'] ?? '',
                    'longitude' => $billing['billing_longitude'] ?? '',
                    'is_billing' => 1,
                ];
                $billingAddressId = $this->reuseOrCreateAnonymousAddress($billingFields);
            }
        } elseif ($request['billing_addresss_same_shipping'] == 'false' && !isset($billing['billing_method_id']) && $physicalProduct != 'yes') {
            return response()->json([
                'errors' => translate('Fill_all_required_fields_of_billing_address')
            ], 403);
        }

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
