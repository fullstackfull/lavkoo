<?php

namespace App\Rules;

use App\Utils\PlainFileName;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * The names of files that were uploaded earlier and are now sent back as text: a product's images, its thumbnail and its digital file.
 *
 * The seller apps upload a file first and send its name with the product, and the platform stores the name and later reads or deletes the
 * file it names. A name is one path segment the platform wrote, so ../../../../.env, an absolute path or a list holding one is refused.
 * A value may be a name, a list of names, a list of objects that hold an `image_name` (the colour images of a product), or the JSON text of
 * any of those, which is how the seller app v3 sends lists. A file sent in the same field is not a name and is left to the file rules.
 */
class StoredFileNames implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!self::areAllPlain($value)) {
            $fail(translate('the_file_name_is_not_valid'));
        }
    }

    private static function areAllPlain(mixed $value): bool
    {
        if ($value === null || $value instanceof UploadedFile) {
            return true;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? self::areAllPlain($decoded) : ($value === '' || PlainFileName::isPlain($value));
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            $name = is_array($item) ? ($item['image_name'] ?? null) : $item;
            if ($name !== null && $name !== '' && !($name instanceof UploadedFile) && !PlainFileName::isPlain($name)) {
                return false;
            }
        }

        return true;
    }
}
