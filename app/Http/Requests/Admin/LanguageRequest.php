<?php

namespace App\Http\Requests\Admin;

use App\Traits\ResponseHandler;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
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

    /** The code names a directory of resources/lang and a file of public/json/admin/lang: letters, digits, hyphen and underscore, never a dot or a slash. */
    public const CODE_PATTERN = '[A-Za-z][A-Za-z0-9_-]{0,15}';

    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required',
            'code' => ['required', 'regex:/^' . self::CODE_PATTERN . '$/D'],
        ];
    }


    public function messages(): array
    {
        return [
            'name.required' => translate('Language_name_field_is_required!'),
            'code.required' => translate('Language_code_field_is_required!'),
            'code.regex' => translate('the_language_code_is_not_valid'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {

                $languages = collect(getWebConfig('language') ?? []);
                $existingNames = $languages->pluck('name')->toArray();
                $existingCodes = $languages->pluck('code')->toArray();

                // The route decides, not the `type` field: a request that leaves the field out was taken for an update, and an add that
                // reached the writer rewrote the files of a language that exists.
                if ($this->route()?->getActionMethod() === 'add') {
                    if (in_array($this['name'], $existingNames)) {
                        $validator->errors()->add(
                            'name', translate('The_language_name_must_be_unique') . '!'
                        );
                    }

                    if (in_array(strtolower((string)$this['code']), array_map('strtolower', $existingCodes), true) || File::isDirectory(base_path('resources/lang/' . $this['code']))) {
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
