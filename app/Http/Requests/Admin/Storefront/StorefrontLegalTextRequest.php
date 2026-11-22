<?php

namespace App\Http\Requests\Admin\Storefront;

use App\Services\Fulfillment\Support\HtmlSanitizer;
use App\Services\Storefront\StorefrontLegalPublisher;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What a person writes into one step of the legal text of a storefront. The text is HTML and is cleaned the way a supplier's product description is before it is kept (scripts,
 * styles, forms, frames and event handlers are removed), because the website shows it without escaping. Who takes the step is the admin who is signed in. An approval and a publication
 * carry the hash of the text as the page showed it, so that they bind the words that were read. What the publisher refuses on the substance (the author approving, a text still
 * being its template, a day in the past, a text that changed since it was read) is its business and is shown to the person in its own words.
 */
class StorefrontLegalTextRequest extends FormRequest
{
    /** A text of a legal document is at most this many characters. */
    private const MAXIMUM_TEXT_LENGTH = 1000000;

    private const MAXIMUM_REASON_LENGTH = 500;

    /** The sha256 of the words of a text, hex, as `body_hash` holds it. */
    private const BODY_HASH_RULES = ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/i'];

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
            'approve' => ['body_hash' => self::BODY_HASH_RULES],
            'publish' => [
                'body_hash' => self::BODY_HASH_RULES,
                'effective_from' => ['bail', 'nullable', 'date_format:Y-m-d H:i', $this->refuseADayAfterTheLastMoment(...)],
            ],
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

    /** The hash of the words the person read: the one the page showed, which the publisher compares with the text it locks. */
    public function expectedBodyHash(): string
    {
        return (string) $this->input('body_hash');
    }

    /** The day the text takes effect, read as UTC; none means now. */
    public function effectiveFrom(): ?CarbonImmutable
    {
        $day = trim((string) $this->input('effective_from'));

        return $day === '' ? null : CarbonImmutable::createFromFormat('!Y-m-d H:i', $day, 'UTC');
    }

    /** The database keeps a moment until the end of 2037: a later day is refused here, in words, as the publisher refuses it, instead of failing in the database. */
    private function refuseADayAfterTheLastMoment(string $attribute, mixed $day, Closure $fail): void
    {
        $lastMoment = CarbonImmutable::parse(StorefrontLegalPublisher::LATEST_EFFECTIVE_MOMENT_UTC, 'UTC');

        if ($this->effectiveFrom()->greaterThan($lastMoment)) {
            $fail(translate('the_day_is_too_late_the_latest_moment_a_text_can_take_effect_utc') . ': ' . $lastMoment->format('Y-m-d H:i:s'));
        }
    }
}
