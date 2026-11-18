<?php

namespace Tests\Feature\Security;

use App\Traits\Processor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The payment gateway form takes a logo. The handler gave whatever the request sent in that field to file_get_contents(), so a plain text
 * field holding `/var/www/.env` copied the shop's secrets into the public disk (and `http://169.254.169.254/...` made the server fetch it),
 * and the name came back in the page, ready to download. Only an uploaded raster image is a logo.
 */
class GatewayImageUploadTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private const DIRECTORY = 'payment_modules/gateway_image/';

    /** @var list<string> */
    private array $storedBefore = [];

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        Schema::create('addon_settings', function ($table) {
            $table->uuid('id')->primary();
            $table->string('key_name')->nullable();
            $table->text('live_values')->nullable();
            $table->text('test_values')->nullable();
            $table->string('settings_type')->nullable();
            $table->string('mode')->default('live');
            $table->boolean('is_active')->default(false);
            $table->text('additional_data')->nullable();
            $table->timestamps();
        });
        DB::table('addon_settings')->insert(['id' => 'a0000000-0000-4000-8000-000000000001', 'key_name' => 'ssl_commerz', 'settings_type' => 'payment_config', 'mode' => 'live', 'is_active' => 0, 'live_values' => '{}', 'test_values' => '{}', 'additional_data' => null]);
        File::ensureDirectoryExists(storage_path('app/public/' . self::DIRECTORY));
        $this->storedBefore = array_map('basename', File::files(storage_path('app/public/' . self::DIRECTORY)));
    }

    protected function tearDown(): void
    {
        foreach (File::files(storage_path('app/public/' . self::DIRECTORY)) as $file) {
            if (!in_array($file->getFilename(), $this->storedBefore, true)) {
                File::delete($file->getPathname());
            }
        }
        array_map('unlink', array_filter($this->temporaryFiles, 'file_exists'));
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function temporaryFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gw');
        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);

        return (string)ob_get_clean();
    }

    private function upload(string $content, string $clientName = 'logo.png'): UploadedFile
    {
        return new UploadedFile($this->temporaryFile($content), $clientName, null, null, true);
    }

    /** @return list<string> */
    private function newlyStored(): array
    {
        return array_values(array_diff(array_map('basename', File::files(storage_path('app/public/' . self::DIRECTORY))), $this->storedBefore));
    }

    private function uploader(): object
    {
        return new class {
            use Processor;
        };
    }

    // ---- the helper ---------------------------------------------------------------------------------------------------------------

    public function test_an_uploaded_image_is_stored_under_a_generated_name(): void
    {
        $name = $this->uploader()->file_uploader(self::DIRECTORY, 'png', $this->upload($this->png()), '');

        $this->assertSame([$name], $this->newlyStored());
        $this->assertSame($this->png(), file_get_contents(storage_path('app/public/' . self::DIRECTORY . $name)));
    }

    public function test_the_stored_extension_is_the_one_the_caller_asks_for_whatever_the_client_named_the_file(): void
    {
        foreach (['logo.php', 'logo.phtml', 'logo.html', 'logo', 'logo.png.php'] as $clientName) {
            $name = $this->uploader()->file_uploader(self::DIRECTORY, 'png', $this->upload($this->png(), $clientName), '');

            $this->assertStringEndsWith('.png', $name, $clientName);
        }
    }

    public function test_a_path_or_an_address_in_place_of_an_upload_is_no_image_and_nothing_is_read(): void
    {
        $secret = $this->temporaryFile('APP_KEY=base64:canary');

        foreach ([$secret, 'file://' . $secret, 'php://filter/read=convert.base64-encode/resource=' . $secret, 'http://127.0.0.1:9/x', ['a'], 5, true] as $value) {
            $this->assertSame('old.png', $this->uploader()->file_uploader(self::DIRECTORY, 'png', $value, 'old.png'), var_export($value, true));
        }
        $this->assertSame('def.png', $this->uploader()->file_uploader(self::DIRECTORY, 'png', $secret, null));
        $this->assertSame([], $this->newlyStored());
    }

    public function test_an_upload_that_is_not_an_image_is_no_image(): void
    {
        foreach (['<?php system($_GET["c"]);', '<html><script>alert(1)</script></html>', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'APP_KEY=base64:canary'] as $content) {
            $this->assertSame('old.png', $this->uploader()->file_uploader(self::DIRECTORY, 'png', $this->upload($content), 'old.png'), $content);
        }
        $this->assertSame([], $this->newlyStored());
    }

    public function test_the_old_logo_is_deleted_only_when_its_name_is_a_plain_name(): void
    {
        $inside = $this->temporaryFile('keep');
        $keep = storage_path('app/public/probe-keep-' . uniqid() . '.txt');
        file_put_contents($keep, 'keep');
        $this->temporaryFiles[] = $keep;

        $this->uploader()->file_uploader(self::DIRECTORY, 'png', $this->upload($this->png()), '../../' . basename($keep));
        $this->uploader()->file_uploader(self::DIRECTORY, 'png', $this->upload($this->png()), '');

        $this->assertFileExists($keep);
        $this->assertFileExists($inside);
    }

    // ---- the form --------------------------------------------------------------------------------------------------------------------

    private function submit(array $files = [], array $fields = []): \Illuminate\Testing\TestResponse
    {
        $parameters = ['gateway' => 'ssl_commerz', 'mode' => 'live', 'status' => 0, 'store_id' => 's', 'store_password' => 'p', 'gateway_title' => 'Card'] + $fields;

        return $this->withoutMiddleware()->call('PUT', '/admin/third-party/payment-method/addon-payment-set', $parameters, [], $files);
    }

    public function test_the_form_stores_an_uploaded_logo_and_keeps_it_in_the_settings(): void
    {
        $this->submit(files: ['gateway_image' => $this->upload($this->png())])->assertRedirect();

        $stored = $this->newlyStored();
        $this->assertCount(1, $stored);
        $row = DB::table('addon_settings')->where('key_name', 'ssl_commerz')->first();
        $this->assertSame($stored[0], json_decode($row->additional_data, true)['gateway_image']);
    }

    public function test_the_form_refuses_a_text_field_that_names_a_file_and_copies_nothing(): void
    {
        $secret = $this->temporaryFile('APP_KEY=base64:canary');

        $response = $this->submit(fields: ['gateway_image' => $secret]);

        $response->assertSessionHasErrors('gateway_image');
        $this->assertSame([], $this->newlyStored());
        $this->assertNull(DB::table('addon_settings')->where('key_name', 'ssl_commerz')->value('additional_data'));
    }

    public function test_the_form_refuses_a_file_that_is_not_an_image(): void
    {
        $response = $this->submit(files: ['gateway_image' => $this->upload('<?php system($_GET["c"]);', 'logo.png')]);

        $response->assertSessionHasErrors('gateway_image');
        $this->assertSame([], $this->newlyStored());
    }

    public function test_the_form_without_a_logo_keeps_the_old_behaviour(): void
    {
        $this->submit()->assertRedirect();

        $this->assertSame([], $this->newlyStored());
        $this->assertSame('Card', json_decode(DB::table('addon_settings')->where('key_name', 'ssl_commerz')->value('additional_data'), true)['gateway_title']);
    }
}
