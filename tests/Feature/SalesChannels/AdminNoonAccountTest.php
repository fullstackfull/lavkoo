<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\SalesChannels\SalesChannel;
use App\Http\Controllers\Admin\SalesChannels\Support\AccountForm;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use App\Services\SalesChannels\Support\ChannelSettings;

/**
 * An administrator can create a noon account from the panel, and could not (H2-02).
 *
 * `SalesChannel::connectable()` has named noon since the connector was written, and the route accepts it -- but `AccountForm` had no
 * `noon` entry in `SECRET_FIELDS`, `SETTING_FIELDS` or the validation rules, so:
 *
 *  - `SECRET_FIELDS[$channel->value]` was an undefined index on the create page and on the account page, so the screens could not even be
 *    OPENED for noon;
 *  - the settings rules fell through to Trendyol's `else` branch, which requires an integration name, a response language and a Torod
 *    reference field -- none of which noon has -- so the form could never have been submitted either;
 *  - the channel chooser printed Trendyol's words on the noon card, because it asked `=== 'amazon' ? ... : trendyol`.
 *
 * So the channel was connectable in the enum and unreachable in the panel. noon's key file is three values from its Access app, and both
 * the country and the warehouse are part of the account's IDENTITY: a noon price belongs to a country and a noon stock figure to a
 * warehouse, so changing either leaves an earlier connection test describing something else (E12-F02).
 */
class AdminNoonAccountTest extends AdminSalesChannelsTestCase
{
    /** The words the noon screens ask `translate()` for and that no locale has yet. */
    private const WORDS = [
        'connect_noon', 'connect_noon_help', 'noon_merchant_code', 'noon_merchant_code_help', 'noon_country', 'noon_country_help',
        'noon_warehouse_code', 'noon_warehouse_code_help', 'use_the_noon_sandbox', 'noon_sandbox_help', 'read_fbn_inventory',
        'fbn_units_are_noons_own_stock',
    ];

    /** @return array<string, mixed> */
    private function noonForm(array $overrides = []): array
    {
        return array_replace_recursive([
            'channel' => 'noon', 'name' => 'noon KSA', 'environment' => 'production', 'seller_id' => 0, 'external_account_id' => 'MERCH-1', 'currency' => 'SAR',
            'settings' => ['country_code' => 'sa', 'warehouse_code' => 'RUH-1', 'use_sandbox' => 0, 'sync_fbn_inventory' => 0, 'route_cod_orders' => 0],
            'secrets' => ['key_id' => 'key-1', 'private_key' => '-----BEGIN PRIVATE KEY-----x-----END PRIVATE KEY-----', 'project_code' => 'proj-1'],
        ], $overrides);
    }

    /**
     * The words the noon screens need, which is the one half of this that code cannot supply.
     *
     * Twelve keys have no text in any locale, and `AdminPagesTestCase` fails any test that RENDERS a page holding an untranslated key --
     * `translate()` writes the key into `resources/lang/<locale>/new-messages.php` the first time it is asked for one, and that file is
     * hashed before and after every page test for exactly this reason. So this test does not render the noon screens; it names the keys,
     * and it turns green by itself on the day the text lands, which is better than a comment nobody re-reads.
     *
     * Everything else about a noon account -- the form, the validation, the secrets, the identity set -- is proved by the tests below
     * without rendering anything.
     */
    public function test_the_noon_screens_words_exist_in_every_locale(): void
    {
        $owed = [];

        foreach (['en', 'sa', 'ae'] as $locale) {
            $messages = require base_path("resources/lang/{$locale}/new-messages.php");

            foreach (self::WORDS as $key) {
                if (!array_key_exists($key, $messages) || trim((string) $messages[$key]) === '') {
                    $owed[] = "{$locale}: {$key}";
                }
            }
        }

        if ($owed !== []) {
            $this->markTestSkipped("the noon account screens cannot be rendered until these have text:\n" . implode("\n", $owed));
        }

        $this->get(route('admin.sales-channels.accounts.create'))->assertOk();
        $this->get(route('admin.sales-channels.accounts.create', ['channel' => 'noon']))->assertOk();

        $this->post(route('admin.sales-channels.accounts.store'), $this->noonForm())->assertRedirect();

        $this->get(route('admin.sales-channels.accounts.show', SalesChannelAccount::where('channel', 'noon')->firstOrFail()))->assertOk();
    }

    public function test_a_noon_account_keeps_its_key_file_its_country_and_its_warehouse(): void
    {
        $this->post(route('admin.sales-channels.accounts.store'), $this->noonForm())->assertSessionHasNoErrors();

        $account = SalesChannelAccount::where('channel', 'noon')->firstOrFail();
        $credentials = app(ChannelCredentialStore::class)->get($account);

        $this->assertSame(['MERCH-1', 'sa', 'RUH-1'], [$account->external_account_id, $account->setting('country_code'), $account->setting('warehouse_code')]);
        $this->assertSame('key-1', $credentials->secret('key_id'));
        $this->assertSame('proj-1', $credentials->secret('project_code'));
        $this->assertNotSame('', (string) $credentials->secret('private_key'));
        $this->assertFalse($account->enabled, 'a new account is created disabled, like every other channel');
    }

    public function test_a_country_noon_does_not_serve_is_refused_by_the_form(): void
    {
        $this->from(route('admin.sales-channels.accounts.create', ['channel' => 'noon']))
            ->post(route('admin.sales-channels.accounts.store'), $this->noonForm(['settings' => ['country_code' => 'kw']]))
            ->assertSessionHasErrors('settings.country_code');

        $this->assertSame(0, SalesChannelAccount::where('channel', 'noon')->count());
    }

    /** A warehouse code is optional, because noon publishes no way to list the warehouses: an account without one publishes no stock. */
    public function test_a_noon_account_without_a_warehouse_is_accepted(): void
    {
        $this->post(route('admin.sales-channels.accounts.store'), $this->noonForm(['settings' => ['warehouse_code' => '']]))->assertSessionHasNoErrors();

        $this->assertSame('', (string) SalesChannelAccount::where('channel', 'noon')->firstOrFail()->setting('warehouse_code'));
    }

    /** Trendyol's rules must not reach noon: an account with none of Trendyol's fields is still a valid noon account. */
    public function test_a_noon_account_is_not_judged_by_trendyols_rules(): void
    {
        $form = $this->noonForm();
        unset($form['settings']['use_sandbox']);

        $this->post(route('admin.sales-channels.accounts.store'), $form)->assertSessionHasNoErrors();
    }

    /**
     * The declared storefront country joined this list on 2026-11-26 (H2-06): it is part of every channel account's identity, and the
     * update action compares this list before and after to tell the operator what the change withdrew. The two noon fields this test was
     * written for are unchanged.
     */
    public function test_the_country_and_the_warehouse_are_part_of_the_accounts_identity(): void
    {
        $this->assertSame([ChannelSettings::MARKET, 'country_code', 'warehouse_code'], AccountForm::IDENTITY_SETTINGS[SalesChannel::Noon->value]);
        $this->assertSame(['key_id', 'private_key', 'project_code'], AccountForm::SECRET_FIELDS[SalesChannel::Noon->value]);
    }

    /** Every connectable channel has a form, which is the check that would have caught this one. */
    public function test_every_connectable_channel_has_the_maps_a_form_needs(): void
    {
        foreach (SalesChannel::connectable() as $channel) {
            $this->assertArrayHasKey($channel->value, AccountForm::SECRET_FIELDS, "{$channel->value} has no secret fields, so its account screens cannot be opened");
            $this->assertArrayHasKey($channel->value, AccountForm::IDENTITY_SETTINGS, "{$channel->value} has no identity settings, so the account page cannot say what a test was about");
        }
    }
}
