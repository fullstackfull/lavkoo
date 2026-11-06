<?php

namespace App\Http\Requests\Admin\OperationalCosts;

use App\Enums\OperationalCosts\CostComponent;
use App\Http\Controllers\Admin\OperationalCosts\DecimalRules;
use App\Services\OperationalCosts\ContractService;
use App\Services\OperationalCosts\Invoices\InvoiceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** An invoice typed in by hand: its header, and its lines (a table of rows, and lines pasted from a spreadsheet, read by InvoiceLineParser). */
class InvoiceRequest extends FormRequest
{
    /** The columns of a line that say something; the component alone does not, because a select always has one chosen. */
    private const LINE_CONTENT = ['description', 'amount', 'quantity', 'external_reference', 'service_date', 'product_id', 'variant_key'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'provider_code' => is_string($this->input('provider_code')) ? strtolower(trim($this->input('provider_code'))) : null,
            'currency' => is_string($this->input('currency')) ? strtoupper(trim($this->input('currency'))) : null,
            'items' => $this->linesWithContent(),
        ], static fn ($value): bool => $value !== null));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(InvoiceService::KINDS)],
            'party_type' => ['required', Rule::in(ContractService::PARTY_TYPES)],
            'provider_code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_.-]+$/'],
            'party_name' => ['required', 'string', 'max:160'],
            'invoice_number' => ['required', 'string', 'max:120'],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'period_start' => ['nullable', 'date_format:Y-m-d'],
            'period_end' => ['nullable', 'date_format:Y-m-d', ...(filled($this->input('period_start')) ? ['after_or_equal:period_start'] : [])],
            'currency' => ['required', 'regex:/^[A-Z]{3,8}$/'],
            'subtotal' => DecimalRules::signedAmount(),
            'tax_total' => DecimalRules::signedAmount(),
            'total' => DecimalRules::signedAmount(required: true),
            'inventory_location_id' => ['nullable', 'integer', Rule::exists('inventory_locations', 'id'), Rule::requiredIf($this->input('kind') === 'purchase')],
            'seller_id' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'pasted_lines' => ['nullable', 'string', 'max:200000'],
            'items' => ['nullable', 'array', 'max:200'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.cost_component' => ['required', Rule::enum(CostComponent::class)],
            'items.*.amount' => DecimalRules::signedAmount(required: true),
            'items.*.quantity' => DecimalRules::amount(),
            'items.*.external_reference' => ['nullable', 'string', 'max:191'],
            'items.*.service_date' => ['nullable', 'date_format:Y-m-d'],
            'items.*.product_id' => ['nullable', 'integer', 'min:1', Rule::exists('products', 'id')],
            'items.*.variant_key' => ['nullable', 'string', 'max:191'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'kind' => translate('invoice_kind'),
            'party_type' => translate('kind_of_company'),
            'provider_code' => translate('company_code'),
            'party_name' => translate('company_name'),
            'invoice_number' => translate('invoice_number'),
            'invoice_date' => translate('invoice_date'),
            'period_start' => translate('period_start'),
            'period_end' => translate('period_end'),
            'currency' => translate('currency'),
            'subtotal' => translate('subtotal'),
            'tax_total' => translate('tax_total'),
            'total' => translate('total'),
            'inventory_location_id' => translate('location'),
            'seller_id' => translate('seller'),
            'notes' => translate('notes'),
            'pasted_lines' => translate('pasted_lines'),
            'items.*.description' => translate('description'),
            'items.*.cost_component' => translate('cost_component'),
            'items.*.amount' => translate('amount'),
            'items.*.quantity' => translate('quantity'),
            'items.*.external_reference' => translate('line_reference'),
            'items.*.service_date' => translate('service_date'),
            'items.*.product_id' => translate('product'),
            'items.*.variant_key' => translate('variant_key'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'regex' => translate('enter_a_valid_value_for_this_field'),
            'items.*.amount.regex' => translate('enter_a_plain_number_such_as_12_50'),
            'total.regex' => translate('enter_a_plain_number_such_as_12_50'),
            'subtotal.regex' => translate('enter_a_plain_number_such_as_12_50'),
            'tax_total.regex' => translate('enter_a_plain_number_such_as_12_50'),
            'items.*.quantity.regex' => translate('enter_a_plain_number_such_as_12_50'),
        ];
    }

    /**
     * The typed lines, without the rows left blank, in the shape InvoiceService::create() takes.
     *
     * @return list<array<string, mixed>>
     */
    public function typedLines(): array
    {
        return array_map(static fn (array $line): array => array_filter($line, static fn ($value): bool => $value !== null), (array) ($this->validated()['items'] ?? []));
    }

    /** @return list<array<string, mixed>>|null null when the request carries no table of lines at all */
    private function linesWithContent(): ?array
    {
        $rows = $this->input('items');
        if (!is_array($rows)) {
            return null;
        }

        return array_values(array_filter($rows, static function ($row): bool {
            foreach (self::LINE_CONTENT as $column) {
                if (is_array($row) && isset($row[$column]) && trim((string) $row[$column]) !== '') {
                    return true;
                }
            }

            return false;
        }));
    }
}
