<?php

namespace Tests\Feature\Security;

use App\Services\Settings\MaskedCredentials;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A stored gateway or SMS credential is shown back to the panel as a mask rather than as itself, and a form that was shown a mask gives the
 * credential back unchanged (G-16).
 *
 * The two screens render every value of `addon_settings.live_values` into an input, so a live installation had every API key, secret and
 * auth token of every configured gateway in the HTML of one panel page -- readable in the page source, in a screenshot, and by any employee
 * whose role reaches the third-party module.
 *
 * `gcc.staff.credentials.masked` ships OFF and the first group below is that: every value is shown exactly as it is shown today and the
 * save path hands the submitted set back key for key, which is the SHAPE of the answer and not only its contents -- a save that dropped or
 * added a key would be a different save even if every value it kept were right.
 *
 * The wiring of the two screens and the two save paths is asserted by scanning them, in the last group. Rendering either screen whole needs
 * the payment-gateway world, the currency table and the setup-guide cache, none of which this is about; what matters is that the credential
 * field of each form goes through this service and that each save path restores through it, and a file that stops doing either fails here.
 */
class CredentialsAreMaskedInTheAdminFormTest extends TestCase
{
    private const SECRET = 'sk_live_51H8wQ2eZvKYlo2C';

    /** The gateway form's own field, and the save path that writes what it posts. */
    private const WIRED = [
        'resources/views/admin-views/third-party/payment-method/_payment-gateways-offcanvas.blade.php',
        'resources/views/admin-views/third-party/sms-index.blade.php',
        'app/Http/Controllers/Admin/ThirdParty/PaymentMethodController.php',
        'app/Http/Controllers/Admin/ThirdParty/SMSModuleController.php',
    ];

    private MaskedCredentials $credentials;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credentials = app(MaskedCredentials::class);
    }

    /** @return array<string, array{0: string}> the key names the six markets' gateways and SMS services actually store secrets under */
    public static function credentialNames(): array
    {
        $names = [
            'api_key', 'secret_key', 'app_key', 'auth_token', 'account_sid', 'password', 'hash_key', 'private_key', 'client_secret',
            'callback_token', 'signature_key', 'salt_key', 'merchant_pin', 'credential_id',
        ];

        return array_combine($names, array_map(static fn (string $name): array => [$name], $names));
    }

    /** @return array<string, array{0: string}> what an operator has to be able to read */
    public static function configurationNames(): array
    {
        $names = ['gateway', 'mode', 'status', 'supported_country', 'gateway_title', 'public_key', 'publishable_key', 'sender_id', 'from', 'base_url', 'key_name'];

        return array_combine($names, array_map(static fn (string $name): array => [$name], $names));
    }

    // ---- the switch is off: the screens and the saves are what they are today --------------------------------------------

    public function test_the_switch_ships_off(): void
    {
        $this->assertFalse($this->credentials->isEnabled(), 'masking ships off');
    }

    #[DataProvider('credentialNames')]
    public function test_with_the_switch_off_a_credential_is_shown_as_itself(string $key): void
    {
        $this->assertSame(self::SECRET, $this->credentials->shown(key: $key, value: self::SECRET), "{$key} must be the field it is today");
    }

    /**
     * Off, a save is the save it is today in SHAPE as well as in contents: the same keys, in the same order, with the same values -- so a
     * screen that posts a mask (which off it cannot) is not quietly repaired either.
     */
    public function test_with_the_switch_off_the_submitted_set_is_handed_straight_back(): void
    {
        $submitted = ['gateway' => 'stripe', 'api_key' => MaskedCredentials::MASK, 'secret_key' => 'typed-by-hand', 'status' => 1];

        $restored = $this->credentials->restore(submitted: $submitted, stored: ['api_key' => self::SECRET]);

        $this->assertSame($submitted, $restored, 'off, nothing is restored and no key moves');
        $this->assertSame(array_keys($submitted), array_keys($restored), 'nor is a key added or dropped');
    }

    // ---- the switch is on ------------------------------------------------------------------------------------------------

    #[DataProvider('credentialNames')]
    public function test_with_the_switch_on_a_stored_credential_is_shown_as_a_mask(string $key): void
    {
        $this->switchItOn();

        $this->assertSame(MaskedCredentials::MASK, $this->credentials->shown(key: $key, value: self::SECRET), "{$key} is a credential");
    }

    #[DataProvider('configurationNames')]
    public function test_with_the_switch_on_configuration_is_still_shown(string $key): void
    {
        $this->switchItOn();

        $this->assertSame('readable', $this->credentials->shown(key: $key, value: 'readable'), "{$key} is configuration an operator reads");
    }

    /** A credential that is not set yet shows nothing, so an empty field still means "no credential". */
    public function test_with_the_switch_on_an_unset_credential_shows_nothing(): void
    {
        $this->switchItOn();

        $this->assertSame('', $this->credentials->shown(key: 'api_key', value: ''));
        $this->assertNull($this->credentials->shown(key: 'api_key', value: null));
    }

    /** A value that is not a string -- the supported-country list, a status -- is handed back as it came and never masked. */
    public function test_with_the_switch_on_a_value_that_is_not_text_is_untouched(): void
    {
        $this->switchItOn();

        $this->assertSame(['SA', 'AE'], $this->credentials->shown(key: 'supported_country', value: ['SA', 'AE']));
        $this->assertSame(1, $this->credentials->shown(key: 'status', value: 1));
    }

    /**
     * The half that makes the mask safe. Both screens write the whole submitted set into `live_values`, so a form saved without the
     * credential being retyped posts the mask -- and the stored secret has to come back, or pressing Save on a gateway's title would
     * silently destroy its key.
     */
    public function test_with_the_switch_on_a_masked_field_is_restored_from_the_stored_row(): void
    {
        $this->switchItOn();

        $restored = $this->credentials->restore(
            submitted: ['gateway' => 'stripe', 'api_key' => MaskedCredentials::MASK, 'gateway_title' => 'Stripe renamed'],
            stored: ['gateway' => 'stripe', 'api_key' => self::SECRET, 'gateway_title' => 'Stripe'],
        );

        $this->assertSame(self::SECRET, $restored['api_key'], 'the stored credential survives a save of another field');
        $this->assertSame('Stripe renamed', $restored['gateway_title'], 'and the edited field is still the edit');
        $this->assertSame(['gateway', 'api_key', 'gateway_title'], array_keys($restored), 'the set keeps its shape');
    }

    /** A credential the operator really did retype is saved, which is the whole point of the field still being editable. */
    public function test_with_the_switch_on_a_retyped_credential_is_saved(): void
    {
        $this->switchItOn();

        $restored = $this->credentials->restore(
            submitted: ['api_key' => 'sk_live_rotated'],
            stored: ['api_key' => self::SECRET],
        );

        $this->assertSame('sk_live_rotated', $restored['api_key']);
    }

    /** The mask is not a secret and must never become one: a row that holds no credential restores to empty, not to asterisks. */
    public function test_a_mask_posted_for_a_credential_nothing_is_stored_for_never_becomes_the_credential(): void
    {
        $this->switchItOn();

        foreach ([null, ['api_key' => null], []] as $stored) {
            $restored = $this->credentials->restore(submitted: ['api_key' => MaskedCredentials::MASK], stored: $stored);

            $this->assertSame('', $restored['api_key'], 'the mask is never stored as a credential');
        }
    }

    /** Configuration that happens to equal the mask is left alone: only a credential is restored, so a title of asterisks stays one. */
    public function test_a_configuration_field_equal_to_the_mask_is_not_restored(): void
    {
        $this->switchItOn();

        $restored = $this->credentials->restore(
            submitted: ['gateway_title' => MaskedCredentials::MASK],
            stored: ['gateway_title' => 'Stripe'],
        );

        $this->assertSame(MaskedCredentials::MASK, $restored['gateway_title']);
    }

    // ---- the wiring, scanned ---------------------------------------------------------------------------------------------

    /**
     * The two forms and the two save paths go through this service. Scanned rather than rendered, for the reason in the class docblock: a
     * field that stops asking, or a save that stops restoring, is the defect, and both are visible in the file.
     *
     * @return array<string, array{0: string}>
     */
    public static function wiredFiles(): array
    {
        return array_combine(self::WIRED, array_map(static fn (string $file): array => [$file], self::WIRED));
    }

    #[DataProvider('wiredFiles')]
    public function test_the_credential_screens_and_their_saves_go_through_the_one_service(string $file): void
    {
        $source = (string) file_get_contents(base_path($file));

        $this->assertStringContainsString('MaskedCredentials::class', $source, "{$file} must ask the one service what a credential field shows or keeps.");
    }

    /** Neither form may print a stored value straight into the field any more, which is the line the defect was on. */
    public function test_neither_credential_form_prints_a_stored_value_unmasked(): void
    {
        foreach (['resources/views/admin-views/third-party/payment-method/_payment-gateways-offcanvas.blade.php', 'resources/views/admin-views/third-party/sms-index.blade.php'] as $form) {
            $source = (string) file_get_contents(base_path($form));

            $this->assertStringNotContainsString('showDemoModeInputValue(value: $value)', $source, "{$form} prints a stored credential straight into the field.");
        }
    }

    private function switchItOn(): void
    {
        config()->set('gcc.staff.credentials.masked', true);
    }
}
