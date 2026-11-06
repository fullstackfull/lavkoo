<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\SalesChannels\IntegrationCapability;
use App\Http\Controllers\Admin\SalesChannels\ChannelAccountController;
use App\Http\Controllers\Admin\SalesChannels\Support\CapabilityLadder;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\ChannelConnectorRegistry;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/** Creating an account, editing it, and climbing the activation ladder: every refusal the service gives is shown, and no secret is ever shown. */
class AdminSalesChannelsAccountsTest extends AdminSalesChannelsTestCase
{
    // ---- forms --------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function amazonForm(array $overrides = []): array
    {
        return array_replace_recursive([
            'channel' => 'amazon', 'name' => 'Amazon KSA', 'environment' => 'production', 'seller_id' => 0, 'external_account_id' => 'A1SELLER', 'currency' => 'SAR',
            'settings' => ['region' => 'eu', 'marketplace_ids' => 'A17E79C6D8DWNP', 'sync_fba_inventory' => 1, 'request_buyer_data' => 0, 'auto_link_exact_sku' => 0, 'route_cod_orders' => 0],
            'secrets' => ['lwa_client_id' => 'amzn1.application-oa2-client.test', 'lwa_client_secret' => 'client-secret-xyz', 'refresh_token' => 'Atzr|refresh-token-abc'],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function trendyolForm(array $overrides = []): array
    {
        return array_replace_recursive([
            'channel' => 'trendyol', 'name' => 'Trendyol KSA', 'environment' => 'production', 'seller_id' => 0, 'external_account_id' => '1234567', 'currency' => 'SAR',
            'settings' => [
                'storefront_code' => 'SA', 'integration_name' => 'PharmacyHub', 'accept_language' => 'ar', 'torod_reference_field' => 'order_number',
                'auto_link_exact_barcode' => 0, 'prices_include_tax' => 1, 'route_cod_orders' => 0, 'carrier_codes' => "smsa=SMSA\nAramex = aramex",
            ],
            'secrets' => ['api_key' => 'key-abc-123', 'api_secret' => 'secret-xyz-789'],
        ], $overrides);
    }

    private function stored(SalesChannelAccount $account, string $key): ?string
    {
        return app(ChannelCredentialStore::class)->get($account->fresh())->secret($key);
    }

    /** An account exactly as the create form makes it: disabled, nothing switched on. */
    private function freshAccount(array $form = null): SalesChannelAccount
    {
        $this->post(route('admin.sales-channels.accounts.store'), $form ?? $this->trendyolForm())->assertRedirect();

        return SalesChannelAccount::latest('id')->firstOrFail();
    }

    // ---- creating -----------------------------------------------------------------------------------------

    public function test_the_create_page_offers_both_channels_first(): void
    {
        $html = $this->get(route('admin.sales-channels.accounts.create'))->assertOk()->getContent();

        $this->assertStringContainsString(route('admin.sales-channels.accounts.create', ['channel' => 'amazon']), $html);
        $this->assertStringContainsString(route('admin.sales-channels.accounts.create', ['channel' => 'trendyol']), $html);
        $this->assertStringNotContainsString('name="secrets[', $html, 'no credential field before a channel is chosen');
    }

    public function test_each_channel_has_its_own_fields_and_every_secret_field_is_empty(): void
    {
        $amazon = $this->get(route('admin.sales-channels.accounts.create', ['channel' => 'amazon']))->assertOk()->getContent();
        foreach (['name="settings[region]"', 'name="settings[marketplace_ids]"', 'name="settings[sync_fba_inventory]"', 'name="settings[request_buyer_data]"', 'name="settings[auto_link_exact_sku]"',
            'name="secrets[lwa_client_id]"', 'name="secrets[lwa_client_secret]"', 'name="secrets[refresh_token]"', 'name="environment"', 'name="external_account_id"'] as $field) {
            $this->assertStringContainsString($field, $amazon);
        }
        $this->assertStringNotContainsString('name="secrets[api_key]"', $amazon);

        $trendyol = $this->get(route('admin.sales-channels.accounts.create', ['channel' => 'trendyol']))->assertOk()->getContent();
        foreach (['name="settings[storefront_code]"', 'name="settings[integration_name]"', 'name="settings[accept_language]"', 'name="settings[torod_reference_field]"', 'name="settings[carrier_codes]"',
            'name="settings[auto_link_exact_barcode]"', 'name="settings[prices_include_tax]"', 'name="settings[route_cod_orders]"', 'name="secrets[api_key]"', 'name="secrets[api_secret]"'] as $field) {
            $this->assertStringContainsString($field, $trendyol);
        }
        $this->assertSame(2, preg_match_all('/<input[^>]*type="password"[^>]*name="secrets\[[a-z_]+\]"[^>]*value=""/', $trendyol), 'both secrets are password inputs with nothing in them');
    }

    public function test_creating_an_account_stores_it_disabled_with_nothing_switched_on(): void
    {
        $response = $this->post(route('admin.sales-channels.accounts.store'), $this->amazonForm());
        $account = SalesChannelAccount::firstOrFail();

        $response->assertRedirect(route('admin.sales-channels.accounts.show', $account));
        $this->assertFalse($account->enabled, 'a new account does nothing');
        $this->assertFalse($account->write_enabled);
        $this->assertTrue($account->dry_run, 'rehearsal is the default');
        $this->assertSame([], $account->capabilities);
        $this->assertSame([], $account->capability_approvals);
        $this->assertSame('untested', $account->connection_status);
        $this->assertSame(0, $account->seller_id, 'the platform\'s own account');
        $this->assertSame(['amazon', 'production', 'A1SELLER', ['A17E79C6D8DWNP'], 'SAR', 1], [$account->channel, $account->environment, $account->external_account_id, $account->marketplace_ids, $account->currency, $account->created_by]);
        $this->assertSame('eu', $account->setting('region'));
        $this->assertSame(['lwa_client_id' => true, 'lwa_client_secret' => true, 'refresh_token' => true], Arr::only(app(ChannelCredentialStore::class)->get($account)->presence(), ['lwa_client_id', 'lwa_client_secret', 'refresh_token']));
        $this->assertToastContains(translate('channel_account_created_disabled'));
        $this->assertNoSecretIn(json_encode(session()->all()), 'the session');
    }

    public function test_a_trendyol_account_keeps_its_settings_and_carrier_codes_as_a_map(): void
    {
        $account = $this->freshAccount();

        $this->assertSame(['smsa' => 'SMSA', 'aramex' => 'ARAMEX'], $account->setting('carrier_codes'), 'one carrier=CODE per line, the carrier lower case, the code upper case');
        $this->assertSame(['SA', 'PharmacyHub', 'ar', 'order_number', false, true, false], [
            $account->setting('storefront_code'), $account->setting('integration_name'), $account->setting('accept_language'), $account->setting('torod_reference_field'),
            $account->setting('auto_link_exact_barcode'), $account->setting('prices_include_tax'), $account->setting('route_cod_orders'),
        ]);
    }

    public function test_the_platform_admin_can_create_an_account_for_a_seller_that_exists(): void
    {
        Schema::create('sellers', static function (Blueprint $table): void {
            $table->id();
            $table->softDeletes();
        });
        DB::table('sellers')->insert(['id' => 7]);

        $this->post(route('admin.sales-channels.accounts.store'), $this->trendyolForm(['seller_id' => 7]))->assertRedirect();

        $this->assertSame(7, SalesChannelAccount::firstOrFail()->seller_id);
        $this->post(route('admin.sales-channels.accounts.store'), $this->trendyolForm(['seller_id' => 8, 'external_account_id' => '999']))->assertSessionHasErrors('seller_id');
        $this->assertSame(1, SalesChannelAccount::count(), 'an account for a seller nobody knows is not created');
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: string}> */
    public static function invalidForms(): array
    {
        return [
            'no name' => ['trendyol', ['name' => ''], 'name'],
            'unknown environment' => ['trendyol', ['environment' => 'staging'], 'environment'],
            'seller id that is not a number (Trendyol)' => ['trendyol', ['external_account_id' => 'abc'], 'external_account_id'],
            'no seller id' => ['amazon', ['external_account_id' => ''], 'external_account_id'],
            'currency of four letters' => ['trendyol', ['currency' => 'SARX'], 'currency'],
            'storefront that does not exist' => ['trendyol', ['settings' => ['storefront_code' => 'XX']], 'settings.storefront_code'],
            'integration name with a space' => ['trendyol', ['settings' => ['integration_name' => 'not valid']], 'settings.integration_name'],
            'response language that does not exist' => ['trendyol', ['settings' => ['accept_language' => 'fr']], 'settings.accept_language'],
            'torod reference field that does not exist' => ['trendyol', ['settings' => ['torod_reference_field' => 'colour']], 'settings.torod_reference_field'],
            'carrier line without an equals sign' => ['trendyol', ['settings' => ['carrier_codes' => 'smsa SMSA']], 'settings.carrier_codes'],
            'negative platform seller' => ['trendyol', ['seller_id' => -1], 'seller_id'],
            'amazon region that does not exist' => ['amazon', ['settings' => ['region' => 'asia']], 'settings.region'],
            'no marketplace' => ['amazon', ['settings' => ['marketplace_ids' => '']], 'settings.marketplace_ids'],
            'marketplace id that is not one' => ['amazon', ['settings' => ['marketplace_ids' => 'not an id!']], 'settings.marketplace_ids.0'],
        ];
    }

    #[DataProvider('invalidForms')]
    public function test_bad_input_is_rejected_with_the_field_it_is_about_and_nothing_is_created(string $channel, array $overrides, string $field): void
    {
        $form = $channel === 'amazon' ? $this->amazonForm($overrides) : $this->trendyolForm($overrides);
        if (($overrides['settings']['marketplace_ids'] ?? null) === '') {
            $form['settings']['marketplace_ids'] = '';
        }

        $this->from(route('admin.sales-channels.accounts.create', ['channel' => $channel]))->post(route('admin.sales-channels.accounts.store'), $form)
            ->assertRedirect(route('admin.sales-channels.accounts.create', ['channel' => $channel]))
            ->assertSessionHasErrors($field);

        $this->assertSame(0, SalesChannelAccount::count());
    }

    public function test_a_channel_that_cannot_be_connected_is_not_found(): void
    {
        $this->post(route('admin.sales-channels.accounts.store'), $this->trendyolForm(['channel' => 'storefront']))->assertNotFound();
        $this->post(route('admin.sales-channels.accounts.store'), ['channel' => 'bogus'])->assertNotFound();
    }

    public function test_one_seller_id_is_one_account_per_channel_and_environment(): void
    {
        $this->freshAccount();

        $this->post(route('admin.sales-channels.accounts.store'), $this->trendyolForm())->assertSessionHasErrors('external_account_id');
        $this->post(route('admin.sales-channels.accounts.store'), $this->trendyolForm(['environment' => 'sandbox']))->assertSessionHasNoErrors();
        $this->assertSame(2, SalesChannelAccount::count(), 'the same seller id in the stage environment is another account');
    }

    public function test_a_rejected_form_comes_back_without_any_secret(): void
    {
        $response = $this->from(route('admin.sales-channels.accounts.create', ['channel' => 'amazon']))->post(route('admin.sales-channels.accounts.store'), $this->amazonForm(['name' => '']));

        $response->assertSessionHasErrors('name');
        $this->assertSame('A1SELLER', session()->getOldInput('external_account_id'), 'what was typed is given back');
        $this->assertNull(session()->getOldInput('secrets'), 'a secret is not');
        $this->assertNoSecretIn(json_encode(session()->all()), 'the session');
        $this->assertNoSecretIn($this->get(route('admin.sales-channels.accounts.create', ['channel' => 'amazon']))->getContent(), 'the form that came back');
    }

    // ---- reading ------------------------------------------------------------------------------------------

    public function test_the_account_page_never_shows_a_stored_secret_and_says_which_are_set(): void
    {
        foreach ([$this->trendyolAccount(), $this->amazonAccount()] as $account) {
            $html = $this->get(route('admin.sales-channels.accounts.show', $account))->assertOk()->getContent();

            $this->assertNoSecretIn($html, 'the page of ' . $account->name);
            $this->assertStringContainsString(e(translate('secret_is_set')), $html);
            $this->assertStringContainsString('name="clear_secrets[]"', $html, 'every stored secret can be cleared on purpose');
            $this->assertSame(0, preg_match('/<input[^>]*type="password"[^>]*value="[^"]/', $html), 'a password input never carries a value');
        }
    }

    public function test_a_secret_that_is_not_stored_says_so_and_cannot_be_cleared(): void
    {
        $account = $this->freshAccount($this->trendyolForm(['secrets' => ['api_secret' => '']]));

        $html = $this->get(route('admin.sales-channels.accounts.show', $account))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('secret_is_not_set')), $html);
        $this->assertSame(1, substr_count($html, 'name="clear_secrets[]"'), 'only the secret that is stored (the key) offers to be cleared');
        $this->assertStringContainsString('value="api_key"', $html);
    }

    public function test_the_account_page_says_what_each_channel_can_and_cannot_do(): void
    {
        foreach ([$this->trendyolAccount(), $this->amazonAccount()] as $account) {
            $html = $this->get(route('admin.sales-channels.accounts.show', $account))->assertOk()->getContent();

            foreach (app(ChannelConnectorRegistry::class)->for($account)->capabilityMatrix() as $feature => $entry) {
                $this->assertStringContainsString(e($entry['note']), $html, "{$account->channel}: the note of {$feature}");
            }
        }
        $trendyol = $this->get(route('admin.sales-channels.accounts.show', SalesChannelAccount::where('channel', 'trendyol')->firstOrFail()))->getContent();
        $this->assertStringContainsString(e(translate('capability_matrix_product_create_update')), $trendyol);
        $this->assertStringContainsString(e(translate('not_built')), $trendyol, 'what is not built is said, not left out');
        $this->assertStringContainsString(e(translate('no_not_documented')), $trendyol, 'and so is what the channel does not document');
    }

    public function test_the_page_states_the_write_state_in_words(): void
    {
        $dryRun = $this->trendyolAccount(writes: false);
        $html = $this->get(route('admin.sales-channels.accounts.show', $dryRun))->assertOk()->getContent();
        $this->assertStringContainsString(e(translate('rehearsal_only_nothing_is_sent')), $html);
        $this->assertStringContainsString(e(translate('write_state_help_rehearsal')), $html);

        $writing = $this->amazonAccount(writes: true);
        $this->assertStringContainsString(e(translate('write_state_help_environment_read_only')), $this->get(route('admin.sales-channels.accounts.show', $writing))->getContent());

        $this->liveWritesOn();
        $live = $this->get(route('admin.sales-channels.accounts.show', $writing))->getContent();
        $this->assertStringContainsString(e(translate('write_state_help_live')), $live);
        $this->assertStringContainsString(e(translate('confirm_that_this_is_sent_for_real')), $live, 'a real send is confirmed next to the button');
        $this->assertStringNotContainsString(e(translate('rehearsal_publish_help')), $live);
    }

    // ---- editing ------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function update(array $overrides = []): array
    {
        return array_replace_recursive(Arr::except($this->trendyolForm(), ['channel', 'environment', 'seller_id']), ['secrets' => ['api_key' => '', 'api_secret' => '']], $overrides);
    }

    public function test_a_blank_secret_keeps_the_stored_one_and_a_typed_one_replaces_it(): void
    {
        $account = $this->trendyolAccount();
        $account = $this->connected($account);

        $this->post(route('admin.sales-channels.accounts.update', $account), $this->update(['name' => 'Renamed']))->assertRedirect(route('admin.sales-channels.accounts.show', $account));
        $this->assertSame('Renamed', $account->fresh()->name);
        $this->assertSame(['key-abc-123', 'secret-xyz-789'], [$this->stored($account, 'api_key'), $this->stored($account, 'api_secret')], 'a form that does not repeat a secret does not wipe it');
        $this->assertSame('ok', $account->fresh()->connection_status, 'nothing about the credentials changed, so the earlier test still stands');

        $this->post(route('admin.sales-channels.accounts.update', $account), $this->update(['secrets' => ['api_key' => 'brand-new-key-999']]));
        $this->assertSame(['brand-new-key-999', 'secret-xyz-789'], [$this->stored($account, 'api_key'), $this->stored($account, 'api_secret')]);
        $this->assertSame('untested', $account->fresh()->connection_status, 'new credentials have not been proven');
        $this->assertNoSecretIn($this->get(route('admin.sales-channels.accounts.show', $account))->getContent(), 'the page after a change');
        $this->assertStringNotContainsString('brand-new-key-999', $this->get(route('admin.sales-channels.accounts.show', $account))->getContent());
    }

    public function test_a_secret_is_removed_only_by_naming_it(): void
    {
        $account = $this->trendyolAccount();

        $this->post(route('admin.sales-channels.accounts.update', $account), $this->update(['clear_secrets' => ['api_secret']]))->assertRedirect();

        $this->assertNull($this->stored($account, 'api_secret'));
        $this->assertSame('key-abc-123', $this->stored($account, 'api_key'));
    }

    public function test_a_secret_cannot_be_typed_and_cleared_at_once(): void
    {
        $account = $this->trendyolAccount();

        $this->from(route('admin.sales-channels.accounts.show', $account))->post(route('admin.sales-channels.accounts.update', $account), $this->update(['secrets' => ['api_key' => 'typed-and-cleared-1'], 'clear_secrets' => ['api_key']]))
            ->assertSessionHasErrors('clear_secrets');

        $this->assertSame('key-abc-123', $this->stored($account, 'api_key'), 'nothing was changed');
    }

    public function test_a_rejected_edit_comes_back_without_the_secrets_typed_into_it(): void
    {
        $account = $this->trendyolAccount();

        $response = $this->from(route('admin.sales-channels.accounts.show', $account))->post(route('admin.sales-channels.accounts.update', $account), $this->update(['name' => '', 'secrets' => ['api_key' => 'typed-secret-4321', 'api_secret' => 'typed-secret-8765']]));

        $response->assertRedirect(route('admin.sales-channels.accounts.show', $account))->assertSessionHasErrors('name');
        $this->assertNull(session()->getOldInput('secrets'));
        $session = json_encode(session()->all());
        $this->assertStringNotContainsString('typed-secret-4321', $session);
        $this->assertStringNotContainsString('typed-secret-8765', $session);
        $this->assertStringNotContainsString('typed-secret-4321', $this->get(route('admin.sales-channels.accounts.show', $account))->getContent());
    }

    public function test_the_environment_and_the_amazon_marketplaces_are_fixed_once_the_account_exists(): void
    {
        $amazon = $this->amazonAccount();

        $form = Arr::except($this->amazonForm(['name' => 'Amazon renamed', 'environment' => 'sandbox', 'settings' => ['marketplace_ids' => 'ATVPDKIKX0DER']]), ['channel']);
        $form['secrets'] = ['lwa_client_id' => '', 'lwa_client_secret' => '', 'refresh_token' => ''];
        $this->post(route('admin.sales-channels.accounts.update', $amazon), $form)->assertRedirect();

        $amazon->refresh();
        $this->assertSame('Amazon renamed', $amazon->name);
        $this->assertSame(['production', ['A17E79C6D8DWNP'], 'eu'], [$amazon->environment, $amazon->marketplace_ids, $amazon->setting('region')], 'a different environment is a different account');
        $this->assertSame(['A17E79C6D8DWNP'], $amazon->setting('marketplace_ids'));
    }

    public function test_changing_the_seller_id_warns_that_the_connection_test_is_about_the_old_one(): void
    {
        $account = $this->connected($this->trendyolAccount());

        $this->post(route('admin.sales-channels.accounts.update', $account), $this->update(['external_account_id' => '7654321']));

        $this->assertToastContains(translate('account_identity_changed_withdrawn'));
        $this->assertSame('7654321', $account->fresh()->external_account_id);
        $this->assertSame('untested', $account->fresh()->connection_status, 'the earlier connection test described another seller');

        $this->forgetToasts();
        $this->post(route('admin.sales-channels.accounts.update', $account), $this->update(['external_account_id' => '7654321', 'name' => 'Only a name']));
        $this->assertStringNotContainsString(translate('account_identity_changed_withdrawn'), implode(' ', $this->flashedToasts()));
    }

    public function test_the_account_default_for_orders_no_rule_decides_about_is_chosen_on_the_form(): void
    {
        $account = $this->freshAccount();
        $this->assertSame('manual_review', $account->execution_policy, 'the standard: nothing happens until a person decides');

        $this->get(route('admin.sales-channels.accounts.show', $account))->assertOk()->assertSee('name="execution_policy"', false);

        $this->post(route('admin.sales-channels.accounts.update', $account), $this->update(['execution_policy' => 'externally_managed']))->assertRedirect();
        $this->assertSame('externally_managed', $account->fresh()->execution_policy);

        $this->post(route('admin.sales-channels.accounts.update', $account), $this->update(['name' => 'Only a name']));
        $this->assertSame('externally_managed', $account->fresh()->execution_policy, 'a form that does not send the field leaves it as it was');
    }

    public function test_an_account_can_not_default_to_creating_shipments(): void
    {
        $account = $this->freshAccount();

        $this->post(route('admin.sales-channels.accounts.update', $account), $this->update(['execution_policy' => 'managed']))->assertSessionHasErrors('execution_policy');
        $this->assertSame('manual_review', $account->fresh()->execution_policy);

        $this->post(route('admin.sales-channels.accounts.store'), $this->trendyolForm(['external_account_id' => '7777777', 'execution_policy' => 'managed']))->assertSessionHasErrors('execution_policy');
        $this->assertSame(1, SalesChannelAccount::count());
    }

    public function test_a_seller_id_already_connected_cannot_be_taken_by_another_account(): void
    {
        $first = $this->freshAccount();
        $second = $this->freshAccount($this->trendyolForm(['external_account_id' => '2222222', 'name' => 'Second']));

        $this->from(route('admin.sales-channels.accounts.show', $second))->post(route('admin.sales-channels.accounts.update', $second), $this->update(['external_account_id' => $first->external_account_id]))
            ->assertSessionHasErrors('external_account_id');
        $this->post(route('admin.sales-channels.accounts.update', $second), $this->update(['external_account_id' => '2222222', 'name' => 'Second renamed']))->assertSessionHasNoErrors();
    }

    // ---- switches -----------------------------------------------------------------------------------------

    public function test_an_account_is_enabled_and_disabled_by_its_own_switch(): void
    {
        $account = $this->freshAccount();

        $this->post(route('admin.sales-channels.accounts.toggle', ['account' => $account, 'action' => 'enable']))->assertRedirect(route('admin.sales-channels.accounts.show', $account));
        $this->assertTrue($account->fresh()->enabled);
        $this->post(route('admin.sales-channels.accounts.toggle', ['account' => $account, 'action' => 'disable']));
        $this->assertFalse($account->fresh()->enabled);
    }

    public function test_writes_cannot_be_allowed_before_a_connection_was_proven_and_the_service_says_so(): void
    {
        $account = $this->freshAccount();

        $this->post(route('admin.sales-channels.accounts.writes', $account), ['allowed' => 1])->assertRedirect();

        $this->assertFalse($account->fresh()->write_enabled);
        $this->assertToastContains('Run a successful connection test first.');

        $this->connected($account);
        $this->post(route('admin.sales-channels.accounts.writes', $account), ['allowed' => 1]);
        $this->assertTrue($account->fresh()->write_enabled);
        $this->post(route('admin.sales-channels.accounts.writes', $account), ['allowed' => 0]);
        $this->assertFalse($account->fresh()->write_enabled);
    }

    public function test_dry_run_is_turned_off_only_with_an_explicit_confirmation(): void
    {
        $account = $this->freshAccount();
        $this->assertTrue($account->dry_run);

        $this->from(route('admin.sales-channels.accounts.show', $account))->post(route('admin.sales-channels.accounts.dry-run', $account), ['dry_run' => 0])
            ->assertSessionHasErrorsIn('dry_run', 'confirm_live');
        $this->assertTrue($account->fresh()->dry_run, 'without the confirmation it is still a rehearsal');

        $this->post(route('admin.sales-channels.accounts.dry-run', $account), ['dry_run' => 0, 'confirm_live' => 1])->assertRedirect();
        $this->assertFalse($account->fresh()->dry_run);

        $this->post(route('admin.sales-channels.accounts.dry-run', $account), ['dry_run' => 1])->assertRedirect();
        $this->assertTrue($account->fresh()->dry_run, 'back to rehearsal needs no confirmation');
    }

    // ---- the ladder ---------------------------------------------------------------------------------------

    public function test_the_page_lays_out_every_step_with_what_it_needs_and_why_it_cannot_be_switched_on_yet(): void
    {
        $account = $this->freshAccount();

        $html = $this->get(route('admin.sales-channels.accounts.show', $account))->assertOk()->getContent();

        foreach (IntegrationCapability::cases() as $capability) {
            $this->assertStringContainsString(e(translate($capability->labelKey())), $html);
            $this->assertStringContainsString(e(translate('integration_capability_help_' . $capability->value)), $html);
            $this->assertStringContainsString('id="capability-' . $capability->value . '"', $html);
        }
        $this->assertStringContainsString(e(translate('ladder_blocker_requires')), $html, 'a step whose prerequisite is off says which');
        $this->assertStringContainsString(e(translate('account_is_disabled_nothing_is_sent')), $html);
        $this->assertSame(3, substr_count($html, e(translate('changes_things_at_the_channel'))), 'the three steps that change something at the channel are marked');
    }

    /** @return array<string, array{0: list<string>, 1: array<string, mixed>}> */
    public static function ladderStates(): array
    {
        $fresh = ['connection_status' => 'ok', 'connection_checked_at' => '+0 days', 'missing_permissions' => []];

        return [
            'brand new' => [[], ['connection_status' => 'untested']],
            'connection test on, never run' => [['connection_test'], ['connection_status' => 'untested']],
            'connection ok, test on' => [['connection_test'], $fresh],
            'read-only sync on' => [['connection_test', 'read_only_sync'], $fresh],
            'product sync on' => [['connection_test', 'read_only_sync', 'product_sync'], $fresh],
            'order import on' => [['connection_test', 'read_only_sync', 'order_import'], $fresh],
            'everything on' => [['connection_test', 'read_only_sync', 'product_sync', 'order_import', 'financial_reconciliation', 'inventory_publishing', 'price_publishing', 'fulfillment_dispatch'], $fresh],
            'connection test is old' => [['connection_test', 'read_only_sync', 'product_sync', 'order_import'], ['connection_status' => 'ok', 'connection_checked_at' => '-31 days', 'missing_permissions' => []]],
            'permissions are missing' => [['connection_test', 'read_only_sync', 'product_sync', 'order_import'], ['connection_status' => 'ok', 'connection_checked_at' => '+0 days', 'missing_permissions' => ['finance:read']]],
            'authorisation expired' => [['connection_test', 'read_only_sync'], ['connection_status' => 'auth_expired', 'connection_checked_at' => '-1 days', 'missing_permissions' => []]],
        ];
    }

    /** @param list<string> $held */
    #[DataProvider('ladderStates')]
    public function test_the_ladder_offers_a_step_exactly_when_the_service_would_accept_it(array $held, array $connection): void
    {
        $account = $this->freshAccount();
        $account->forceFill($connection + [])->forceFill([
            'capabilities' => $held,
            'connection_checked_at' => isset($connection['connection_checked_at']) ? now()->modify($connection['connection_checked_at']) : null,
        ])->save();
        $account = $account->fresh();

        foreach (app(CapabilityLadder::class)->rungs($account) as $rung) {
            if ($rung->held) {
                $this->assertFalse($rung->canSwitchOn());
                continue;
            }
            DB::beginTransaction();
            try {
                app(ChannelAccountService::class)->enableCapability($account->fresh(), $rung->capability, 1, 'Reviewed and approved');
                $accepted = true;
            } catch (ChannelUnavailableException) {
                $accepted = false;
            } finally {
                DB::rollBack();
            }
            $this->assertSame($accepted, $rung->canSwitchOn(), $rung->capability->value . ' with ' . json_encode($held) . ' and ' . json_encode($connection) . ': the page and the service disagree');
        }
    }

    public function test_switching_a_step_on_out_of_order_shows_the_service_message_and_changes_nothing(): void
    {
        $account = $this->freshAccount();

        $this->post(route('admin.sales-channels.accounts.capability', ['account' => $account, 'capability' => 'read_only_sync', 'action' => 'enable']))
            ->assertRedirect(route('admin.sales-channels.accounts.show', $account));

        $this->assertToastContains("Switch on 'connection_test' first.");
        $this->assertSame([], $account->fresh()->capabilities);
    }

    public function test_a_step_that_needs_a_proven_connection_says_so(): void
    {
        $account = $this->freshAccount();
        $this->post(route('admin.sales-channels.accounts.capability', ['account' => $account, 'capability' => 'connection_test', 'action' => 'enable']))->assertRedirect();
        $this->assertSame(['connection_test'], $account->fresh()->capabilities);

        $this->post(route('admin.sales-channels.accounts.capability', ['account' => $account, 'capability' => 'read_only_sync', 'action' => 'enable']));

        $this->assertToastContains('Run a successful connection test first.');
        $this->assertSame(['connection_test'], $account->fresh()->capabilities);
    }

    public function test_a_step_that_changes_things_at_the_channel_needs_a_reason_and_records_who_approved_it(): void
    {
        $account = $this->connected($this->freshAccount());
        $account->forceFill(['capabilities' => ['connection_test', 'read_only_sync', 'product_sync']])->save();
        $url = route('admin.sales-channels.accounts.capability', ['account' => $account, 'capability' => 'inventory_publishing', 'action' => 'enable']);

        $this->from(route('admin.sales-channels.accounts.show', $account))->post($url)->assertSessionHasErrorsIn('capability_inventory_publishing', 'approval_reason');
        $this->post($url, ['approval_reason' => 'abc'])->assertSessionHasErrorsIn('capability_inventory_publishing', 'approval_reason');
        $this->assertNotContains('inventory_publishing', $account->fresh()->capabilities, 'no reason, no approval');

        $this->post($url, ['approval_reason' => 'Reviewed the rehearsal output'])->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertContains('inventory_publishing', $account->capabilities);
        $this->assertSame([1, 'Reviewed the rehearsal output'], [$account->capability_approvals['inventory_publishing']['by'], $account->capability_approvals['inventory_publishing']['reason']]);
        $html = $this->get(route('admin.sales-channels.accounts.show', $account))->getContent();
        $this->assertStringContainsString('Reviewed the rehearsal output', $html);
        $this->assertStringContainsString(e(translate('approved')), $html);
    }

    public function test_an_old_connection_test_and_missing_permissions_stop_a_write_approval_and_say_why(): void
    {
        $account = $this->connected($this->freshAccount());
        $account->forceFill(['capabilities' => ['connection_test', 'read_only_sync', 'product_sync'], 'connection_checked_at' => now()->subDays(40)])->save();
        $url = route('admin.sales-channels.accounts.capability', ['account' => $account, 'capability' => 'price_publishing', 'action' => 'enable']);

        $this->post($url, ['approval_reason' => 'Reviewed the rehearsal output']);
        $this->assertToastContains('too old');
        $this->assertStringContainsString(e(translate('ladder_blocker_connection_stale')), $this->get(route('admin.sales-channels.accounts.show', $account))->getContent());

        $account->forceFill(['connection_checked_at' => now(), 'missing_permissions' => ['finance:read']])->save();
        $this->post($url, ['approval_reason' => 'Reviewed the rehearsal output']);
        $this->assertToastContains('finance:read');
        $this->assertStringContainsString(e(translate('ladder_blocker_missing_permissions')), $this->get(route('admin.sales-channels.accounts.show', $account))->getContent());
        $this->assertNotContains('price_publishing', $account->fresh()->capabilities);
    }

    public function test_switching_a_step_off_switches_off_those_that_need_it_and_withdraws_their_approvals(): void
    {
        $account = $this->connected($this->freshAccount());
        $account->forceFill([
            'capabilities' => ['connection_test', 'read_only_sync', 'product_sync', 'order_import', 'inventory_publishing', 'fulfillment_dispatch'],
            'capability_approvals' => ['inventory_publishing' => ['by' => 1, 'reason' => 'ok then'], 'fulfillment_dispatch' => ['by' => 1, 'reason' => 'ok then'], 'webhook_management' => ['by' => 1, 'reason' => 'ok then']],
        ])->save();
        $html = $this->get(route('admin.sales-channels.accounts.show', $account))->getContent();
        $this->assertStringContainsString(e(translate('this_also_switches_off')), $html, 'the page says what else goes off before the button is pressed');

        $this->post(route('admin.sales-channels.accounts.capability', ['account' => $account, 'capability' => 'product_sync', 'action' => 'disable']))->assertRedirect();

        $account->refresh();
        $this->assertEqualsCanonicalizing(['connection_test', 'read_only_sync', 'order_import', 'fulfillment_dispatch'], $account->capabilities, 'inventory publishing needed product sync');
        $this->assertArrayNotHasKey('inventory_publishing', $account->capability_approvals);
        $this->assertArrayHasKey('fulfillment_dispatch', $account->capability_approvals);
        $this->assertToastContains(translate('integration_capability_inventory_publishing'));
        $this->assertToastContains(translate('integration_capability_product_sync'));
        $this->assertArrayHasKey('webhook_management', $account->capability_approvals, 'an approval that is not about a step stays');

        $this->post(route('admin.sales-channels.accounts.capability', ['account' => $account, 'capability' => 'connection_test', 'action' => 'disable']));
        $this->assertSame([], $account->fresh()->capabilities, 'every step needs the connection test');
    }

    public function test_an_unknown_step_or_action_is_not_found(): void
    {
        $account = $this->freshAccount();

        $this->post(route('admin.sales-channels.accounts.capability', ['account' => $account, 'capability' => 'teleport', 'action' => 'enable']))->assertNotFound();
        $this->post(route('admin.sales-channels.accounts.capability', ['account' => $account, 'capability' => 'connection_test', 'action' => 'explode']))->assertNotFound();
        $this->post(route('admin.sales-channels.accounts.toggle', ['account' => $account, 'action' => 'explode']))->assertNotFound();
        $this->post(route('admin.sales-channels.accounts.approval', ['account' => $account, 'scope' => 'everything', 'action' => 'approve']))->assertNotFound();
        $this->post(route('admin.sales-channels.accounts.approval', ['account' => $account, 'scope' => 'webhook_management', 'action' => 'maybe']))->assertNotFound();
        $this->get(route('admin.sales-channels.accounts.show', 99999))->assertNotFound();
    }

    // ---- approvals ----------------------------------------------------------------------------------------

    public function test_a_kind_of_write_that_is_not_a_step_is_approved_with_a_reason_and_withdrawn_on_request(): void
    {
        $account = $this->connected($this->freshAccount());
        $approve = route('admin.sales-channels.accounts.approval', ['account' => $account, 'scope' => 'webhook_management', 'action' => 'approve']);

        $this->from(route('admin.sales-channels.accounts.show', $account))->post($approve)->assertSessionHasErrorsIn('approval_webhook_management', 'approval_reason');
        $this->assertArrayNotHasKey('webhook_management', $account->fresh()->capability_approvals);

        $this->post($approve, ['approval_reason' => 'Register the order webhook'])->assertSessionHasNoErrors();
        $this->assertSame([1, 'Register the order webhook'], [$account->fresh()->capability_approvals['webhook_management']['by'], $account->fresh()->capability_approvals['webhook_management']['reason']]);
        $this->assertStringContainsString('Register the order webhook', $this->get(route('admin.sales-channels.accounts.show', $account))->getContent());

        $this->post(route('admin.sales-channels.accounts.approval', ['account' => $account, 'scope' => 'webhook_management', 'action' => 'revoke']))->assertRedirect();
        $this->assertArrayNotHasKey('webhook_management', $account->fresh()->capability_approvals);
    }

    public function test_an_approval_needs_a_proven_connection_and_the_service_message_is_shown(): void
    {
        $account = $this->freshAccount();

        $this->post(route('admin.sales-channels.accounts.approval', ['account' => $account, 'scope' => 'product_writes', 'action' => 'approve']), ['approval_reason' => 'Push product content'])->assertRedirect();

        $this->assertToastContains('Run a successful connection test first.');
        $this->assertSame([], $account->fresh()->capability_approvals);
    }

    public function test_a_withdrawn_approval_leaves_the_step_on_but_says_that_its_writes_are_refused_until_it_is_approved_again(): void
    {
        $account = $this->connected($this->freshAccount());
        $account->forceFill(['capabilities' => ['connection_test', 'read_only_sync', 'product_sync', 'inventory_publishing'], 'capability_approvals' => ['inventory_publishing' => ['by' => 1, 'reason' => 'first approval']]])->save();

        $this->post(route('admin.sales-channels.accounts.approval', ['account' => $account, 'scope' => 'inventory_publishing', 'action' => 'revoke']))->assertRedirect();

        $account->refresh();
        $this->assertContains('inventory_publishing', $account->capabilities);
        $this->assertArrayNotHasKey('inventory_publishing', $account->capability_approvals);
        $this->assertStringContainsString(e(translate('approval_missing_writes_of_this_kind_are_refused')), $this->get(route('admin.sales-channels.accounts.show', $account))->getContent());

        $this->post(route('admin.sales-channels.accounts.approval', ['account' => $account, 'scope' => 'inventory_publishing', 'action' => 'approve']), ['approval_reason' => 'second approval, reviewed'])->assertSessionHasNoErrors();
        $this->assertSame('second approval, reviewed', $account->fresh()->capability_approvals['inventory_publishing']['reason']);
    }

    public function test_the_routes_of_the_account_screens_are_the_ones_the_sidebar_and_the_brief_name(): void
    {
        foreach (['index', 'accounts.create', 'accounts.store', 'accounts.show', 'accounts.update', 'accounts.toggle', 'accounts.capability', 'accounts.approval', 'accounts.writes', 'accounts.dry-run',
            'accounts.test', 'accounts.sync', 'accounts.publish', 'accounts.webhook', 'products.index', 'orders.index', 'routing.index', 'logs.index'] as $name) {
            $this->assertTrue(Route::has('admin.sales-channels.' . $name), $name);
        }
        $this->assertSame(['enable', 'disable'], ChannelAccountController::SWITCH_ACTIONS);
    }
}
