<?php

namespace Tests\Feature\Address;

use App\Models\AddressCity;
use App\Models\AddressRegion;
use App\Models\BusinessSetting;
use App\Services\Address\AddressModeGate;
use App\Services\Address\AddressSchema;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The switch that turns the structured address on for the whole store: off / soft / strict, guarded so that it can never be turned on
 * where it would lock customers out (migrations missing, no city to choose) or be wrong (a ZIP allow-list that a National Address cannot satisfy).
 */
class AdminAddressModeTest extends AdminAddressTestCase
{
    private function setZipRestriction(bool $on): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => AddressModeGate::ZIP_RESTRICTION_SETTING], ['value' => json_encode($on ? 1 : 0)]);
        clearWebConfigCacheKeys();
    }

    private function switchMode(string $mode)
    {
        return $this->post(route('admin.address-system.mode.update'), ['mode' => $mode]);
    }

    public function test_the_administrator_moves_through_the_three_modes_and_each_change_is_recorded(): void
    {
        $this->signInWithTheCatalog();
        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::mode());

        foreach ([AddressSchema::MODE_SOFT, AddressSchema::MODE_STRICT, AddressSchema::MODE_OFF] as $mode) {
            $this->switchMode($mode)->assertRedirect(route('admin.address-system.index'));

            $this->assertSame($mode, AddressSchema::configuredMode());
            $this->assertSame($mode, AddressSchema::mode(), 'in force straight away');
        }

        $audit = DB::table('audit_logs')->where('action', 'address.mode_changed')->orderBy('id')->get();
        $this->assertCount(3, $audit);
        $this->assertSame(['mode' => 'off'], json_decode($audit[0]->before, true));
        $this->assertSame(['mode' => 'soft'], json_decode($audit[0]->after, true));
        $this->assertSame('admin', $audit[0]->actor_type);
        $this->assertSame(1, (int) $audit[0]->actor_id);
    }

    public function test_choosing_the_mode_that_is_already_chosen_changes_and_records_nothing(): void
    {
        $this->signInWithTheCatalog();
        $this->switchMode('soft');
        $recorded = count($this->auditActions());

        $this->switchMode('soft')->assertRedirect(route('admin.address-system.index'));

        $this->assertCount($recorded, $this->auditActions());
        $this->assertSame(AddressSchema::MODE_SOFT, AddressSchema::configuredMode());
    }

    public function test_soft_and_strict_are_refused_while_no_city_is_active_but_off_never_is(): void
    {
        $this->signIn();
        $this->assertFalse(AddressSchema::hasCities());

        foreach (['soft', 'strict'] as $mode) {
            $this->switchMode($mode)->assertRedirect(route('admin.address-system.index'));

            $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::configuredMode(), "{$mode} must not be saved");
            $this->assertContains(translate('address_mode_blocked_no_cities'), $this->toasts());
        }
        $this->assertSame([], $this->auditActions());

        $this->switchMode('off')->assertRedirect(route('admin.address-system.index'));
        $this->assertContains(translate('address_mode_saved'), $this->toasts());
    }

    public function test_a_catalog_whose_cities_are_all_switched_off_counts_as_having_none(): void
    {
        $this->signInWithTheCatalog();
        AddressCity::query()->update(['is_active' => false]);
        AddressSchema::flush();

        $this->switchMode('soft');

        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::configuredMode());
        $this->assertContains(translate('address_mode_blocked_no_cities'), $this->toasts());
    }

    public function test_a_catalog_whose_regions_are_all_switched_off_counts_as_having_no_city_nobody_could_choose(): void
    {
        $this->signInWithTheCatalog();
        AddressRegion::query()->update(['is_active' => false]);
        AddressSchema::flush();

        $this->assertSame(0, AddressRegion::query()->where('is_active', true)->count());
        $this->assertGreaterThan(0, AddressCity::query()->where('is_active', true)->count(), 'the cities themselves are still on');
        $this->assertFalse(AddressSchema::hasCities());

        $this->switchMode('soft');

        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::configuredMode());
        $this->assertContains(translate('address_mode_blocked_no_cities'), $this->toasts());
    }

    public function test_switching_off_the_last_region_holds_a_mode_that_is_on_at_off_and_switching_one_back_on_releases_it(): void
    {
        $this->signInWithTheCatalog();
        $this->switchMode('soft');
        $this->assertSame(AddressSchema::MODE_SOFT, AddressSchema::mode());

        AddressRegion::query()->update(['is_active' => false]);
        AddressSchema::flush();
        $html = $this->get(route('admin.address-system.index'))->assertOk()->getContent();

        $this->assertSame([AddressSchema::MODE_SOFT, AddressSchema::MODE_OFF], [AddressSchema::configuredMode(), AddressSchema::mode()], 'customers are not left without a region to choose');
        $this->assertStringContainsString(e(translate('address_mode_held_off_no_cities')), $html);

        AddressRegion::query()->orderBy('id')->first()->update(['is_active' => true]);
        AddressSchema::flush();

        $this->assertSame(AddressSchema::MODE_SOFT, AddressSchema::mode());
    }

    public function test_the_command_changes_the_mode_through_the_same_gate_and_drops_the_settings_cache(): void
    {
        $this->signInWithTheCatalog();

        $this->artisan('address:mode', ['mode' => 'soft'])->expectsOutput('Address mode: soft configured, soft in force.')->assertExitCode(0);
        $this->assertSame(AddressSchema::MODE_SOFT, AddressSchema::configuredMode());
        $this->assertContains('address.mode_changed', $this->auditActions());

        BusinessSetting::query()->where('type', AddressSchema::SETTING)->update(['value' => json_encode('off')]);
        $this->assertSame(AddressSchema::MODE_SOFT, AddressSchema::configuredMode(), 'a value edited in the database is hidden by the settings cache');

        $this->artisan('address:mode', ['mode' => 'off'])->assertExitCode(0);
        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::configuredMode(), 'the command makes the database value the one in force');
        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::mode());
    }

    public function test_the_command_refuses_what_the_panel_refuses_and_an_unknown_mode(): void
    {
        $this->signIn();

        $this->artisan('address:mode', ['mode' => 'soft'])->expectsOutput(translate('address_mode_blocked_no_cities'))->assertExitCode(1);
        $this->artisan('address:mode', ['mode' => 'everything'])->assertExitCode(2);
        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::configuredMode());

        $this->artisan('address:mode', ['mode' => 'off'])->assertExitCode(0);
    }

    public function test_soft_and_strict_are_refused_while_the_zip_restriction_is_on_stage_s1(): void
    {
        $this->signInWithTheCatalog();
        $this->setZipRestriction(true);

        foreach (['soft', 'strict'] as $mode) {
            $this->switchMode($mode)->assertRedirect(route('admin.address-system.index'));

            $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::configuredMode(), "{$mode} must not be saved");
            $this->assertContains(translate('address_mode_blocked_zip_restriction'), $this->toasts());
        }
        $this->assertSame([], $this->auditActions());

        $this->setZipRestriction(false);
        $this->switchMode('soft');
        $this->assertSame(AddressSchema::MODE_SOFT, AddressSchema::configuredMode(), 'allowed again once the restriction is off');
    }

    public function test_turning_it_off_is_allowed_even_while_the_zip_restriction_is_on(): void
    {
        $this->signInWithTheCatalog();
        $this->switchMode('soft');
        $this->setZipRestriction(true);

        $this->switchMode('off');

        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::configuredMode());
    }

    public function test_the_overview_explains_why_a_mode_is_blocked_links_to_the_delivery_zone_page_and_disables_the_options(): void
    {
        $this->signInWithTheCatalog();
        $this->setZipRestriction(true);

        $html = $this->get(route('admin.address-system.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('address_mode_blocked_zip_restriction')), $html);
        $this->assertStringContainsString(route('admin.business-settings.delivery-zone.index'), $html);
        $this->assertMatchesRegularExpression('/value="soft"[^>]*disabled/', $html);
        $this->assertMatchesRegularExpression('/value="strict"[^>]*disabled/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="off"[^>]*disabled/', $html);
    }

    public function test_the_overview_leaves_the_options_open_when_nothing_blocks_them(): void
    {
        $this->signInWithTheCatalog();

        $html = $this->get(route('admin.address-system.index'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/name="mode" value="(soft|strict)"[^>]*disabled/', $html);
        $this->assertStringNotContainsString(e(translate('address_mode_blocked_zip_restriction')), $html);
    }

    public function test_the_overview_says_the_chosen_mode_is_paused_while_the_zip_restriction_is_on(): void
    {
        $this->signInWithTheCatalog();
        $this->switchMode('soft');
        $this->setZipRestriction(true);

        $html = $this->get(route('admin.address-system.index'))->assertOk()->getContent();

        $this->assertSame(AddressSchema::MODE_SOFT, AddressSchema::configuredMode());
        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::mode(), 'the store keeps the old address with its ZIP code while the restriction is on');
        $this->assertStringContainsString(e(translate('address_zip_conflict_active')), $html);
        $this->assertStringNotContainsString(e(translate('address_mode_held_off_no_cities')), $html, 'the reason is the restriction, not an empty city list');
    }

    public function test_turning_the_restriction_off_brings_the_chosen_mode_back_without_touching_the_setting(): void
    {
        $this->signInWithTheCatalog();
        $this->switchMode('strict');

        $this->setZipRestriction(true);
        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::mode());

        $this->setZipRestriction(false);
        $this->assertSame(AddressSchema::MODE_STRICT, AddressSchema::mode());
    }

    public function test_the_overview_says_so_when_the_chosen_mode_is_held_at_off_because_no_city_is_active(): void
    {
        $this->signInWithTheCatalog();
        $this->switchMode('strict');
        AddressCity::query()->update(['is_active' => false]);
        AddressSchema::flush();

        $html = $this->get(route('admin.address-system.index'))->assertOk()->getContent();

        $this->assertSame(AddressSchema::MODE_STRICT, AddressSchema::configuredMode());
        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::mode());
        $this->assertStringContainsString(e(translate('address_mode_held_off_no_cities')), $html);
    }

    public function test_an_unknown_or_missing_mode_is_refused_and_changes_nothing(): void
    {
        $this->signInWithTheCatalog();

        $this->switchMode('everything')->assertSessionHasErrors('mode');
        $this->post(route('admin.address-system.mode.update'))->assertSessionHasErrors('mode');
        $this->post(route('admin.address-system.mode.update'), ['mode' => ['soft']])->assertSessionHasErrors('mode');

        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::configuredMode());
        $this->assertSame([], $this->auditActions());
    }

    public function test_the_gate_names_every_reason_at_once(): void
    {
        $this->signIn();
        $this->setZipRestriction(true);
        $gate = app(AddressModeGate::class);

        $this->assertSame(['address_mode_blocked_no_cities', 'address_mode_blocked_zip_restriction'], $gate->problems('soft'));
        $this->assertSame(['address_mode_blocked_no_cities', 'address_mode_blocked_zip_restriction'], $gate->problems('strict'));
        $this->assertSame([], $gate->problems('off'));
    }

    public function test_the_gate_refuses_a_store_whose_migrations_have_not_run(): void
    {
        $this->seedAddressCatalog();
        foreach (['address_city_aliases', 'address_cities', 'address_regions'] as $table) {
            DB::statement("DROP TABLE {$table}");
        }
        AddressSchema::flush();
        $gate = app(AddressModeGate::class);

        $this->assertSame(['address_mode_blocked_not_migrated'], $gate->problems('soft'));
        $this->assertSame([], $gate->problems('off'));
        $this->assertSame(['address_mode_blocked_not_migrated'], $gate->switchTo('strict'));
        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::configuredMode());
    }

    public function test_the_zip_restriction_is_read_the_way_the_checkout_reads_it(): void
    {
        $gate = app(AddressModeGate::class);
        $this->assertFalse($gate->zipRestrictionOn(), 'a store that never set it has it off');

        foreach ([[0, false], ['0', false], [1, true], ['1', true]] as [$stored, $expected]) {
            DB::table('business_settings')->updateOrInsert(['type' => AddressModeGate::ZIP_RESTRICTION_SETTING], ['value' => json_encode($stored)]);
            clearWebConfigCacheKeys();

            $this->assertSame($expected, $gate->zipRestrictionOn(), 'stored ' . json_encode($stored));
        }
    }

    public function test_an_unknown_mode_is_a_programming_error_not_a_setting(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AddressModeGate::class)->switchTo('everything');
    }

    public function test_switching_to_off_does_not_touch_the_lists_or_the_saved_addresses(): void
    {
        $this->signInWithTheCatalog();
        $this->switchMode('strict');
        $address = $this->address(['city_id' => $this->ids['riyadh'], 'region_id' => $this->ids['region_RUH'], 'national_address' => 'RAGI2929']);
        $before = $this->addressRow($address);
        $cities = AddressCity::count();
        $regions = AddressRegion::count();

        $this->switchMode('off');

        $this->assertSame($before, $this->addressRow($address));
        $this->assertSame($cities, AddressCity::count());
        $this->assertSame($regions, AddressRegion::count());
    }
}
