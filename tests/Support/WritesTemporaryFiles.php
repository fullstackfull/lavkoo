<?php

namespace Tests\Support;

/** Files a test writes for the code under test; they are removed when the test ends (tempnam() alone leaves its empty base file behind). */
trait WritesTemporaryFiles
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function temporaryFile(string $extension, string $contents): string
    {
        $base = tempnam(directory: sys_get_temp_dir(), prefix: 'address_test_');
        $path = $base . '.' . $extension;
        rename(from: $base, to: $path);
        file_put_contents(filename: $path, data: $contents);

        return $this->temporaryFiles[] = $path;
    }

    /** Called by the framework after every test of a class that uses this trait. */
    protected function tearDownWritesTemporaryFiles(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink(filename: $path);
        }

        $this->temporaryFiles = [];
    }
}
