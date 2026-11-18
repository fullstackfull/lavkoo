<?php

namespace Tests\Support;

/**
 * translate() appends a key that no language file defines to the tracked new-messages.php of the locale in use. A test that reaches
 * such a message leaves the working tree dirty, and on the server the same call rewrites a language file in the middle of a request.
 *
 * The files are digested when the guard is armed and compared again before the application is destroyed, so a message nobody
 * defined fails the test that reached it instead of being written quietly.
 */
trait AssertsNoLanguageFileWasWritten
{
    protected function guardAgainstWrittenLanguageFiles(): void
    {
        $digestsBefore = $this->digestsOfLanguageFiles();

        $this->beforeApplicationDestroyed(function () use ($digestsBefore): void {
            $this->assertSame(
                expected: $digestsBefore,
                actual: $this->digestsOfLanguageFiles(),
                message: 'a file under resources/lang changed during the test: translate() wrote a key that no language file defines',
            );
        });
    }

    /** @return array<string, string> */
    private function digestsOfLanguageFiles(): array
    {
        $digests = [];
        foreach (glob(base_path('resources/lang/*/*.php')) ?: [] as $file) {
            $digests[$file] = md5_file($file);
        }

        return $digests;
    }
}
