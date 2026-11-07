<?php

namespace App\Http\Requests\Admin\Address;

use Illuminate\Foundation\Http\FormRequest;

/** Writing the bundled lists needs a tick: the preview is the place to read what would change. */
class AddressImportRequest extends FormRequest
{
    use TranslatesValidationMessages {
        messages as private genericMessages;
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['confirm' => ['accepted']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['confirm' => translate('address_import_confirm')];
    }

    /** @return array<string, string|array<string, string>> */
    public function messages(): array
    {
        return ['confirm.accepted' => translate('address_import_confirm_required')] + $this->genericMessages();
    }
}
