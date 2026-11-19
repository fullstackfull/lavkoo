<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\SharedController;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use Illuminate\Http\Client\Request as LicenceRequest;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\TestCase;

/**
 * The activation request does nothing on a panel that is activated already.
 *
 * The activation form is shown only while the panel is not activated. The request that form posts never asked: it sent whatever
 * user name and purchase key it was given to the licence server and wrote the answer over the stored activation of the panel,
 * for a visitor who was not signed in, on a panel that was running. A request that arrived as local was not even sent to the
 * licence server; its values were stored as an activation.
 *
 * The stored activation is a tracked file that the running panel reads, so no test here writes it. The controller's read and
 * write of that file are replaced; its decision, the route, the address of the caller and the call to the licence server are
 * the real ones, and the licence server is a fake that no test can get past.
 */
class ActivationRequestTest extends TestCase
{
    use BuildsFulfillmentSchema;

    private const MASTER = 1;

    private const CATALOG_EMPLOYEE = 2;

    /** An address from the range reserved for documentation: a caller who is not the server itself. */
    private const A_PUBLIC_ADDRESS = '203.0.113.7';

    private const DEVELOPMENT_FLAG = 'DEVELOPMENT_ENVIRONMENT';

    private const SUBMITTED = ['username' => 'somebody', 'purchase_key' => 'a-key-somebody-typed'];

    /** @var list<array{string, array<string, mixed>}> every write of the stored activation the controller asked for */
    private array $written = [];

    /** @var array{mixed, mixed, string|false} */
    private array $developmentFlagBefore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCoreSchema();
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
        $this->developmentFlagBefore = [$_ENV[self::DEVELOPMENT_FLAG] ?? null, $_SERVER[self::DEVELOPMENT_FLAG] ?? null, getenv(self::DEVELOPMENT_FLAG)];
        $this->setTheDevelopmentFlag('false');
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['active' => base64_encode('1')])]);
    }

    protected function tearDown(): void
    {
        [$inEnv, $inServer, $inProcess] = $this->developmentFlagBefore;
        $this->restore($_ENV, $inEnv);
        $this->restore($_SERVER, $inServer);
        $inProcess === false ? putenv(self::DEVELOPMENT_FLAG) : putenv(self::DEVELOPMENT_FLAG . '=' . $inProcess);

        parent::tearDown();
    }

    /** @return array<string, array{int|null}> */
    public static function whoAsks(): array
    {
        return [
            'a visitor who is not signed in' => [null],
            'an employee' => [self::CATALOG_EMPLOYEE],
            'the first admin' => [self::MASTER],
        ];
    }

    #[DataProvider('whoAsks')]
    public function test_a_request_to_an_activated_panel_is_not_carried_out(?int $asking): void
    {
        $this->aPanelWhoseStoredActivationIs('1');

        $this->askingFrom(self::A_PUBLIC_ADDRESS, $asking)->post('/activation-check', self::SUBMITTED)->assertRedirect(url('/'));

        Http::assertNothingSent();
        $this->assertSame([], $this->written, 'the stored activation of a running panel must not be written by a request');
    }

    /** @return array<string, array{mixed}> */
    public static function howTheFlagWasStored(): array
    {
        return ['as text, which is how it is written' => ['1'], 'as a number' => [1], 'as a boolean' => [true]];
    }

    #[DataProvider('howTheFlagWasStored')]
    public function test_an_activated_panel_is_recognised_however_its_flag_was_stored(mixed $stored): void
    {
        $this->aPanelWhoseStoredActivationIs($stored);

        $this->askingFrom(self::A_PUBLIC_ADDRESS)->post('/activation-check', self::SUBMITTED)->assertRedirect(url('/'));

        Http::assertNothingSent();
        $this->assertSame([], $this->written);
    }

    /** @return array<string, array{string}> */
    public static function theServerItself(): array
    {
        return ['over IPv4' => ['127.0.0.1'], 'over IPv6' => ['::1']];
    }

    /**
     * A local caller is never asked to activate, so its request has nothing to do either. Before, it was the one request that
     * skipped the licence server and stored what it was given as an activation.
     */
    #[DataProvider('theServerItself')]
    public function test_a_request_that_arrives_as_local_is_not_carried_out(string $address): void
    {
        $this->aPanelWhoseStoredActivationIs('0');

        $this->askingFrom($address)->post('/activation-check', self::SUBMITTED)->assertRedirect(url('/'));

        Http::assertNothingSent();
        $this->assertSame([], $this->written);
    }

    public function test_a_request_to_a_development_installation_is_not_carried_out(): void
    {
        $this->setTheDevelopmentFlag('true');
        $this->aPanelWhoseStoredActivationIs('0');

        $this->askingFrom(self::A_PUBLIC_ADDRESS)->post('/activation-check', self::SUBMITTED)->assertRedirect(url('/'));

        Http::assertNothingSent();
        $this->assertSame([], $this->written);
    }

    public function test_a_panel_that_is_not_activated_is_still_activated_by_its_form(): void
    {
        $this->aPanelWhoseStoredActivationIs('0');

        $this->askingFrom(self::A_PUBLIC_ADDRESS)->post('/activation-check', self::SUBMITTED)->assertRedirect(url('/'));

        Http::assertSentCount(1);
        Http::assertSent(fn (LicenceRequest $sent): bool => $sent['username'] === self::SUBMITTED['username'] && $sent['purchase_key'] === self::SUBMITTED['purchase_key']);
        $this->assertCount(1, $this->written);
        [$application, $activation] = $this->written[0];
        $this->assertSame('admin_panel', $application);
        $this->assertSame('1', $activation['active']);
        $this->assertSame(self::SUBMITTED['username'], $activation['username']);
        $this->assertSame(self::SUBMITTED['purchase_key'], $activation['purchase_key']);
    }

    public function test_the_form_is_shown_while_the_panel_is_not_activated(): void
    {
        $this->aPanelWhoseStoredActivationIs('0');

        $this->askingFrom(self::A_PUBLIC_ADDRESS)->get('/activation-check')->assertOk()->assertViewIs('installation.activation-check');
    }

    public function test_the_form_is_not_shown_once_the_panel_is_activated(): void
    {
        $this->aPanelWhoseStoredActivationIs('1');

        $this->askingFrom(self::A_PUBLIC_ADDRESS)->get('/activation-check')->assertRedirect(url('/'));
    }

    /** The controller, with the file that stores the activation replaced by a value to read and a list of what was written. */
    private function aPanelWhoseStoredActivationIs(mixed $active): void
    {
        $this->partialMock(SharedController::class, function (MockInterface $controller) use ($active): void {
            $controller->shouldReceive('getAddonsConfig')->andReturn(['admin_panel' => ['active' => $active, 'username' => 'the-owner', 'purchase_key' => 'the-key-on-record']]);
            $controller->shouldReceive('updateActivationConfig')->andReturnUsing(function (string $app, array $response): void {
                $this->written[] = [$app, $response];
            });
        });
    }

    private function askingFrom(string $address, ?int $signedInAs = null): static
    {
        $asking = $this->withServerVariables(['REMOTE_ADDR' => $address]);

        return $signedInAs === null ? $asking : $asking->actingAs(Admin::findOrFail($signedInAs), 'admin');
    }

    private function setTheDevelopmentFlag(string $value): void
    {
        $_ENV[self::DEVELOPMENT_FLAG] = $value;
        $_SERVER[self::DEVELOPMENT_FLAG] = $value;
        putenv(self::DEVELOPMENT_FLAG . '=' . $value);
    }

    /** @param array<string, mixed> $source */
    private function restore(array &$source, mixed $before): void
    {
        if ($before === null) {
            unset($source[self::DEVELOPMENT_FLAG]);

            return;
        }
        $source[self::DEVELOPMENT_FLAG] = $before;
    }
}
