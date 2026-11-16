<?php

namespace Tests\Feature\Security;

use App\Rules\StoredFileNames;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The seller apps upload a file first and send its name with the product. The name is stored, and the platform later reads, downloads or
 * deletes the file it names, so a name is one path segment the platform wrote, and a path, a way up or a list holding one is refused.
 */
class StoredFileNamesRuleTest extends TestCase
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

    private function passes(mixed $value): bool
    {
        return !Validator::make(['field' => $value], ['field' => [new StoredFileNames()]])->fails();
    }

    /** @return array<string, array{0: mixed}> */
    public static function whatTheAppsSend(): array
    {
        return [
            'a name' => ['2026-11-16-6afb27ee9e4cf.webp'],
            'nothing' => [null],
            'an empty text' => [''],
            'a list of names' => [['2026-11-16-a.webp', '2026-11-16-b.webp']],
            'the json text of a list of names' => [json_encode(['2026-11-16-a.webp', '2026-11-16-b.webp'])],
            'a list of image objects, as seller app v3 sends them' => [[['image_name' => '2026-11-16-a.webp', 'storage' => 'public'], ['image_name' => '2026-11-16-b.webp', 'storage' => 'public']]],
            'the json text of a list of image objects' => [json_encode([['image_name' => '2026-11-16-a.webp', 'storage' => 'public']])],
            'colour images, one without a file' => [json_encode([['color' => '#ff0000', 'image_name' => '2026-11-16-a.webp', 'storage' => 'public'], ['color' => '#00ff00', 'image_name' => null]])],
            'a name with a space and dots' => ['my guide. v2.pdf'],
            'a number' => ['12345'],
        ];
    }

    #[DataProvider('whatTheAppsSend')]
    public function test_what_the_seller_apps_send_is_accepted(mixed $value): void
    {
        $this->assertTrue($this->passes($value), json_encode($value));
    }

    public function test_a_file_sent_in_the_field_is_not_a_name_and_is_left_to_the_file_rules(): void
    {
        $this->assertTrue($this->passes(UploadedFile::fake()->create('guide.pdf', 10)));
    }

    /** @return array<string, array{0: mixed}> */
    public static function whatReachesAnotherFile(): array
    {
        return [
            'the project root, five levels up' => ['../../../../../.env'],
            'a file next to the uploads' => ['../shop/banner/victim-banner.webp'],
            'an absolute path' => ['/etc/passwd'],
            'a windows path' => ['..\\..\\.env'],
            'a directory' => ['shop/banner/victim-banner.webp'],
            'only the way up' => ['..'],
            'a null byte' => ["guide.pdf\0.png"],
            'a list with one path in it' => [['2026-11-16-a.webp', '../../../../../.env']],
            'the json text of a list with one path in it' => [json_encode(['2026-11-16-a.webp', '../../.env'])],
            'an image object with a path' => [[['image_name' => '../../.env', 'storage' => 'public']]],
            'the json text of an image object with a path' => [json_encode([['image_name' => '../../.env', 'storage' => 'public']])],
            'a number in a list that is not a name' => [[12, ['x']]],
            'a value that is neither text nor a list' => [true],
        ];
    }

    #[DataProvider('whatReachesAnotherFile')]
    public function test_a_path_a_way_up_or_a_list_holding_one_is_refused(mixed $value): void
    {
        $this->assertFalse($this->passes($value), json_encode($value));
    }

    /** @return array<string, array{0: mixed}> */
    public static function valuesThatHoldNoName(): array
    {
        return [
            'a list that holds a list' => [[['2026-11-16-a.webp']]],
            'the json text of a list that holds a list' => [json_encode([['2026-11-16-a.webp']])],
            'the json text of an object that holds an object' => [json_encode(['a' => ['b' => 1]])],
            'an empty entry in a list' => [[[]]],
            'the json text of a list that holds an empty list' => ['[[]]'],
            'an entry that has a colour and no image name' => [[['color' => '#ff0000', 'storage' => 'public']]],
            'the json text of an entry that has a colour and no image name' => [json_encode([['color' => '#ff0000']])],
            'an entry whose image name is a list' => [[['image_name' => ['2026-11-16-a.webp']]]],
        ];
    }

    #[DataProvider('valuesThatHoldNoName')]
    public function test_a_list_or_an_entry_that_holds_no_image_name_is_refused_and_not_stored_as_it_is(mixed $value): void
    {
        $this->assertFalse($this->passes($value), json_encode($value));
    }

    public function test_the_refusal_is_a_validation_error_on_the_field_with_a_message(): void
    {
        $validator = Validator::make(['thumbnail' => '../../../../../.env'], ['thumbnail' => [new StoredFileNames()]]);

        $this->assertTrue($validator->fails());
        $this->assertSame(translate('the_file_name_is_not_valid'), $validator->errors()->first('thumbnail'));
    }
}
