<?php

namespace App\Http\Requests\Admin\Storefront;

use App\Services\Storefront\StorefrontDecisionRecord;
use App\Services\Storefront\StorefrontLifecycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The words a person writes beside a change of the lifecycle of a storefront. The person who makes the change is never one of them: it is the admin who is signed in, so a
 * name cannot be typed in. What the service refuses on the substance (a storefront that is not ready, a stop with no mode, an end that is not in the future) is its business
 * and is shown to the person as the service words it.
 */
class StorefrontLifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $reason = ['required', 'string', 'max:' . StorefrontDecisionRecord::MAXIMUM_TEXT_LENGTH];

        return match ($this->route()?->getActionMethod()) {
            'activate' => [
                'reason' => $reason,
                'reference' => ['required', 'string', 'max:' . StorefrontDecisionRecord::MAXIMUM_TEXT_LENGTH],
            ],
            'pause' => [
                'reason' => $reason,
                'mode' => ['required', 'string', Rule::in(StorefrontLifecycle::MODES)],
                'message' => ['nullable', 'string', 'max:' . StorefrontLifecycle::MAXIMUM_MESSAGE_LENGTH],
                'until' => ['nullable', 'string', 'max:16'],
            ],
            default => ['reason' => $reason],
        };
    }

    /** The person making the change: the admin who is signed in, by name and number. */
    public function person(): string
    {
        $admin = auth('admin')->user();

        return trim(sprintf('%s (admin %s)', (string) $admin?->name, (string) $admin?->getKey()));
    }

    public function reason(): string
    {
        return (string) $this->input('reason');
    }

    public function reference(): string
    {
        return (string) $this->input('reference');
    }

    public function mode(): string
    {
        return (string) $this->input('mode');
    }

    public function message(): ?string
    {
        return $this->input('message');
    }

    public function until(): ?string
    {
        return $this->input('until');
    }
}
