<?php

namespace Tests\Support;

use App\Traits\FileManagerTrait;

/**
 * A plain host for `FileManagerTrait`, the way every controller and service of the platform hosts it.
 *
 * A test class cannot host it itself: the trait's `delete()` is protected and `Illuminate\Foundation\Testing\TestCase` declares a public
 * `delete()` of its own, so `use FileManagerTrait` inside a test is a fatal error at class load rather than a failing test. Nothing here
 * overrides or imitates a method of the trait -- the three wrappers only widen the visibility of what a controller calls.
 */
final class HostsTheFileManager
{
    use FileManagerTrait;

    public function uploadFile(string $dir, string $format, mixed $file = null, ?string $disk = null): string
    {
        return $this->fileUpload($dir, $format, $file, $disk);
    }

    /**
     * A file's code with its comments taken out, and nothing else changed: every comment becomes a space, so offsets shift but no two
     * tokens are joined into one.
     *
     * Here rather than in the test because a source scan that counts a key must not count the docblock that explains it -- the same fault
     * `PushTopicNamesTest`'s register of FCM senders had, where a sentence about a method joined the register of the files that call it.
     */
    public static function withoutComments(string $code): string
    {
        $out = '';

        foreach (token_get_all($code) as $token) {
            $out .= is_array($token)
                ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1])
                : $token;
        }

        return $out;
    }

    /** @return array{name: string, storage: string}|null */
    public function copyFile(string $dir, ?string $fileName, ?string $storageType = null, ?string $format = null): ?array
    {
        return $this->copyStoredFile(dir: $dir, fileName: $fileName, storageType: $storageType, format: $format);
    }
}
