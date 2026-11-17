<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * A test that puts files into the public disk of the working tree shares it with whatever is already there (a shop's own uploads, when the suite runs in
 * a tree that has been used). It removes the files it created, and the ones the code under test stored while it ran, one file at a time, and a folder only
 * when nothing is left in it.
 */
trait RemovesFilesItCreated
{
    /** @var list<string> */
    private array $created = [];

    /** Writes a file for the code under test to find. */
    private function fileOnDisk(string $path, string $content): void
    {
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $content);
        $this->created[] = $path;
    }

    /**
     * Runs what stores files below a folder and remembers the files it stored.
     *
     * @template TResult
     * @param callable(): TResult $work
     * @return TResult
     */
    private function trackingTheFilesStoredBelow(string $folder, callable $work): mixed
    {
        $before = $this->filesBelow(folder: $folder);
        $result = $work();
        $this->created = [...$this->created, ...array_diff($this->filesBelow(folder: $folder), $before)];

        return $result;
    }

    /** @param list<string> $folders the folders the test used, a folder before the one that holds it */
    private function removeTheFilesItCreated(array $folders): void
    {
        File::delete(array_unique($this->created));
        $this->created = [];
        foreach ($folders as $folder) {
            @rmdir($folder);
        }
    }

    /** @return list<string> */
    private function filesBelow(string $folder): array
    {
        return is_dir($folder) ? array_map(static fn ($file): string => $file->getPathname(), File::allFiles($folder, true)) : [];
    }
}
