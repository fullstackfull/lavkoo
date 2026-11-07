<?php

namespace Tests\Feature\OperationalCosts;

use App\Http\Middleware\ModulePermissionMiddleware;
use App\Models\Admin;
use App\Models\SalesChannelAccount;
use Tests\Feature\SalesChannels\AdminSalesChannelsTestCase;

/**
 * The account pages need the Sales channels permission, but the setting that makes the putting back of returned units automatic decides something the Operational costs module owns
 * (automatic cost layers and reversals of the cost of goods): a role that cannot decide a return by hand cannot switch it on, off or leave it changed. The permission middleware is
 * left on: admin 4 holds only Sales channels, the master admin holds both.
 */
class AccountReturnSettingPermissionTest extends AdminSalesChannelsTestCase
{
    protected function middlewareToDisable(): array
    {
        return array_values(array_filter(parent::middlewareToDisable(), static fn (string $class): bool => $class !== ModulePermissionMiddleware::class));
    }

    /** @return array<string, mixed> the account form as the page posts it, with the switch as given */
    private function form(int|string|null $autoRestock, array $overrides = []): array
    {
        $settings = ['storefront_code' => 'SA', 'integration_name' => 'PharmacyHub', 'accept_language' => 'ar', 'torod_reference_field' => 'order_number', 'auto_link_exact_barcode' => 0, 'prices_include_tax' => 1, 'route_cod_orders' => 0, 'carrier_codes' => 'smsa=SMSA'];
        if ($autoRestock !== null) {
            $settings['auto_restock_accepted_returns'] = $autoRestock;
        }

        return array_replace_recursive([
            'name' => 'Trendyol KSA', 'external_account_id' => '1234567', 'currency' => 'SAR', 'settings' => $settings, 'secrets' => ['api_key' => '', 'api_secret' => ''],
        ], $overrides);
    }

    private function autoRestock(SalesChannelAccount $account): bool
    {
        return (bool) $account->fresh()->setting('auto_restock_accepted_returns');
    }

    public function test_a_role_without_operational_costs_cannot_switch_on_the_automatic_putting_back_of_units(): void
    {
        $account = $this->trendyolAccount();
        $this->actingAs(Admin::findOrFail(4), 'admin');

        $this->post(route('admin.sales-channels.accounts.update', $account), $this->form(1))->assertRedirect();

        $this->assertFalse($this->autoRestock($account), 'Putting units back is an operational-costs decision; switching it on needs that permission too.');
    }

    public function test_a_role_without_operational_costs_changes_the_rest_of_the_account_and_leaves_the_switch_as_it_was(): void
    {
        $account = $this->trendyolAccount();
        $account->forceFill(['settings' => array_replace((array) $account->settings, ['auto_restock_accepted_returns' => true])])->save();
        $this->actingAs(Admin::findOrFail(4), 'admin');

        $this->post(route('admin.sales-channels.accounts.update', $account), $this->form(0, ['name' => 'Renamed by channels']))->assertRedirect();

        $this->assertSame('Renamed by channels', $account->fresh()->name);
        $this->assertTrue($this->autoRestock($account), 'The page posted 0 for a switch it showed disabled: that is not a decision to switch it off.');
    }

    public function test_a_role_without_operational_costs_creates_an_account_with_the_switch_off(): void
    {
        $this->actingAs(Admin::findOrFail(4), 'admin');

        $this->post(route('admin.sales-channels.accounts.store'), $this->form(1, ['channel' => 'trendyol', 'environment' => 'production', 'seller_id' => 0, 'secrets' => ['api_key' => 'key-abc-123', 'api_secret' => 'secret-xyz-789']]))->assertRedirect();

        $this->assertFalse($this->autoRestock(SalesChannelAccount::firstOrFail()));
    }

    public function test_the_page_shows_the_switch_to_a_role_without_the_permission_but_not_changeable_and_says_why(): void
    {
        $account = $this->trendyolAccount();
        $account->forceFill(['settings' => array_replace((array) $account->settings, ['auto_restock_accepted_returns' => true])])->save();

        $this->actingAs(Admin::findOrFail(4), 'admin');
        $restricted = $this->get(route('admin.sales-channels.accounts.show', $account))->assertOk()->getContent();
        $this->get(route('admin.sales-channels.accounts.create', ['channel' => 'trendyol']))->assertOk()->assertSee('id="trendyol-auto-restock"', false)->assertSee('disabled', false);

        $this->actingAs(Admin::findOrFail(1), 'admin');
        $master = $this->get(route('admin.sales-channels.accounts.show', $account))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*id="trendyol-auto-restock"[^>]*checked[^>]*disabled|<input[^>]*id="trendyol-auto-restock"[^>]*disabled[^>]*checked/', $restricted, 'It shows what is set, and it cannot be changed here.');
        $this->assertStringContainsString(e(translate('needs_the_operational_costs_permission')), $restricted);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="trendyol-auto-restock"[^>]*disabled/', $master);
        $this->assertStringNotContainsString(e(translate('needs_the_operational_costs_permission')), $master);
    }

    public function test_the_master_admin_holds_both_permissions_and_changes_the_switch(): void
    {
        $account = $this->trendyolAccount();

        $this->post(route('admin.sales-channels.accounts.update', $account), $this->form(1))->assertRedirect();
        $this->assertTrue($this->autoRestock($account));

        $this->post(route('admin.sales-channels.accounts.update', $account), $this->form(0))->assertRedirect();
        $this->assertFalse($this->autoRestock($account));
    }
}
