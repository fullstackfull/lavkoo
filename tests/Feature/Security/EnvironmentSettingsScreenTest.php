<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Admin\Settings\EnvironmentSettingsController;
use App\Http\Controllers\Admin\Settings\SoftwareUpdateController;
use App\Http\Requests\Admin\SoftwareUpdateRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The environment screens tell the admin whether the .env file was written. A file the web user cannot open (a hardened install keeps it
 * read-only) used to make the writer return as if it had written, so the screen said "updated successfully" while APP_DEBUG stayed on, and the
 * licence form went on to contact the licence server with settings that were not saved.
 */
class EnvironmentSettingsScreenTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private string $directory;

    private string $licenceConfiguration;

    private string $licenceConfigurationBefore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        $this->licenceConfiguration = base_path('config/system-addons.php');
        $this->licenceConfigurationBefore = (string) file_get_contents($this->licenceConfiguration);
        $this->directory = sys_get_temp_dir() . '/env-screen-' . uniqid();
        mkdir($this->directory);
        $this->app->useEnvironmentPath($this->directory);
        $this->app->loadEnvironmentFrom('.env');
        $this->flushSession();
        $this->app['session']->start();
    }

    protected function tearDown(): void
    {
        file_put_contents($this->licenceConfiguration, $this->licenceConfigurationBefore);
        File::deleteDirectory($this->directory);
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** The file as a hardened install keeps it for the web user: something that cannot be opened for writing. */
    private function makeEnvironmentFileUnwritable(): void
    {
        mkdir($this->directory . '/.env');
    }

    private function makeEnvironmentFileWritable(): void
    {
        file_put_contents($this->directory . '/.env', "APP_DEBUG=true\nAPP_MODE=dev\nFORCE_HTTPS=false\n");
    }

    /** @return array{type: string, message: string} */
    private function lastToast(): array
    {
        $toasts = $this->app['session.store']->get('laravel-toaster-magic::messages');

        return end($toasts);
    }

    /** @return array<string, array{0: string, 1: array<string, string>}> the screen, and what it is sent */
    public static function switches(): array
    {
        return [
            'debug and mode' => ['update', ['app_debug' => 'false', 'app_mode' => 'live']],
            'force https' => ['updateForceHttps', ['force_https' => '1']],
        ];
    }

    /** @param array<string, string> $input */
    #[DataProvider('switches')]
    public function test_a_switch_says_it_failed_when_the_environment_file_cannot_be_written(string $screen, array $input): void
    {
        $this->makeEnvironmentFileUnwritable();

        app(EnvironmentSettingsController::class)->{$screen}(Request::create('/', 'POST', $input));

        $this->assertSame('error', $this->lastToast()['type']);
        $this->assertSame(translate('environment_variables_updated_failed'), $this->lastToast()['message']);
    }

    /** @param array<string, string> $input */
    #[DataProvider('switches')]
    public function test_a_switch_says_it_succeeded_when_the_environment_file_is_written(string $screen, array $input): void
    {
        $this->makeEnvironmentFileWritable();

        app(EnvironmentSettingsController::class)->{$screen}(Request::create('/', 'POST', $input));

        $this->assertSame('success', $this->lastToast()['type']);
        $this->assertSame(translate('environment_variables_updated_successfully'), $this->lastToast()['message']);
    }

    public function test_the_licence_form_stops_before_it_contacts_the_licence_server_when_the_environment_file_cannot_be_written(): void
    {
        $this->makeEnvironmentFileUnwritable();
        Http::fake();
        $request = SoftwareUpdateRequest::create('/', 'POST', ['name' => 'Admin', 'email' => 'admin@shop.test', 'username' => 'buyer', 'purchase_key' => '11111111-2222-3333-4444-555555555555']);

        $failure = null;
        try {
            app(SoftwareUpdateController::class)->update($request);
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }

        $this->assertNotNull($failure, 'the licence form went on although its settings were not saved');
        Http::assertNothingSent();
        $this->assertSame($this->licenceConfigurationBefore, file_get_contents($this->licenceConfiguration));
    }
}
