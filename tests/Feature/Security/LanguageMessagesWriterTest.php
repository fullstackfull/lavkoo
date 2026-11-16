<?php

namespace Tests\Feature\Security;

use App\Services\LanguageService;
use Illuminate\Support\Facades\File;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A language file is PHP that every request in that language includes. The "translate all" action wrote each message into a double-quoted
 * PHP string as it was, so a value that carried `${...}` was run by the include that follows the write (and stayed in the file, to run on every
 * request in that language), and a quote or a trailing backslash in a value made the file unparsable. The values are now written with var_export.
 */
class LanguageMessagesWriterTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private const LANGUAGE = 'probe-tr';

    private string $directory;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        $this->directory = base_path('resources/lang/' . self::LANGUAGE);
        $this->marker = storage_path('probe-rce-marker.txt');
        File::ensureDirectoryExists($this->directory);
        File::delete($this->marker);
    }

    protected function tearDown(): void
    {
        in_array('https', stream_get_wrappers(), true) && @stream_wrapper_restore('https');
        File::deleteDirectory($this->directory);
        File::delete([$this->marker, public_path('json/admin/lang/' . self::LANGUAGE . '.json')]);
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @return array<string, string> */
    private function hostileMessages(): array
    {
        return [
            'hostile_one' => '${file_put_contents(base_path(\'storage/probe-rce-marker.txt\'), \'ran\')}',
            'hostile_two' => '{${file_put_contents(base_path(\'storage/probe-rce-marker.txt\'), \'ran\')}}',
            'a_dollar_variable' => 'costs $5 and $name',
            'a_double_quote' => 'He said "hi"',
            'a_single_quote' => "it's here",
            'a_trailing_backslash' => 'path\\',
            'a_backslash_n' => 'line\\nbreak',
            'a_real_line_break' => "first\nsecond",
            'a_php_tag' => '<?php echo 1; ?>',
            'arabic_text' => 'مرحبا بالعالم',
            'plain_key' => 'Plain value',
        ];
    }

    private function plant(string $file, array $messages): void
    {
        file_put_contents($this->directory . '/' . $file, "<?php\n\nreturn " . var_export($messages, true) . ";\n");
    }

    /** Lets autoTranslatorBatchedMode() "translate" without the network: the service answers with the text it was asked for. */
    private function fakeTranslationService(): void
    {
        in_array('https', stream_get_wrappers(), true) && stream_wrapper_unregister('https');
        stream_wrapper_register('https', FakeTranslationStream::class);
    }

    public function test_the_remaining_messages_are_written_as_strings_that_read_back_exactly(): void
    {
        $messages = $this->hostileMessages();
        $this->plant('new-messages.php', $messages);
        $path = $this->directory . '/new-messages.php';

        app(LanguageService::class)->getAddTranslateNewKey($path, $path, [base64_encode('plain_key')]);

        $remaining = include $path;
        unset($messages['plain_key']);
        ksort($messages);
        $this->assertSame($messages, $remaining);
        $this->assertFileDoesNotExist($this->marker);
    }

    public function test_translate_all_runs_no_value_as_code_and_leaves_both_files_readable(): void
    {
        $this->plant('new-messages.php', $this->hostileMessages());
        $this->plant('messages.php', ['already_translated' => 'Done']);
        $this->fakeTranslationService();

        $response = app(LanguageService::class)->getAllMessagesTranslateProcess(language: self::LANGUAGE, count: 50);

        $this->assertFileDoesNotExist($this->marker, 'a value of the language file ran as code');
        $this->assertSame(1, $response['status']);
        $messages = include $this->directory . '/messages.php';
        $remaining = include $this->directory . '/new-messages.php';
        $this->assertSame('Done', $messages['already_translated']);
        $this->assertArrayHasKey('hostile_one', $messages);
        $this->assertSame([], $remaining);
        $this->assertStringNotContainsString('${', (string)file_get_contents($this->directory . '/messages.php'), 'the file holds a double-quoted string with an interpolation');
    }

    public function test_a_value_with_an_interpolation_alone_is_not_run_by_translate_all(): void
    {
        $this->plant('new-messages.php', ['hostile_one' => $this->hostileMessages()['hostile_one']]);
        $this->plant('messages.php', []);
        $this->fakeTranslationService();

        app(LanguageService::class)->getAllMessagesTranslateProcess(language: self::LANGUAGE, count: 50);

        $this->assertFileDoesNotExist($this->marker, 'a value of the language file ran as code');
    }

    public function test_the_file_after_translate_all_is_the_same_shape_translate_writes(): void
    {
        $this->plant('new-messages.php', ['first_key' => 'First key']);
        $this->plant('messages.php', []);
        $this->fakeTranslationService();

        app(LanguageService::class)->getAllMessagesTranslateProcess(language: self::LANGUAGE, count: 50);

        $this->assertSame("<?php\n\nreturn [\n\t'first_key' => 'first key',\n];\n", file_get_contents($this->directory . '/messages.php'));
    }
}

/** Answers translate.googleapis.com requests with the text of the `q` parameter as its own translation. */
final class FakeTranslationStream
{
    public $context;

    private string $body = '';

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        parse_str((string)parse_url($path, PHP_URL_QUERY), $query);
        $text = (string)($query['q'] ?? '');
        $this->body = (string)json_encode([[[$text, $text, null, null, 10]]]);

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->body, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->body);
    }

    public function stream_stat(): array|false
    {
        return [];
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }
}
