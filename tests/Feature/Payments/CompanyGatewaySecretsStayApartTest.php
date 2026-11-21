<?php

namespace Tests\Feature\Payments;

use App\Models\PaymentGatewayAccount;
use App\Services\Payments\GatewayAccountDirectory;
use App\Services\Payments\GatewayAccountRegistry;
use App\Services\Payments\GatewayAccountSecrets;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\BuildsCompanyGatewayAccounts;
use Tests\TestCase;

/**
 * A company's gateway credentials are the company's, the environment's, and nobody's to read (D2-08).
 *
 * Three separate claims, each of which this file refuses to take on trust:
 *
 *   - encrypted at rest: what is in the column is not the secret, and the secret is nowhere else;
 *   - test and live do not cross: an account row IS one environment, so there is no blob either could be fetched from;
 *   - never shown and never logged: no screen, no answer, no array, no log line and no console table carries a value.
 */
class CompanyGatewaySecretsStayApartTest extends TestCase
{
    use BuildsCompanyGatewayAccounts;

    private const LIVE_KEY = 'the-live-secret-key-of-kuwait';

    private const TEST_KEY = 'the-sandbox-secret-key-of-kuwait';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWalletTopUpWorld();
        $this->installTheCompanyAndAccountTables();
        $this->thePlatformPaymobRow();
        $this->turnCompanyAccountsOn();
    }

    protected function tearDown(): void
    {
        $this->dropTheCompanyAndAccountTables();

        parent::tearDown();
    }

    /** D2-08: 'Every gateway's credentials are encrypted per company account and environment'. */
    public function test_a_credential_is_stored_encrypted_and_is_nowhere_in_the_clear(): void
    {
        $account = $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::LIVE_KEY, 'hmac' => 'the-hmac'], environment: 'live');

        $stored = (string) DB::table('payment_gateway_accounts')->where('id', $account->id)->value('credentials');

        $this->assertStringNotContainsString(self::LIVE_KEY, $stored, 'the column holds ciphertext, not the key');
        $this->assertStringNotContainsString('the-hmac', $stored);
        $this->assertSame(self::LIVE_KEY, app(GatewayAccountSecrets::class)->read(account: $account, name: 'secret_key'), 'and the application can still read it');

        $everyColumn = implode('|', array_map(static fn (mixed $value): string => (string) $value, (array) DB::table('payment_gateway_accounts')->where('id', $account->id)->first()));

        $this->assertStringNotContainsString(self::LIVE_KEY, $everyColumn, 'no second column holds the plain value beside the ciphertext');
    }

    /** D2-08: 'test and live values can differ'. The defect in the shared admin form is that they cannot. */
    public function test_the_test_and_the_live_credentials_of_one_market_do_not_cross(): void
    {
        $live = $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::LIVE_KEY], environment: 'live');
        $test = $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::TEST_KEY], environment: 'test');
        $secrets = app(GatewayAccountSecrets::class);

        $this->assertSame(self::LIVE_KEY, $secrets->read(account: $live, name: 'secret_key'));
        $this->assertSame(self::TEST_KEY, $secrets->read(account: $test, name: 'secret_key'));

        $registry = app(GatewayAccountRegistry::class);

        $this->assertSame(self::LIVE_KEY, $secrets->read(account: $registry->forStorefront(country: 'KW', gateway: self::PAYMOB, environment: 'live'), name: 'secret_key'));
        $this->assertSame(self::TEST_KEY, $secrets->read(account: $registry->forStorefront(country: 'KW', gateway: self::PAYMOB, environment: 'test'), name: 'secret_key'));
    }

    /** A malformed environment is read as the sandbox one, so it can never reach the live account by accident. */
    public function test_an_environment_that_is_neither_test_nor_live_resolves_to_the_sandbox_account(): void
    {
        $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::LIVE_KEY], environment: 'live');
        $test = $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::TEST_KEY], environment: 'test');

        $resolved = app(GatewayAccountRegistry::class)->forStorefront(country: 'KW', gateway: self::PAYMOB, environment: 'LIVE ');

        $this->assertSame((int) $test->id, (int) $resolved?->id);
    }

    /** One market's secret is not another's, and the resolver is the only way in. */
    public function test_one_companys_secret_is_not_reachable_through_another_companys_account(): void
    {
        $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::LIVE_KEY], environment: 'live');
        $saudi = $this->anAccountFor(country: 'SA', secrets: ['secret_key' => 'the-live-secret-key-of-saudi-arabia'], environment: 'live');
        $secrets = app(GatewayAccountSecrets::class);

        $this->assertSame('the-live-secret-key-of-saudi-arabia', $secrets->read(account: $saudi, name: 'secret_key'));
        $this->assertNotSame(self::LIVE_KEY, $secrets->read(account: $saudi, name: 'secret_key'));

        $forSaudi = app(GatewayAccountRegistry::class)->forStorefront(country: 'SA', gateway: self::PAYMOB, environment: 'live');

        $this->assertSame((int) $saudi->id, (int) $forSaudi?->id, 'a request acting for Saudi Arabia resolves the Saudi account and no other');
    }

    /** The admin form posts a blank field for a credential the operator did not retype, and that must not clear a live key. */
    public function test_a_blank_save_keeps_the_stored_credential_and_clearing_one_is_asked_for_by_name(): void
    {
        $account = $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::LIVE_KEY, 'public_key' => 'the-public-key'], environment: 'live');
        $secrets = app(GatewayAccountSecrets::class);

        $secrets->put(account: $account, secrets: ['secret_key' => '', 'public_key' => null]);

        $this->assertSame(self::LIVE_KEY, $secrets->read(account: $account->refresh(), name: 'secret_key'));
        $this->assertSame('the-public-key', $secrets->read(account: $account, name: 'public_key'));

        $secrets->put(account: $account, secrets: [], clear: ['public_key']);

        $this->assertNull($secrets->read(account: $account->refresh(), name: 'public_key'));
        $this->assertSame(self::LIVE_KEY, $secrets->read(account: $account, name: 'secret_key'), 'clearing one leaves the others');
    }

    /** A name that is not a credential of a gateway account is not stored, so a stray form field cannot become one. */
    public function test_a_name_that_is_not_a_credential_is_not_stored(): void
    {
        $account = $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::LIVE_KEY], environment: 'live');
        $secrets = app(GatewayAccountSecrets::class);

        $secrets->put(account: $account, secrets: ['password' => 'not-a-gateway-credential']);

        $this->assertNull($secrets->read(account: $account->refresh(), name: 'password'));
        $this->assertStringNotContainsString('not-a-gateway-credential', (string) DB::table('payment_gateway_accounts')->where('id', $account->id)->value('credentials'));
    }

    /** Ciphertext this application cannot decrypt is absent, not handed back as if it were the value. */
    public function test_a_credential_that_will_not_decrypt_reads_as_absent(): void
    {
        $account = $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::LIVE_KEY], environment: 'live');
        DB::table('payment_gateway_accounts')->where('id', $account->id)->update(['credentials' => json_encode(['secret_key' => 'this-was-never-encrypted'])]);

        $this->assertNull(app(GatewayAccountSecrets::class)->read(account: $account->refresh(), name: 'secret_key'));
    }

    /** D2-08: 'never shown or logged'. Every surface that carries an account carries the names and not the values. */
    public function test_no_surface_carries_a_credential(): void
    {
        $account = $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::LIVE_KEY, 'webhook_secret' => 'the-webhook-secret-of-kuwait'], environment: 'live', webhookToken: 'kuwait-token');
        $directory = app(GatewayAccountDirectory::class);
        $row = (array) DB::table('payment_gateway_accounts')->where('id', $account->id)->first();
        $ciphertext = [(string) $row['credentials'], (string) $row['webhook_secret']];

        $surfaces = [
            'the model as an array' => json_encode($account->toArray()),
            'the model as JSON' => $account->toJson(),
            'the directory a screen reads' => json_encode($directory->rows()),
            'a fresh query of the table through the model' => PaymentGatewayAccount::query()->get()->toJson(),
        ];

        foreach ($surfaces as $what => $rendered) {
            $this->assertStringNotContainsString(self::LIVE_KEY, (string) $rendered, $what . ' must not carry a credential');
            $this->assertStringNotContainsString('the-webhook-secret-of-kuwait', (string) $rendered, $what . ' must not carry the signing secret');

            // Not only the value: the ciphertext of it is a credential leaving the application too, and the model is what keeps it in.
            foreach ($ciphertext as $stored) {
                $this->assertNotSame('', $stored);
                $this->assertStringNotContainsString($stored, (string) $rendered, $what . ' must not carry the stored ciphertext either');
            }
        }

        $listed = $directory->rows()[0];

        $this->assertSame(['secret_key'], $listed['secrets_held'], 'which credentials it holds, by name');
        $this->assertTrue($listed['has_webhook_secret'], 'and whether the signing secret is there');
    }

    /** The operator's own listing says what is held and never what it is. */
    public function test_the_console_listing_shows_the_names_and_never_the_values(): void
    {
        $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::LIVE_KEY, 'webhook_secret' => 'the-webhook-secret-of-kuwait'], environment: 'live', webhookToken: 'kuwait-token');

        $this->assertSame(0, Artisan::call('gcc:payment-account:list'));

        $printed = Artisan::output();

        $this->assertStringContainsString('secret_key', $printed, 'which credentials it holds, by name');
        $this->assertStringContainsString('saved', $printed, 'and whether the signing secret is there');
        $this->assertStringContainsString('kuwait-token', $printed, 'and its own webhook address');
        $this->assertStringNotContainsString(self::LIVE_KEY, $printed);
        $this->assertStringNotContainsString('the-webhook-secret-of-kuwait', $printed);
    }

    /** Writing a credential writes nothing to the log, whatever the log is set to. */
    public function test_writing_and_reading_a_credential_writes_nothing_to_the_log(): void
    {
        $written = [];
        Log::listen(static function (object $message) use (&$written): void {
            $written[] = $message->message . ' ' . json_encode($message->context);
        });

        $account = $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::LIVE_KEY], environment: 'live');
        app(GatewayAccountSecrets::class)->read(account: $account, name: 'secret_key');
        DB::table('payment_gateway_accounts')->where('id', $account->id)->update(['credentials' => json_encode(['secret_key' => 'this-was-never-encrypted'])]);
        app(GatewayAccountSecrets::class)->read(account: $account->refresh(), name: 'secret_key');

        $this->assertStringNotContainsString(self::LIVE_KEY, implode("\n", $written));
        $this->assertStringNotContainsString('this-was-never-encrypted', implode("\n", $written), 'not even the ciphertext of a value that would not decrypt');
    }

    /** The encryption is this application's own, so a dump of the table alone opens nothing. */
    public function test_the_ciphertext_is_this_applications_own(): void
    {
        $account = $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::LIVE_KEY], environment: 'live');
        $stored = json_decode((string) DB::table('payment_gateway_accounts')->where('id', $account->id)->value('credentials'), true);

        $this->assertSame(self::LIVE_KEY, Crypt::decryptString($stored['secret_key']), 'the application key is what opens it');
        $this->assertNotSame(self::LIVE_KEY, $stored['secret_key']);
    }
}
