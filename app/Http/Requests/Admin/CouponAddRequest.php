<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CouponAddRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize():bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules():array
    {
        $data = [
            'coupon_type' => 'required',
            'title' => 'required',
            'code' => 'required|unique:coupons',
            'coupon_bearer' => 'required_if:coupon_type,discount_on_purchase,free_delivery',
            'seller_id' => 'required_if:coupon_type,discount_on_purchase,free_delivery',
            'customer_id' => 'required_if:coupon_type,discount_on_purchase,free_delivery',
            'limit' => 'required_if:coupon_type,discount_on_purchase,free_delivery',
            'discount_type' => 'required_if:coupon_type,discount_on_purchase,first_order',
            'discount' => 'required_if:coupon_type,discount_on_purchase,first_order',
            'min_purchase' => 'required',
            'start_date' => 'required',
            'expire_date' => 'required',
        ];

        if (isset($this['discount_type']) && $this['discount_type'] == 'percentage' && empty($this['max_discount'])) {
            $data['max_discount'] = 'required';
        }
        return $data;
    }

    /**
    * @return array
    * Get the validation error message
    */
    public function messages(): array
    {
        $data = [
            'coupon_bearer.required_if' => translate('coupon_bearer_is_required!'),
            'seller_id.required_if' => translate('select_vendor_is_required!'),
            'customer_id.required_if' => translate('select_customer_is_required!'),
            'limit.required_if' => translate('limit_for_same_user_is_required!'),
            'discount_type.required_if' => translate('discount_type_is_required!'),
            'discount.required_if' => translate('discount_amount_is_required!'),
            'min_purchase.required' => translate('minimum_purchase_is_required!'),
        ];

        if (isset($this['discount_type']) && $this['discount_type'] == 'percentage' && empty($this['max_discount'])) {
            $data['max_discount.required'] = translate('max_discount_amount_is_required!');
        }
        return $data;
    }

}
