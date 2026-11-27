<?php

namespace Tests\Fixtures\LanguageFileGuard;

use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * A test that lets translate() append a key, so that the guard of {@see \Tests\Unit\Ci\CiLanguageFilesStayUnchangedTest} can be run
 * against a real offender instead of against a description of one.
 *
 * It lives under tests/Fixtures and not under tests/Unit or tests/Feature, which are the only two directories phpunit.xml collects, so
 * `vendor/bin/phpunit` never runs it by itself: it is run by its path, by that class, as a subprocess.
 *
 * The locale is put in the session rather than read from `business_settings`, because this fixture builds no database.
 */
class WritesAnUnknownKeyTest extends TestCase
{
    /** The key this fixture invents. It carries the process id so two runs of it never collide in the file. */
    public static function theKeyItWrites(): string
    {
        return 'zz_language_file_guard_fixture_' . getmypid();
    }

    public function test_it_renders_a_key_no_locale_holds(): void
    {
        Session::put('local', 'en');

        $this->assertSame(
            ucfirst(str_replace('_', ' ', self::theKeyItWrites())),
            translate(self::theKeyItWrites()),
            'translate() answers the humanised key, which is what it does for a key it does not know',
        );
    }
}
