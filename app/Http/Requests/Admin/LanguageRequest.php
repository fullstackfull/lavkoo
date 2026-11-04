<?php

namespace App\Http\Requests\Admin;

use App\Traits\ResponseHandler;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Class Language
 *
 * @property int $id
 * @property string $name
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @package App\Models
 */
class LanguageRequest extends FormRequest
{
    use ResponseHandler;
    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required',
            'code' => 'required',
        ];
    }


    public function messages(): array
    {
        return [
            'name.required' => translate('Language_name_field_is_required!'),
            'code.required' => translate('Language_code_field_is_required!'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {

                $languages = collect(getWebConfig('language') ?? []);
                $existingNames = $languages->pluck('name')->toArray();
                $existingCodes = $languages->pluck('code')->toArray();

                if ($this['type'] == 'add') {
                    if (in_array($this['name'], $existingNames)) {
                        $validator->errors()->add(
                            'name', translate('The_language_name_must_be_unique') . '!'
                        );
                    }

                    if (in_array($this['code'], $existingCodes)) {
                        $validator->errors()->add(
                            'code', translate('The_language_code_must_be_unique') . '!'
                        );
                    }
                } else {
                    if (in_array($this['name'], $existingNames) && $languages->where('name', $this['name'])->first()['code'] != $this['code']) {
                        $validator->errors()->add(
                            'name', translate('The_language_name_must_be_unique') . '!'
                        );
                    }
                }
            }
        ];
    }

}
