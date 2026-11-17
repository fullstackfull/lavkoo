<?php

namespace Tests\Feature\Security;

use App\Http\Requests\API\v3\DigitalProductFileUploadAfterSell;
use App\Utils\ImageManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A file a seller or a customer uploads is stored under an extension, on a disk the web server serves. A name the server hands to a
 * script engine runs what was uploaded, so the extension of a stored file never comes from the client unchecked.
 *
 * The seller app's upload of the file delivered after a sale sends it as `digital_file_after_sell`, and the handler stores that field under
 * the extension the client wrote, but the request class validated a field called `digital_file_ready`: the app's own request failed with
 * "required", and a request that also carried a valid `digital_file_ready` stored whatever `digital_file_after_sell` held, named
 * `shell.php` included.
 */
class UploadedFileExtensionTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        // A file under product/digital-product/ is deleted only when no product or variation holds it, so the replacement asks the variations.
        Schema::create('digital_product_variations', static function ($table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('file')->nullable();
        });
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function rulesOfTheAfterSaleUpload(): array
    {
        return (new DigitalProductFileUploadAfterSell())->rules();
    }

    private function validates(array $input): bool
    {
        return !Validator::make($input, $this->rulesOfTheAfterSaleUpload())->fails();
    }

    public function test_the_file_the_seller_app_sends_after_a_sale_is_the_field_that_is_validated(): void
    {
        $this->assertTrue($this->validates(['order_id' => 1, 'digital_file_after_sell' => UploadedFile::fake()->create('delivered.pdf', 10, 'application/pdf')]));
        $this->assertTrue($this->validates(['order_id' => 1, 'digital_file_after_sell' => UploadedFile::fake()->image('delivered.png')]));
    }

    public function test_a_script_is_refused_as_the_after_sale_file_even_when_another_valid_file_travels_with_it(): void
    {
        $script = UploadedFile::fake()->create('shell.php', 1, 'text/x-php');
        $valid = UploadedFile::fake()->create('delivered.pdf', 10, 'application/pdf');

        $this->assertFalse($this->validates(['order_id' => 1, 'digital_file_after_sell' => $script]));
        $this->assertFalse($this->validates(['order_id' => 1, 'digital_file_after_sell' => $script, 'digital_file_ready' => $valid]));
    }

    public function test_a_request_with_the_old_field_alone_is_no_upload(): void
    {
        $valid = UploadedFile::fake()->create('delivered.pdf', 10, 'application/pdf');

        $this->assertFalse($this->validates(['order_id' => 1, 'digital_file_ready' => $valid]));
    }

    public function test_a_file_whose_name_is_not_a_listed_script_but_whose_content_is_not_an_allowed_type_is_refused(): void
    {
        $this->assertFalse($this->validates(['order_id' => 1, 'digital_file_after_sell' => UploadedFile::fake()->createWithContent('delivered.txt', '<?php system($_GET["c"]);')]));
    }

    /** @return array<string, array{0: string, 1: string}> the extension the caller passes, and the one the stored file gets */
    public static function extensionsOfStoredFiles(): array
    {
        return [
            'a pdf' => ['pdf', 'pdf'],
            'upper case is lower case' => ['PDF', 'pdf'],
            'a zip' => ['zip', 'zip'],
            'a video' => ['mp4', 'mp4'],
            'a word document' => ['docx', 'docx'],
            'php' => ['php', 'bin'],
            'php in upper case' => ['PHP', 'bin'],
            'a numbered php' => ['php7', 'bin'],
            'phtml' => ['phtml', 'bin'],
            'pht, which the disallowed list does not name' => ['pht', 'bin'],
            'phar' => ['phar', 'bin'],
            'phps' => ['phps', 'bin'],
            'a shell script' => ['sh', 'bin'],
            'html' => ['html', 'bin'],
            'nothing' => ['', 'bin'],
            'a name, not an extension' => ['shell.php', 'bin'],
            'a path' => ['../../x', 'bin'],
            'a very long extension' => ['abcdefghijkl', 'bin'],
        ];
    }

    #[DataProvider('extensionsOfStoredFiles')]
    public function test_a_stored_file_never_gets_an_extension_a_server_would_run(string $callersExtension, string $storedExtension): void
    {
        Storage::fake('public');

        $name = ImageManager::file_upload(dir: 'product/digital-product/', format: $callersExtension, file: UploadedFile::fake()->create('delivered.dat', 10, 'application/octet-stream'));

        $this->assertStringEndsWith('.' . $storedExtension, $name);
        $this->assertTrue(Storage::disk('public')->exists('product/digital-product/' . $name));
    }

    public function test_replacing_a_file_goes_through_the_same_guard(): void
    {
        Storage::fake('public');

        $name = ImageManager::update(dir: 'product/digital-product/', old_image: 'old.pdf', format: 'phtml', image: UploadedFile::fake()->create('delivered.dat', 10, 'application/octet-stream'), file_type: 'file');

        $this->assertStringEndsWith('.bin', $name);
    }
}
