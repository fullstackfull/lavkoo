<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Admin\Settings\BusinessSettingsController;
use App\Http\Controllers\Admin\Settings\DatabaseSettingController;
use App\Http\Controllers\Admin\Settings\ThemeBuilderController;
use App\Http\Requests\Admin\DeepLinkRequest;
use App\Services\DeepLink\AssociationFileWriter;
use App\Services\Theme\ThemeAssetService;
use App\Services\Theme\ThemePermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Three admin handlers took a name from the request and used it without the list their own screen is built from: the database clean emptied
 * any table named in `tables[]` (the screen hides the admins, the business settings and the users, the handler did not), the app deep-link form
 * wrote its fields into whichever business-settings row `type` named (`language`, `fcm_credentials`, `storage_connection_type`), and the theme
 * builder's media upload skipped the edit permission that its media delete asks for.
 */
class AdminHandlerAllowlistsTest extends TestCase
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
        Mockery::close();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    // ---- the database clean --------------------------------------------------------------------------------------------------

    private function tableWithRows(string $name): void
    {
        Schema::dropIfExists($name);
        Schema::create($name, static function ($table) {
            $table->id();
            $table->string('label')->nullable();
        });
        DB::table($name)->insert([['label' => 'a'], ['label' => 'b']]);
    }

    public function test_the_database_clean_empties_the_tables_the_screen_offers_and_no_other(): void
    {
        foreach (['admins', 'users', 'oauth_clients', 'probe_cache_rows'] as $table) {
            $this->tableWithRows($table);
        }
        DB::table('business_settings')->insert(['type' => 'probe', 'value' => 'keep']);

        app(DatabaseSettingController::class)->delete(Request::create('/', 'POST', ['tables' => ['admins', 'users', 'business_settings', 'oauth_clients', 'Admins', 'probe_cache_rows', 'no_such_table', 'information_schema.tables']]));

        $this->assertSame(0, DB::table('probe_cache_rows')->count(), 'the table the screen offers is emptied');
        foreach (['admins', 'users', 'oauth_clients'] as $table) {
            $this->assertSame(2, DB::table($table)->count(), $table . ' is not offered by the screen and is not emptied by the handler');
        }
        $this->assertSame(1, DB::table('business_settings')->where('type', 'probe')->count());
    }

    public function test_the_database_clean_with_nothing_that_is_offered_changes_nothing(): void
    {
        $this->tableWithRows('admins');

        app(DatabaseSettingController::class)->delete(Request::create('/', 'POST', ['tables' => ['admins']]));
        app(DatabaseSettingController::class)->delete(Request::create('/', 'POST', ['tables' => 'admins']));

        $this->assertSame(2, DB::table('admins')->count());
    }

    // ---- the app deep link -------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: mixed}> */
    public static function rowsTheFormMustNotWrite(): array
    {
        return [
            'the language setting' => ['language'],
            'the firebase credentials' => ['fcm_credentials'],
            'the storage type' => ['storage_connection_type'],
            'a list' => [['language']],
        ];
    }

    #[DataProvider('rowsTheFormMustNotWrite')]
    public function test_the_deep_link_form_writes_its_own_row_whatever_type_it_names(mixed $type): void
    {
        DB::table('business_settings')->insert([['type' => 'language', 'value' => 'keep-language'], ['type' => 'fcm_credentials', 'value' => 'keep-fcm'], ['type' => 'storage_connection_type', 'value' => 'keep-storage']]);
        $this->mock(AssociationFileWriter::class)->shouldReceive('publish')->andReturn([]);
        $request = DeepLinkRequest::create('/', 'POST', ['type' => $type, 'android_package_name' => 'com.shop.app', 'android_sha256_fingerprint' => 'AA:BB', 'playstore_redirect_url' => 'https://play.google.com/x']);

        try {
            app(BusinessSettingsController::class)->updateAppDeepLink($request);
        } finally {
            $this->assertSame('keep-language', DB::table('business_settings')->where('type', 'language')->value('value'));
            $this->assertSame('keep-fcm', DB::table('business_settings')->where('type', 'fcm_credentials')->value('value'));
            $this->assertSame('keep-storage', DB::table('business_settings')->where('type', 'storage_connection_type')->value('value'));
        }
        $this->assertStringContainsString('com.shop.app', (string)DB::table('business_settings')->where('type', 'app_deep_link')->value('value'));
    }

    public function test_the_deep_link_request_accepts_its_own_type_or_none(): void
    {
        $rules = (new DeepLinkRequest())->rules();

        foreach (['app_deep_link', null] as $type) {
            $this->assertFalse(Validator::make(['type' => $type], ['type' => $rules['type']])->fails(), var_export($type, true));
        }
        $this->assertTrue(Validator::make(['type' => 'language'], ['type' => $rules['type']])->fails());
    }

    // ---- the theme builder's media upload ----------------------------------------------------------------------------------

    public function test_the_theme_builder_media_upload_needs_the_edit_permission_like_the_delete(): void
    {
        $this->mock(ThemePermissionService::class)->shouldReceive('canEdit')->andReturn(false);
        $assets = $this->mock(ThemeAssetService::class);
        $assets->shouldNotReceive('upload');
        $request = Request::create('/', 'POST', [], [], ['image' => UploadedFile::fake()->image('logo.png')]);

        $response = app(ThemeBuilderController::class)->uploadMedia($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('error', $response->getData(true)['status']);
    }
}
