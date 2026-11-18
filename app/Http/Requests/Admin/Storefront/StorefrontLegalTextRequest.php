<?php

namespace App\Http\Requests\Admin\Storefront;

use App\Services\Fulfillment\Support\HtmlSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What a person writes into one step of the legal text of a storefront. The text is HTML and is cleaned the way a supplier's product description is before it is kept (scripts,
 * styles, forms, frames and event handlers are removed), because the website shows it without escaping. Who takes the step is the admin who is signed in. What the publisher
 * refuses on the substance (the author approving, a text still being its template, a day in the past) is its business and is shown to the person in its own words.
 */
class StorefrontLegalTextRequest extends FormRequest
{
    /** A text of a legal document is at most this many characters. */
    private const MAXIMUM_TEXT_LENGTH = 1000000;

    private const MAXIMUM_REASON_LENGTH = 500;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return match ($this->route()?->getActionMethod()) {
            'draft' => [
                'document_type' => ['required', 'string', 'regex:/^[a-z0-9_]{2,32}$/'],
                'locale' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
                'template' => ['required', 'string', 'max:' . self::MAXIMUM_TEXT_LENGTH],
            ],
            'edit' => ['body' => ['required', 'string', 'max:' . self::MAXIMUM_TEXT_LENGTH]],
            'publish' => ['effective_from' => ['nullable', 'date_format:Y-m-d H:i']],
            'revoke', 'discard' => ['reason' => ['required', 'string', 'max:' . self::MAXIMUM_REASON_LENGTH]],
            default => [],
        };
    }

    /** The admin who takes the step: the one who is signed in. */
    public function admin(): int
    {
        return (int) auth('admin')->id();
    }

    public function documentType(): string
    {
        return strtolower(trim((string) $this->input('document_type')));
    }

    public function locale(): string
    {
        return strtolower(trim((string) $this->input('locale')));
    }

    public function template(): string
    {
        return HtmlSanitizer::clean((string) $this->input('template'));
    }

    public function body(): string
    {
        return HtmlSanitizer::clean((string) $this->input('body'));
    }

    public function reason(): string
    {
        return (string) $this->input('reason');
    }

    /** The day the text takes effect, read as UTC; none means now. */
    public function effectiveFrom(): ?CarbonImmutable
    {
        $day = trim((string) $this->input('effective_from'));

        return $day === '' ? null : CarbonImmutable::createFromFormat('!Y-m-d H:i', $day, 'UTC');
    }
}
