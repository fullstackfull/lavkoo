<?php

namespace App\Http\Requests\Admin;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\TaxModule\app\Traits\VatTaxManagement;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $icon
 * @property int $parent_id
 * @property int $position
 * @property int $home_status
 * @property int $priority
 */
class CurrencyAddRequest extends FormRequest
{
    use VatTaxManagement;

    protected $stopOnFirstFailure = false;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $result = [
            'name' => 'required|string',
            'symbol' => 'required|string',
            'code' => 'required|string',
        ];

        $currencyModel = getWebConfig(name: 'currency_model');
        if ($currencyModel == 'multi_currency') {
            $result['exchange_rate'] = 'required';
        }

        return $result;
    }

    public function messages(): array
    {
        $result = [
            'name.required' => translate('Currency_name_is_required'),
            'symbol.required' => translate('Currency_symbol_is_required'),
            'code.required' => translate('Currency_code_is_required'),
        ];

        $currencyModel = getWebConfig(name: 'currency_model');
        if ($currencyModel == 'multi_currency') {
            $result['exchange_rate.required'] = translate('Currency_exchange_rate_is_required');
        }

        return $result;
    }
}
