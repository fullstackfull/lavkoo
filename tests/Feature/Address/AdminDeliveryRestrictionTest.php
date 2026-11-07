<?php

namespace Tests\Feature\Address;

use App\Services\Address\AddressModeGate;
use App\Services\Address\AddressSchema;
use App\Services\Address\DeliveryRestrictionNotice;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the delivery zone page (where the ZIP code and country restrictions are switched on) says about the unified address. How the
 * restrictions work is not changed; before the address migrations have run the page is exactly what it was.
 */
class AdminDeliveryRestrictionTest extends AdminAddressTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('delivery_country_codes', static function (Blueprint $table): void {
            $table->id();
            $table->string('country_code')->nullable();
            $table->timestamps();
        });
        Schema::create('delivery_zip_codes', static function (Blueprint $table): void {
            $table->id();
            $table->string('zipcode')->nullable();
            $table->timestamps();
        });
        DB::table('business_settings')->insert(['type' => 'pagination_limit', 'value' => json_encode(20)]);
        clearWebConfigCacheKeys();
        $this->signInWithTheCatalog();
    }

    private function setting(string $type, bool $on): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => $type], ['value' => json_encode($on ? 1 : 0)]);
        clearWebConfigCacheKeys();
    }

    private function zipRestriction(bool $on): void
    {
        $this->setting(AddressModeGate::ZIP_RESTRICTION_SETTING, $on);
    }

    private function page(): string
    {
        return $this->get(route('admin.business-settings.delivery-zone.index'))->assertOk()->getContent();
    }

    public function test_the_page_is_unchanged_when_the_unified_address_is_off_and_the_zip_restriction_is_off(): void
    {
        $html = $this->page();

        $this->assertStringNotContainsString(e(translate('address_zip_banner_count')), $html);
        $this->assertStringNotContainsString(route('admin.address-system.index'), $html);
        $this->assertNull(app(DeliveryRestrictionNotice::class)->forPage());
    }

    public function test_with_the_zip_restriction_on_the_page_warns_that_the_structured_address_cannot_be_switched_on(): void
    {
        $this->zipRestriction(true);
        $this->address(['zip' => null]);

        $html = $this->page();

        $this->assertStringContainsString('alert-warning', $html);
        $this->assertStringContainsString(e(translate('address_zip_banner_blocks_mode')), $html);
        $this->assertStringContainsString(route('admin.address-system.index'), $html);
        $this->assertSame(['level' => 'warning', 'zip_restriction' => true, 'mode_chosen' => 'off', 'mode_in_force' => 'off', 'saudi_without_zip' => 1], app(DeliveryRestrictionNotice::class)->forPage());
    }

    public function test_with_the_structured_address_already_chosen_the_warning_says_it_is_paused(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_SOFT);
        $this->zipRestriction(true);

        $html = $this->page();

        $this->assertStringContainsString('alert-warning', $html);
        $this->assertStringContainsString(e(translate('address_zip_banner_conflict')), $html);
        $this->assertStringNotContainsString(e(translate('address_zip_banner_blocks_mode')), $html);
    }

    public function test_with_the_structured_address_on_and_no_restriction_the_page_only_informs(): void
    {
        AddressSchema::setMode(AddressSchema::MODE_STRICT);
        $this->zipRestriction(false);

        $html = $this->page();

        $this->assertStringContainsString('alert-info', $html);
        $this->assertStringContainsString(e(translate('address_zip_banner_info')), $html);
        $this->assertStringNotContainsString('alert-warning', $html);
    }

    public function test_the_count_is_of_saved_saudi_addresses_with_a_blank_zip_and_nothing_else(): void
    {
        $this->zipRestriction(true);
        $this->address(['zip' => null]);
        $this->address(['zip' => '']);
        $this->address(['zip' => '   ']);
        $this->address(['zip' => '12345']);
        $this->address(['zip' => null, 'country' => 'SA']);
        $this->address(['zip' => null, 'country' => 'Syria']);
        $this->address(['zip' => null, 'country' => null]);

        $html = $this->page();

        $this->assertSame(4, app(DeliveryRestrictionNotice::class)->forPage()['saudi_without_zip']);
        $this->assertMatchesRegularExpression('/' . preg_quote(e(translate('address_zip_banner_count')), '/') . ':\s*<strong>4<\/strong>/', $html);
    }

    public function test_the_banner_changes_no_restriction_and_no_setting(): void
    {
        $this->zipRestriction(true);
        $this->setting('delivery_country_restriction', true);
        $before = DB::table('business_settings')->orderBy('id')->get()->all();
        $zipRows = DB::table('delivery_zip_codes')->count();

        $this->page();

        $this->assertEquals($before, DB::table('business_settings')->orderBy('id')->get()->all());
        $this->assertSame($zipRows, DB::table('delivery_zip_codes')->count());
        $this->assertSame([], $this->auditActions());
    }

    public function test_before_the_address_migrations_the_page_adds_nothing(): void
    {
        $this->zipRestriction(true);
        foreach (['address_city_aliases', 'address_cities', 'address_regions'] as $table) {
            Schema::drop($table);
        }
        AddressSchema::flush();

        $html = $this->page();

        $this->assertStringNotContainsString(e(translate('address_zip_banner_blocks_mode')), $html);
        $this->assertStringNotContainsString(route('admin.address-system.index'), $html);
        $this->assertNull(app(DeliveryRestrictionNotice::class)->forPage());
    }

    public function test_the_zip_restriction_toggle_still_works_as_it_did(): void
    {
        $this->post(route('admin.business-settings.delivery-zone.zipcode-restriction-status-change'), ['status' => 1])->assertRedirect();
        $this->assertTrue(app(AddressModeGate::class)->zipRestrictionOn());

        $this->post(route('admin.business-settings.delivery-zone.zipcode-restriction-status-change'), ['status' => 0])->assertRedirect();
        $this->assertFalse(app(AddressModeGate::class)->zipRestrictionOn());
    }
}
