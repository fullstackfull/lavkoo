<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * translate() writes a key it does not know into the language file, and a page may hand it a value a visitor chose (the wallet and loyalty
 * pages call `translate(request('type'))` for an empty list). The admin's translation screen lists every key of the file, so a key that holds a
 * tag was a row of that screen that another user's browser rendered: a customer's query string became script in the admin session.
 */
class TranslationKeyTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @return array<string, mixed> the keys of the file the next translate() reads */
    private function storedKeys(): array
    {
        return include base_path('resources/lang/en/new-messages.php');
    }

    /** @return array<string, array{0: string}> */
    public static function keysWithMarkup(): array
    {
        return [
            'an image with a handler' => ['<img src=x onerror=document.title=1>'],
            'a script element' => ['<script>alert(1)</script>'],
            'a closing angle bracket only' => ['a>b'],
            'an opening angle bracket only' => ['a<b'],
            'a tag behind a space' => ['wallet <svg onload=alert(1)>'],
            'a tab' => ["a\tb"],
            'a line break' => ["a\nb"],
            'a null byte' => ["a\0b"],
            'a delete character' => ["a\x7Fb"],
        ];
    }

    #[DataProvider('keysWithMarkup')]
    public function test_a_key_with_markup_is_shown_without_it_and_is_never_stored(string $key): void
    {
        $before = file_get_contents(base_path('resources/lang/en/new-messages.php'));

        $shown = translate($key);

        $this->assertSame(0, preg_match('/[<>]/', (string)$shown), 'the text shown holds no tag');
        $this->assertSame($before, file_get_contents(base_path('resources/lang/en/new-messages.php')), 'the language file is not written');
        $this->assertArrayNotHasKey(str_replace(' ', '_', str_replace('"', '', $key)), $this->storedKeys());
    }

    public function test_a_new_key_without_markup_is_still_stored_and_shown_as_text(): void
    {
        $shown = translate('probe_translation_key_that_is_new');

        $this->assertSame('Probe translation key that is new', $shown);
        $this->assertArrayHasKey('probe_translation_key_that_is_new', $this->storedKeys());
    }

    public function test_a_sentence_with_the_punctuation_the_pages_use_is_still_stored(): void
    {
        $shown = translate("Please enter the shop's name (required): a/b & c, 50% off!");

        $this->assertStringContainsString('shop s name', (string)$shown);
        $this->assertArrayHasKey("Please_enter_the_shop's_name_(required):_a/b_&_c,_50%_off!", $this->storedKeys());
    }

    public function test_a_key_the_files_know_is_translated_as_before(): void
    {
        $this->assertSame('Language change successfully', translate('language_change_successfully'));
    }
}
