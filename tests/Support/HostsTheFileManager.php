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

    /** @return array{name: string, storage: string}|null */
    public function copyFile(string $dir, ?string $fileName, ?string $storageType = null, ?string $format = null): ?array
    {
        return $this->copyStoredFile(dir: $dir, fileName: $fileName, storageType: $storageType, format: $format);
    }
}
