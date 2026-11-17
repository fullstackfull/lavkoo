<?php

namespace Tests\Feature\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Modules\Blog\app\Http\Controllers\Admin\BlogDownloadAppController;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The blog's "download the app" section keeps an icon and a background in the company directory. Its delete action removed the file the request
 * named, whatever it was (the shop's logo, a favicon, any other file of the directory), and then the setting.
 */
class BlogDownloadAppImageDeleteTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        DB::table('business_settings')->insert([
            ['type' => 'blog_feature_download_app_icon', 'value' => json_encode(['image_name' => 'app-icon.webp', 'storage' => 'public'])],
            ['type' => 'blog_feature_download_app_background', 'value' => 'app-background.webp'],
        ]);
        foreach (['app-icon.webp', 'app-background.webp', 'victim-logo.png', 'victim-favicon.png'] as $name) {
            File::ensureDirectoryExists(storage_path('app/public/company'));
            file_put_contents(storage_path('app/public/company/' . $name), $name);
            $this->created[] = storage_path('app/public/company/' . $name);
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->created, 'file_exists'));
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function company(string $name): string
    {
        return storage_path('app/public/company/' . $name);
    }

    private function deleteImages(array $query): void
    {
        app(BlogDownloadAppController::class)->deleteImage(Request::create('/', 'POST', $query));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function requestsThatNameAnotherFile(): array
    {
        return [
            'the logo as the icon' => [['icon' => 'victim-logo.png']],
            'the favicon as the background' => [['image' => 'victim-favicon.png']],
            'both' => [['icon' => 'victim-logo.png', 'image' => 'victim-favicon.png']],
            'the background as the icon' => [['icon' => 'app-background.webp']],
            'a way up' => [['icon' => '../seo-meta-info/victim.svg']],
            'a list' => [['icon' => ['app-icon.webp']]],
        ];
    }

    #[DataProvider('requestsThatNameAnotherFile')]
    public function test_a_file_the_setting_does_not_hold_is_not_deleted_and_the_setting_stays(array $query): void
    {
        $this->deleteImages($query);

        foreach (['victim-logo.png', 'victim-favicon.png', 'app-icon.webp', 'app-background.webp'] as $name) {
            $this->assertFileExists($this->company($name), $name);
        }
        $this->assertSame(2, DB::table('business_settings')->count());
    }

    public function test_the_icon_and_the_background_the_setting_holds_are_still_deleted_with_their_settings(): void
    {
        $this->deleteImages(['icon' => 'app-icon.webp', 'image' => 'app-background.webp']);

        $this->assertFileDoesNotExist($this->company('app-icon.webp'));
        $this->assertFileDoesNotExist($this->company('app-background.webp'));
        $this->assertFileExists($this->company('victim-logo.png'));
        $this->assertSame(0, DB::table('business_settings')->count());
    }
}
