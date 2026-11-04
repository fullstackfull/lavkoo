<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;

class CouponRequest extends FormRequest
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
            'customer_id' => 'required_if:coupon_type,discount_on_purchase,free_delivery',
            'limit' => 'required_if:coupon_type,discount_on_purchase,free_delivery',
            'discount_type' => 'required_if:coupon_type,discount_on_purchase',
            'discount' => 'required_if:coupon_type,discount_on_purchase',
            'min_purchase' => 'required_if:coupon_type,discount_on_purchase',
            'code' => 'required|unique:coupons',
            'title' => 'required',
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
            'coupon_bearer.required_if' => translate('coupon_bearer_is_required').'!',
            'customer_id.required_if' => translate('customer_is_required').'!',
            'limit.required_if' => translate('limit_for_same_user_is_required').'!',
            'discount_type.required_if' => translate('discount_type_is_required').'!',
            'discount.required_if' => translate('discount_amount_is_required').'!',
            'min_purchase.required' => translate('minimum_purchase_is_required').'!',
        ];

        if (isset($this['discount_type']) && $this['discount_type'] == 'percentage' && empty($this['max_discount'])) {
            $data['max_discount.required'] = translate('max_discount_amount_is_required!');
        }
        return $data;
    }

}
