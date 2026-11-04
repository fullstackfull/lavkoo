<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Closure;
use Illuminate\Http\UploadedFile;

class DisallowedExtension implements ValidationRule
{
    protected array $disallowed;

    public function __construct()
    {
        $this->disallowed = getDisallowedExtensionsListArray();
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$value instanceof UploadedFile) {
            return;
        }
        $extension = strtolower($value->getClientOriginalExtension());
        if (in_array($extension, $this->disallowed, true)) {
            $fail(translate('this_file_type_is_not_allowed'));
        }
    }
}
