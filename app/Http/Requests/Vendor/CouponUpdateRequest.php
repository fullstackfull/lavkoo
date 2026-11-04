<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CouponUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $data = [
            'coupon_type' => 'required',
            'customer_id' => 'required_if:coupon_type,discount_on_purchase,free_delivery',
            'limit' => 'required_if:coupon_type,discount_on_purchase,free_delivery',
            'discount_type' => 'required_if:coupon_type,discount_on_purchase,first_order',
            'discount' => 'required_if:coupon_type,discount_on_purchase,first_order',
            'min_purchase' => 'required',
            'title' => 'required',
            'start_date' => 'required',
            'expire_date' => 'required',
            'code' => [
                'required',
                Rule::unique('coupons', 'code')->ignore($this->route('id')),
            ],
        ];

        if (isset($this['discount_type']) && $this['discount_type'] == 'percentage' && empty($this['max_discount'])) {
            $data['max_discount'] = 'required';
        }
        return $data;
    }

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
