<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DeepLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => 'nullable|string',

            // ANDROID
            'android_package_name' => [
                'nullable',
                'string',
                'max:255',
                'required_with:android_sha256_fingerprint,playstore_redirect_url',
            ],

            'android_sha256_fingerprint' => [
                'nullable',
                'string',
                'max:255',
                'required_with:android_package_name,playstore_redirect_url',
            ],

            'playstore_redirect_url' => [
                'nullable',
                'string',
                'max:255',
                'required_with:android_package_name,android_sha256_fingerprint',
            ],

            // IOS
            'ios_bundle_id' => [
                'nullable',
                'string',
                'max:255',
                'required_with:ios_team_id,app_store_redirect_url',
            ],

            'ios_team_id' => [
                'nullable',
                'string',
                'max:255',
                'required_with:ios_bundle_id,app_store_redirect_url',
            ],

            'app_store_redirect_url' => [
                'nullable',
                'string',
                'max:255',
                'required_with:ios_bundle_id,ios_team_id',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'android_package_name.required_with' => translate('All Android fields must be filled if one is provided.'),
            'android_sha256_fingerprint.required_with' => translate('All Android fields must be filled if one is provided.'),
            'playstore_redirect_url.required_with' => translate('All Android fields must be filled if one is provided.'),

            'ios_bundle_id.required_with' => translate('All iOS fields must be filled if one is provided.'),
            'ios_team_id.required_with' => translate('All iOS fields must be filled if one is provided.'),
            'app_store_redirect_url.required_with' => translate('All iOS fields must be filled if one is provided.'),
        ];
    }
}
