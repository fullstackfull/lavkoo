<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;


/**
 * @property int $id
 * @property string $name
 * @property string $image
 * @property int $status
 */
class CompanyReliabilityUpdateRequest extends FormRequest
{
    protected $stopOnFirstFailure = false;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [];

        for ($i = 1; $i <= 4; $i++) {
            $rules["title_{$i}"] = ['required', 'string', 'max:40'];
            $rules["image_{$i}"] = ['sometimes', 'image', getFileUploadFormats(skip: '.svg', asRule: 'true'), 'max:'. getFileUploadMaxSize(unit: 'kb')];
        }

        return $rules;
    }


    public function messages(): array
    {
        $messages = [];
        for ($i = 1; $i <= 4; $i++) {
            $messages["title_{$i}.required"] = translate("The title for item {$i} is required.");
            $messages["image_{$i}.sometimes"] = translate("The image for item {$i} is required.");
            $messages["image_{$i}.mimes"] = translate("The image for item {$i} must be a file of type:". getFileUploadFormats('skip: .svg', asMessage: 'true'));
        }
        return $messages;
    }

}
