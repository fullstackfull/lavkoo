<?php

namespace Tests\Feature\Security;

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Http\Requests\ProductAddRequest;
use App\Http\Requests\ProductUpdateRequest;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The admin and vendor panels post back the names of files that were uploaded earlier (a copy from the product gallery carries the original's
 * thumbnail, images and preview file), and a bulk import sheet holds a thumbnail cell. They are stored and later deleted by name, so each is
 * one plain name; the audit reproduced a seller deleting `company/logo.png` of the public disk through `existing_thumbnail=../../company/logo.png`
 * and through a sheet cell written with backslashes (which an explode on a slash does not split).
 */
class PanelProductFileNamesTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        DB::table('products')->insert(['id' => 5, 'name' => 'Guide', 'code' => 'GUIDE123']);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @return array<string, array<string, mixed>> the fields of the product forms that carry a name, and a value that is a path */
    public static function namesThatArePaths(): array
    {
        return [
            'the thumbnail' => [['existing_thumbnail' => '../../company/logo.png']],
            'a list of images' => [['existing_images' => ['2026-11-16-a.webp', '../company/logo2.png']]],
            'the preview file' => [['existing_preview_file' => '../../company/logo3.png']],
            'a backslash path' => [['existing_thumbnail' => '..\\..\\company\\logo.png']],
        ];
    }

    private function updateRequest(): ProductUpdateRequest
    {
        $route = new Route('POST', '/vendor/products/update/{id}', []);
        $route->bind(Request::create('/vendor/products/update/5', 'POST'));
        $request = new ProductUpdateRequest(app(ProductRepositoryInterface::class));
        $request->initialize();
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    private function fails(array $rules, array $input): bool
    {
        return Validator::make($input, array_intersect_key($rules, $input))->fails();
    }

    #[DataProvider('namesThatArePaths')]
    public function test_the_add_form_refuses_a_name_that_is_a_path(array $input): void
    {
        $this->assertTrue($this->fails((new ProductAddRequest())->rules(), $input), json_encode($input));
    }

    #[DataProvider('namesThatArePaths')]
    public function test_the_update_form_refuses_a_name_that_is_a_path(array $input): void
    {
        $this->assertTrue($this->fails($this->updateRequest()->rules(), $input), json_encode($input));
    }

    /** @return array<string, array<string, mixed>> the colour image fields, which hold a file or a name, and the meta image of a copy */
    public static function colourAndMetaNamesThatArePaths(): array
    {
        return [
            'a colour image that points into the digital files' => [['color_image_FF0000' => 'digital-product/victim.zip']],
            'a colour image that points into the thumbnails' => [['color_image_00FF00' => 'thumbnail/victim.webp']],
            'a colour image that goes up' => [['color_image_0000FF' => '../../company/logo.png']],
            'a colour image written as a list with a path' => [['color_image_FF0000' => ['digital-product/victim.zip']]],
            'the meta image of a copy' => [['existing_meta_image' => '../meta/victim.webp']],
        ];
    }

    #[DataProvider('colourAndMetaNamesThatArePaths')]
    public function test_the_add_form_refuses_a_colour_image_or_a_meta_image_that_is_a_path(array $input): void
    {
        $this->assertTrue($this->fails(ProductAddRequest::create('/', 'POST', $input)->rules(), $input), json_encode($input));
    }

    #[DataProvider('colourAndMetaNamesThatArePaths')]
    public function test_the_update_form_refuses_a_colour_image_or_a_meta_image_that_is_a_path(array $input): void
    {
        $request = $this->updateRequest();
        $request->merge($input);

        $this->assertTrue($this->fails($request->rules(), $input), json_encode($input));
    }

    public function test_a_colour_image_that_is_a_file_or_a_plain_name_and_a_meta_image_name_are_accepted(): void
    {
        $input = ['color_image_FF0000' => '2026-11-16-red.webp', 'color_image_00FF00' => UploadedFile::fake()->image('green.webp'), 'existing_meta_image' => '2026-11-16-meta.webp'];

        $this->assertFalse($this->fails(ProductAddRequest::create('/', 'POST', $input)->rules(), $input));
    }

    public function test_the_names_the_gallery_copy_posts_back_are_accepted(): void
    {
        $input = ['existing_thumbnail' => '2026-11-16-thumb.webp', 'existing_images' => ['2026-11-16-a.webp', '2026-11-16-b.webp'], 'existing_preview_file' => '2026-11-16-preview.pdf'];

        $this->assertFalse($this->fails((new ProductAddRequest())->rules(), $input));
        $this->assertFalse($this->fails($this->updateRequest()->rules(), $input));
    }

    /** @return array<string, array{0: mixed, 1: string}> a thumbnail cell of the bulk import sheet, and the name that is stored */
    public static function thumbnailCells(): array
    {
        return [
            'a plain name' => ['photo.png', 'photo.png'],
            'a folder and a name, as the sheet is written' => ['thumbnail/photo.png', 'photo.png'],
            'a padded cell' => ['  photo.png ', 'photo.png'],
            'a way up with slashes' => ['../../company/logo.png', 'def.png'],
            'a way up with backslashes' => ['..\\..\\company\\logo.png', 'def.png'],
            'a backslash folder and a name' => ['thumbnail\\photo.png', 'photo.png'],
            'only a way up' => ['..', 'def.png'],
            'an empty cell' => ['', 'def.png'],
            'nothing' => [null, 'def.png'],
            'a folder then a way up' => ['thumbnail/..', 'def.png'],
        ];
    }

    #[DataProvider('thumbnailCells')]
    public function test_a_bulk_import_thumbnail_cell_is_one_plain_name_or_the_default_image(mixed $cell, string $stored): void
    {
        $this->assertSame($stored, ProductService::importedFileName(cell: $cell));
    }
}
