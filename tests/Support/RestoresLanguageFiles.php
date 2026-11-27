<?php

namespace Tests\Support;

/**
 * translate() appends a humanised placeholder to the tracked language files for any key it does not know (the invoice and order templates still
 * use a few that never had a text), so a test that renders them would leave the working tree dirty. The files are put back after the test.
 *
 * {@see \Tests\TestCase} remembers and restores for every test that boots the application, before the test as well as after it, so a suite
 * does not depend on each of its files electing to. The 195 classes that elect to as well are unaffected and are left alone: the copy is
 * taken once per process either way.
 *
 * The state lives in {@see LanguageFiles} rather than here because a trait's static property belongs to the class that uses the trait: a
 * copy per using class would have held up to three quarters of a gigabyte for the length of a run.
 */
trait RestoresLanguageFiles
{
    protected function rememberLanguageFiles(): void
    {
        LanguageFiles::remember(directory: base_path());
    }

    protected function restoreLanguageFiles(): void
    {
        LanguageFiles::restore();
    }

    /**
     * Which keys each language file has gained since the copy was taken, so that a guard can NAME them rather than report a dirty tree.
     *
     * @return array<string, list<string>> path => the keys it gained
     */
    protected function keysWrittenToTheLanguageFiles(): array
    {
        return LanguageFiles::keysWritten();
    }
}
