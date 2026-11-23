<?php

namespace Tests\Feature\MyFatoorah;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Models\Admin;
use App\Models\PaymentGatewayAccount;
use App\Services\Payments\GatewayAccountRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Tests\Support\RestoresLanguageFiles;

/**
 * D2-09: 'Each account shows its own webhook URL and secret status in the admin screen'.
 *
 * THE SCREEN, THROUGH ITS OWN ROUTE. GET /admin/third-party/payment-method/myfatoorah is driven as a signed-in administrator, so the answer comes
 * through the real controller, the real page and the real permission gate rather than from a partial handed its data by hand. A test that renders the
 * partial on its own proves the partial, and the criterion is about the screen: it was satisfied with the @include removed from the page, and with the
 * controller passing an empty list.
 *
 * EACH ACCOUNT'S OWN. The partial stamps every row with `mf-webhook-url-{id}`, and every assertion here is made INSIDE the row of a named account: this
 * account's row holds this account's address and this account's secret status. Substring assertions over the whole page were the second half of the
 * defect — "three addresses appear somewhere" is equally true when every account is shown the NEXT account's address.
 *
 * WHOSE SCREEN. The gateway's own screen shows the accounts of the gateway it is, so an account of another gateway has no row here: its webhook address
 * is registered in another portal, and an operator shown it on this page would register the wrong address.
 *
 * The admin shell and the licence check are stood down, as every admin page test in this project stands them down — they need the installed database.
 * The permission gate is NOT: it is what the criterion's second half is about. What the route itself requires is asserted from the route table instead,
 * so the two that are stood down here are still pinned.
 */
class CompanyAccountsOnTheAdminScreenTest extends CompanyAccountsTestCase
{
    use RestoresLanguageFiles;

    private const KUWAIT_WEBHOOK_SECRET = 'kuwait-account-webhook-secret';

    private const SAUDI_WEBHOOK_SECRET = 'saudi-account-webhook-secret';

    /** The administrator of the core fixture whose role carries only `3rd_party_setup` — the permission this screen sits behind. */
    private const THIRD_PARTY_ADMIN = 3;

    /** The administrator whose role carries only `catalog`, so this screen is not theirs. */
    private const CATALOG_ADMIN = 2;

    private PaymentGatewayAccount $kuwait;

    private PaymentGatewayAccount $saudi;

    private PaymentGatewayAccount $bare;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->configureGateway();
        $this->buildTheAdministrators();
        $this->installTheCompanyAndAccountTables();
        $this->turnCompanyAccountsOn();

        $this->kuwait = $this->anAccountFor(country: 'KW', webhookSecret: self::KUWAIT_WEBHOOK_SECRET, token: 'kuwait-webhook-token');
        $this->saudi = $this->anAccountFor(country: 'SA', webhookSecret: self::SAUDI_WEBHOOK_SECRET, token: 'saudi-webhook-token');
        $this->bare = $this->anAccountFor(country: 'AE', webhookSecret: null, token: 'bare-webhook-token');

        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        $this->withoutMiddleware([AdminMiddleware::class, ActivationCheckMiddleware::class]);
    }

    protected function tearDown(): void
    {
        $this->dropTheCompanyAndAccountTables();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** What the route requires, from the route table: the two gates this test stands down are still the route's own. */
    public function test_the_screen_sits_behind_an_administrator_the_licence_check_and_the_third_party_permission(): void
    {
        $gathered = $this->theScreensRoute()->gatherMiddleware();

        foreach (['web', 'admin', 'actch:admin_panel', 'module:3rd_party_setup'] as $required) {
            $this->assertContains($required, $gathered, 'the gateway screen no longer requires ' . $required);
        }
    }

    /** The criterion itself: one row per account, and each row carries the address and the secret status OF THAT ACCOUNT. */
    public function test_each_account_shows_its_own_webhook_url_and_its_own_secret_status(): void
    {
        $answer = $this->openTheScreenAs(self::THIRD_PARTY_ADMIN);
        $answer->assertOk();

        $rows = $this->accountRowsOf($answer->getContent());

        $this->assertSame(
            [(int) $this->bare->id, (int) $this->kuwait->id, (int) $this->saudi->id],
            array_keys($rows),
            'one row per account of this gateway, in the directory\'s own market order (AE, KW, SA), and no row belonging to nothing',
        );

        foreach ([[$this->kuwait, true], [$this->saudi, true], [$this->bare, false]] as [$account, $holdsASecret]) {
            $row = $rows[(int) $account->id];
            $address = '/api/payments/account/' . $account->webhook_path_token . '/webhook';

            $this->assertStringContainsString($address, $row, 'the row of account ' . $account->id . ' must carry ITS OWN address');
            $this->assertStringContainsString((string) $account->storefront_country, $row, 'and its own market');
            $this->assertStringContainsString('Lavkoo ' . $account->storefront_country . ' Trading Co.', $row, 'and its own company');

            $this->assertSame($holdsASecret, str_contains($row, '>Saved<'), 'the row of account ' . $account->id . ' must say whether IT holds a secret');
            $this->assertSame(!$holdsASecret, str_contains($row, '>Missing<'));

            foreach ($this->theOtherAccountsBeside($account) as $other) {
                $this->assertStringNotContainsString(
                    (string) $other->webhook_path_token,
                    $row,
                    'the row of account ' . $account->id . ' carries another account\'s address',
                );
            }
        }
    }

    /** The status of each secret, never the secret: the page says Saved and Missing and carries no value. */
    public function test_the_screen_carries_no_secret_of_any_account(): void
    {
        $body = $this->openTheScreenAs(self::THIRD_PARTY_ADMIN)->assertOk()->getContent();

        foreach ([self::KUWAIT_WEBHOOK_SECRET, self::SAUDI_WEBHOOK_SECRET] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $body, 'the screen must carry the status and never the secret');
        }

        foreach ([$this->kuwait, $this->saudi] as $account) {
            $stored = (string) DB::table('payment_gateway_accounts')->where('id', $account->id)->value('webhook_secret');

            $this->assertNotSame('', $stored);
            $this->assertStringNotContainsString($stored, (string) $body, 'nor the stored ciphertext of it');
        }
    }

    /**
     * This gateway's screen shows this gateway's accounts.
     *
     * An account of another gateway has a webhook address of its own and a portal of its own to register it in. Printing it here would have an operator
     * paste one gateway's address into another gateway's dashboard, and the delivery it then never receives is the kind of fault that is found months
     * later. The account built here is otherwise identical to the three above — same market shape, same secret, same token form.
     */
    public function test_an_account_of_another_gateway_has_no_row_on_this_gateways_screen(): void
    {
        $paymob = $this->anAccountFor(country: 'QA', webhookSecret: 'qatar-paymob-webhook-secret', token: 'qatar-paymob-token', gateway: 'paymob_accept');

        $body = (string) $this->openTheScreenAs(self::THIRD_PARTY_ADMIN)->assertOk()->getContent();

        $this->assertStringNotContainsString((string) $paymob->webhook_path_token, $body, 'another gateway\'s address must not be shown here');
        $this->assertArrayNotHasKey((int) $paymob->id, $this->accountRowsOf($body), 'and it must have no row of its own');
        $this->assertCount(3, $this->accountRowsOf($body), 'the three accounts of this gateway, and only those');
    }

    /**
     * Rendering the screen adds no key to the language files.
     *
     * translate() WRITES any key it does not know into resources/lang/<locale>/new-messages.php, and that file is include()d on every single request,
     * so a phrase this branch introduced without shipping its key would edit a shipped file on the first operator who opened the page — and leave the
     * working tree of whoever ran the suite dirty. The keys of the account block are shipped in all three tracked files; this is what says so.
     */
    public function test_rendering_the_screen_adds_no_key_to_the_language_files(): void
    {
        $before = $this->languageFiles();

        $this->openTheScreenAs(self::THIRD_PARTY_ADMIN)->assertOk();

        foreach ($this->languageFiles() as $path => $after) {
            $this->assertSame($before[$path], $after, 'rendering the gateway screen added an untranslated key to ' . $path);
        }
    }

    /** An administrator whose role does not carry this module does not reach the screen, and is shown no account. */
    public function test_an_administrator_without_the_third_party_permission_is_turned_away(): void
    {
        $answer = $this->openTheScreenAs(self::CATALOG_ADMIN);

        $answer->assertRedirect(route('admin.dashboard.index'));
        $this->assertStringNotContainsString((string) $this->kuwait->webhook_path_token, (string) $answer->getContent());
        $this->assertStringNotContainsString((string) $this->saudi->webhook_path_token, (string) $answer->getContent());
    }

    /** The master administrator holds every module, so the screen is theirs too. */
    public function test_the_master_administrator_sees_each_accounts_own_address(): void
    {
        $rows = $this->accountRowsOf($this->openTheScreenAs(1)->assertOk()->getContent());

        $this->assertStringContainsString('/api/payments/account/kuwait-webhook-token/webhook', $rows[(int) $this->kuwait->id]);
        $this->assertStringContainsString('/api/payments/account/saudi-webhook-token/webhook', $rows[(int) $this->saudi->id]);
    }

    /** Nobody signed in reaches nothing: the gate answers before the screen is built. */
    public function test_a_request_from_nobody_is_turned_away(): void
    {
        $answer = $this->get(route('admin.third-party.payment-method.myfatoorah.index'));

        $answer->assertStatus(302);
        $this->assertStringNotContainsString((string) $this->kuwait->webhook_path_token, (string) $answer->getContent());
    }

    /** With per-company accounts off the screen is what it is today: the page renders and holds no account block at all. */
    public function test_the_screen_shows_no_account_block_while_per_company_accounts_are_off(): void
    {
        config(['gcc.storefronts.payment_accounts.enabled' => false]);
        $this->forgetTheRegistry();

        $body = (string) $this->openTheScreenAs(self::THIRD_PARTY_ADMIN)->assertOk()->getContent();

        $this->assertSame([], $this->accountRowsOf($body));
        $this->assertStringNotContainsString('/api/payments/account/', $body, 'no per-company address is printed while the registry is off');
        $this->assertStringContainsString(route('myfatoorah.webhook'), $body, 'and the platform address is still the one on the screen');
    }

    /** @return array<string, string> each tracked language file, by its path relative to the project */
    private function languageFiles(): array
    {
        $files = [];

        foreach (['en', 'sa', 'ae'] as $locale) {
            $relative = "resources/lang/{$locale}/new-messages.php";
            $files[$relative] = (string) file_get_contents(base_path($relative));
        }

        return $files;
    }

    /** @return array<int, string> the markup of each account's row, keyed by the account id the row is stamped with */
    private function accountRowsOf(string $body): array
    {
        preg_match_all('/<tr>(?:(?!<\/tr>).)*<\/tr>/s', $body, $matches);

        $rows = [];

        foreach ($matches[0] as $row) {
            if (preg_match('/id="mf-webhook-url-(\d+)"/', $row, $stamp) === 1) {
                $rows[(int) $stamp[1]] = $row;
            }
        }

        return $rows;
    }

    /** @return list<PaymentGatewayAccount> */
    private function theOtherAccountsBeside(PaymentGatewayAccount $account): array
    {
        return array_values(array_filter(
            [$this->kuwait, $this->saudi, $this->bare],
            static fn (PaymentGatewayAccount $other): bool => (int) $other->id !== (int) $account->id,
        ));
    }

    private function openTheScreenAs(int $administrator): TestResponse
    {
        $this->actingAs(Admin::findOrFail($administrator), 'admin');

        return $this->get(route('admin.third-party.payment-method.myfatoorah.index'));
    }

    private function theScreensRoute(): LaravelRoute
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->getName() === 'admin.third-party.payment-method.myfatoorah.index') {
                return $route;
            }
        }

        $this->fail('the gateway screen has no route named admin.third-party.payment-method.myfatoorah.index');
    }

    private function forgetTheRegistry(): void
    {
        app(GatewayAccountRegistry::class)->flush();
    }

    /**
     * The three administrators of this project's core test fixture, with the same ids and the same roles.
     *
     * Built here rather than taken from BuildsFulfillmentSchema::buildCoreSchema(), which creates the payment and settings tables this suite has already
     * created from its own real migrations. The ids and the roles are deliberately the same ones, so a reader of either fixture reads the same world:
     * 1 is the master account, 2 holds only `catalog`, 3 holds only `3rd_party_setup`.
     */
    private function buildTheAdministrators(): void
    {
        Schema::dropIfExists('admins');
        Schema::dropIfExists('admin_roles');

        Schema::create('admin_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->text('module_access')->nullable();
            $table->boolean('status')->default(1);
            $table->timestamps();
        });

        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('admin_role_id')->default(1);
            $table->integer('status')->default(1);
            $table->timestamps();
        });

        DB::table('admin_roles')->insert([
            ['id' => 1, 'name' => 'Master Admin', 'module_access' => null, 'status' => 1],
            ['id' => self::CATALOG_ADMIN, 'name' => 'Catalog only', 'module_access' => json_encode(['catalog']), 'status' => 1],
            ['id' => self::THIRD_PARTY_ADMIN, 'name' => 'Third-party setup', 'module_access' => json_encode(['3rd_party_setup']), 'status' => 1],
        ]);

        DB::table('admins')->insert([
            ['id' => 1, 'name' => 'Admin', 'email' => 'master@example.test', 'admin_role_id' => 1],
            ['id' => self::CATALOG_ADMIN, 'name' => 'Catalog admin', 'email' => 'catalog@example.test', 'admin_role_id' => self::CATALOG_ADMIN],
            ['id' => self::THIRD_PARTY_ADMIN, 'name' => 'Integrations admin', 'email' => 'integrations@example.test', 'admin_role_id' => self::THIRD_PARTY_ADMIN],
        ]);
    }
}
