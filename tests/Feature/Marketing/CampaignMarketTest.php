<?php

namespace Tests\Feature\Marketing;

use App\Http\Controllers\Admin\Commerce\CampaignController;
use App\Models\Admin;
use App\Models\ExperienceCampaign;
use App\Services\Commerce\CampaignMarkets;
use App\Services\Commerce\CampaignResolver;
use App\Services\Commerce\CampaignRules;
use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Storefront\StorefrontVisibility;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsAuditLogTable;
use Tests\TestCase;

/**
 * I1-15: a central campaign selects one, several or all markets, and a manager restricted to a market runs a local one there and nowhere else.
 *
 * The SERVING half of this was already built and nobody could reach it: {@see ExperienceCampaign} carries the storefront-visibility scope, so
 * a campaign with a mapping row is served only in the markets its rows name -- but no screen could write such a row, so every campaign anybody
 * created ran platform-wide and "selects one, several or all markets" described an empty table. What is asserted here is the whole path, because
 * a writer that stores a market the resolver does not read, or a resolver that reads a market the writer never stores, is one half of a feature:
 *
 *   - the admin screen stores the markets chosen, and the storefront of another market is not served that campaign;
 *   - a restricted employee is offered their own markets only, and whatever the form carries their campaign lands in their own markets --
 *     nothing chosen does NOT mean "every market" for them, which is the widest reach on the platform;
 *   - a restricted employee is not shown, and may not change or delete, a campaign of a market that is not theirs, nor a platform-wide one;
 *   - two campaigns in different markets no longer contest a slot, because no page ever carries both.
 */
class CampaignMarketTest extends TestCase
{
    use BuildsAuditLogTable;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.mode' => 'live', 'commerce.enabled' => true]);
        $this->buildTheCampaignWorld();
        $this->buildTheAdmins();
        $this->buildAuditLogTable();
        ToastMagic::clear();
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true, 'gcc.storefronts.countries.AE.active' => true]);
        StorefrontVisibility::flush();
        $this->signInAsAdmin(1);
    }

    protected function tearDown(): void
    {
        $this->serveNoStorefront();
        StorefrontVisibility::flush();

        parent::tearDown();
    }

    /** The first test of the switch: with storefronts off a campaign names no market and runs where it has always run. */
    public function test_while_storefronts_are_off_a_campaign_names_no_market(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        StorefrontVisibility::flush();

        $this->createCampaign(name: 'Weekend sale', markets: ['KW']);

        $this->assertSame(0, DB::table(StorefrontVisibility::TABLE)->count(), 'a market was stored while storefronts are off');
        $this->assertFalse(app(CampaignMarkets::class)->applies());
    }

    public function test_a_campaign_created_for_kuwait_names_kuwait_and_nothing_else(): void
    {
        $this->createCampaign(name: 'Kuwaiti weekend sale', markets: ['KW']);

        $this->assertSame(['KW'], app(CampaignMarkets::class)->of($this->theCampaign()));
    }

    public function test_a_campaign_created_for_two_markets_names_both(): void
    {
        $this->createCampaign(name: 'Gulf weekend sale', markets: ['KW', 'AE']);

        $this->assertEqualsCanonicalizing(['KW', 'AE'], app(CampaignMarkets::class)->of($this->theCampaign()));
    }

    public function test_a_campaign_created_for_no_market_runs_in_every_market_exactly_as_before(): void
    {
        $this->createCampaign(name: 'Platform news', markets: []);

        $this->assertSame([], app(CampaignMarkets::class)->of($this->theCampaign()));
        $this->assertSame(0, DB::table(StorefrontVisibility::TABLE)->count());
    }

    /** The serving half: the storefront of another market is not dressed by a campaign that names only Kuwait. */
    public function test_only_the_chosen_markets_storefront_is_served_the_campaign(): void
    {
        $this->createCampaign(name: 'Kuwaiti hero', markets: ['KW'], status: ExperienceCampaign::STATUS_ACTIVE);
        $this->createCampaign(name: 'Everywhere hero', markets: [], status: ExperienceCampaign::STATUS_ACTIVE, slot: 'bottom');

        $this->assertEqualsCanonicalizing(['Kuwaiti hero', 'Everywhere hero'], $this->overlayNamesIn('KW'));
        $this->assertSame(['Everywhere hero'], $this->overlayNamesIn('AE'));
    }

    public function test_an_employee_restricted_to_kuwait_is_offered_kuwait_only(): void
    {
        $this->restrictTo(admin: 2, countries: ['KW']);
        $this->signInAsAdmin(2);

        $this->assertSame(['KW'], app(CampaignMarkets::class)->choices());
        $this->assertFalse(app(CampaignMarkets::class)->mayChooseEveryMarket());
    }

    /** Whatever the form carries, a restricted employee's campaign runs in their own markets: the field cannot widen their reach. */
    public function test_an_employee_restricted_to_kuwait_cannot_create_a_campaign_for_another_market(): void
    {
        $this->restrictTo(admin: 2, countries: ['KW']);
        $this->signInAsAdmin(2);

        $this->createCampaign(name: 'Emirati sale by a Kuwaiti manager', markets: ['AE']);

        $this->assertSame(['KW'], app(CampaignMarkets::class)->of($this->theCampaign()), 'a restricted employee reached a market that is not theirs');
    }

    public function test_a_restricted_employee_who_chooses_nothing_runs_in_their_own_markets_and_not_every_market(): void
    {
        $this->restrictTo(admin: 2, countries: ['KW']);
        $this->signInAsAdmin(2);

        $this->createCampaign(name: 'No market chosen', markets: []);

        $this->assertSame(['KW'], app(CampaignMarkets::class)->of($this->theCampaign()), 'a restricted employee created a platform-wide campaign');
    }

    /** An employee whose scope grants NO market reaches nobody, and no market named means every market -- so they are refused instead. */
    public function test_an_employee_whose_scope_grants_no_market_may_not_create_a_campaign(): void
    {
        $this->restrictTo(admin: 2, countries: ['ZZ']);
        $this->signInAsAdmin(2);

        $this->createCampaign(name: 'Created by nobody', markets: []);

        $this->assertSame(0, ExperienceCampaign::query()->count(), 'the campaign was stored although the employee has no market');
        $this->assertFalse(app(CampaignMarkets::class)->mayCreate());
    }

    public function test_a_restricted_employee_is_not_shown_another_markets_campaign(): void
    {
        $this->createCampaign(name: 'Emirati sale', markets: ['AE']);
        $this->createCampaign(name: 'Kuwaiti sale', markets: ['KW']);
        $this->createCampaign(name: 'Platform news', markets: []);

        $this->restrictTo(admin: 2, countries: ['KW']);
        $this->signInAsAdmin(2);

        $listed = app(CampaignMarkets::class)->listedFor(ExperienceCampaign::query()->orderBy('id')->get())
            ->pluck('name')->all();

        // The platform-wide one dresses their storefront too, so hiding it would hide something that is running in their market.
        $this->assertSame(['Kuwaiti sale', 'Platform news'], $listed);
    }

    public function test_a_restricted_employee_may_not_change_another_markets_campaign_or_a_platform_wide_one(): void
    {
        $this->createCampaign(name: 'Emirati sale', markets: ['AE']);
        $emirati = $this->theCampaign();
        $this->createCampaign(name: 'Platform news', markets: []);
        $platform = ExperienceCampaign::query()->orderByDesc('id')->first();
        $this->createCampaign(name: 'Kuwaiti sale', markets: ['KW']);
        $kuwaiti = ExperienceCampaign::query()->orderByDesc('id')->first();

        $this->restrictTo(admin: 2, countries: ['KW']);
        $this->signInAsAdmin(2);

        $markets = app(CampaignMarkets::class);
        $this->assertFalse($markets->mayChange($emirati), 'a Kuwaiti manager may change an Emirati campaign');
        $this->assertFalse($markets->mayChange($platform), 'a Kuwaiti manager may change a platform-wide campaign');
        $this->assertTrue($markets->mayChange($kuwaiti));
    }

    public function test_a_restricted_employee_cannot_rename_another_markets_campaign_through_the_screen(): void
    {
        $this->createCampaign(name: 'Emirati sale', markets: ['AE']);
        $emirati = $this->theCampaign();

        $this->restrictTo(admin: 2, countries: ['KW']);
        $this->signInAsAdmin(2);

        app(CampaignController::class)->update(Request::create(
            uri: '/admin/commerce/campaigns/update',
            method: 'POST',
            parameters: ['id' => $emirati->id, 'name' => 'Taken over'],
        ));

        $this->assertSame('Emirati sale', $emirati->fresh()->name);
    }

    public function test_a_restricted_employee_cannot_delete_another_markets_campaign(): void
    {
        $this->createCampaign(name: 'Emirati sale', markets: ['AE']);
        $emirati = $this->theCampaign();

        $this->restrictTo(admin: 2, countries: ['KW']);
        $this->signInAsAdmin(2);

        app(CampaignController::class)->delete(Request::create(
            uri: '/admin/commerce/campaigns/delete',
            method: 'POST',
            parameters: ['id' => $emirati->id],
        ));

        $this->assertNotNull($emirati->fresh(), 'a Kuwaiti manager deleted an Emirati campaign');
    }

    /** Deleting a campaign takes its mapping with it: the row is keyed by the id, and the next campaign to take that id is not theirs. */
    public function test_deleting_a_campaign_removes_the_markets_it_named(): void
    {
        $this->createCampaign(name: 'Kuwaiti sale', markets: ['KW']);
        $campaign = $this->theCampaign();

        app(CampaignController::class)->delete(Request::create(
            uri: '/admin/commerce/campaigns/delete',
            method: 'POST',
            parameters: ['id' => $campaign->id],
        ));

        $this->assertSame(0, DB::table(StorefrontVisibility::TABLE)->count(), 'the mapping outlived the campaign');
    }

    /**
     * The §38 conflict check, per market: the Ramadan hero of Kuwait and the Ramadan hero of the Emirates are one campaign per market, at the
     * same priority, in the same window, and refusing the second is a refusal with no cause -- no page ever carries both.
     */
    public function test_two_campaigns_in_different_markets_do_not_contest_a_slot(): void
    {
        $this->createCampaign(name: 'Kuwaiti Ramadan', markets: ['KW'], status: ExperienceCampaign::STATUS_ACTIVE);
        $this->createCampaign(name: 'Emirati Ramadan', markets: ['AE'], status: ExperienceCampaign::STATUS_ACTIVE);

        $emirati = ExperienceCampaign::query()->orderByDesc('id')->first();

        $this->assertSame([], app(CampaignRules::class)->conflictsFor($emirati));
    }

    /** And within one market the check still bites, which is what makes the narrowing a narrowing rather than a removal. */
    public function test_two_campaigns_in_the_same_market_still_contest_a_slot(): void
    {
        $this->createCampaign(name: 'Kuwaiti Ramadan', markets: ['KW'], status: ExperienceCampaign::STATUS_ACTIVE);
        $this->createCampaign(name: 'Kuwaiti Eid', markets: ['KW'], status: ExperienceCampaign::STATUS_ACTIVE);

        $second = ExperienceCampaign::query()->orderByDesc('id')->first();

        $this->assertSame(['Kuwaiti Ramadan (hero)'], app(CampaignRules::class)->conflictsFor($second));
    }

    // ---------------------------------------------------------------------------------------

    /** @param list<string> $markets */
    private function createCampaign(string $name, array $markets, string $status = ExperienceCampaign::STATUS_DRAFT, string $slot = 'hero'): void
    {
        app(CampaignController::class)->store(Request::create(
            uri: '/admin/commerce/campaigns/store',
            method: 'POST',
            parameters: [
                'name' => $name,
                'page' => 'home',
                'priority' => 30,
                'starts_at' => now()->subDay()->format('Y-m-d H:i:s'),
                'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'storefront_countries' => $markets,
                'overrides' => json_encode([[
                    'slot' => $slot,
                    'section' => ['type' => 'promotional_banner', 'settings' => ['title' => $name]],
                ]]),
            ],
        ));

        if ($status !== ExperienceCampaign::STATUS_DRAFT) {
            ExperienceCampaign::query()->where('name', $name)->update(['status' => $status]);
        }
    }

    private function theCampaign(): ExperienceCampaign
    {
        return ExperienceCampaign::query()->orderByDesc('id')->firstOrFail();
    }

    /** @return list<string> the campaign names the overlay of one storefront carries */
    private function overlayNamesIn(string $country): array
    {
        $this->serveStorefront($country);
        Cache::flush();

        $names = array_map(
            static fn (array $override): string => (string) ExperienceCampaign::query()
                ->withoutGlobalScope('storefront_visibility')
                ->whereKey($override['campaign_id'])
                ->value('name'),
            app(CampaignResolver::class)->overridesFor('home'),
        );

        $this->serveNoStorefront();

        return $names;
    }

    private function serveStorefront(string $country): void
    {
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context(explicitChoice: $country));
    }

    private function serveNoStorefront(): void
    {
        app()->forgetInstance(StorefrontContext::class);
    }

    private function buildTheCampaignWorld(): void
    {
        Schema::dropIfExists('experience_campaigns');
        Schema::create('experience_campaigns', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('status')->default('draft');
            $table->string('page')->default('home');
            $table->integer('priority')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->text('overrides')->nullable();
            $table->timestamps();
        });

        // `translate()` reads the shop's language off the settings; without the table every toast on this path is a query error.
        Schema::dropIfExists('business_settings');
        Schema::create('business_settings', static function (Blueprint $table): void {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        // An empty themes table, so the controller's "which pages does this shop serve" falls back to the system slugs rather than failing
        // on a table that is not there -- the shape an installation that has not composed a theme is in.
        Schema::dropIfExists('themes');
        Schema::create('themes', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        // The staff country scope is enforced only once the products carry a market, so the table the real migration adds that column
        // to has to be there -- the scope reads the column, never a row.
        Schema::dropIfExists('products');
        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        // The real migrations, so the mapping table and the product column are exactly what a server gets.
        Schema::dropIfExists(StorefrontVisibility::TABLE);
        (require database_path('migrations/2026_12_08_100000_create_storefront_entity_visibility_table.php'))->up();
        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
    }

    private function buildTheAdmins(): void
    {
        Schema::dropIfExists('admins');
        Schema::dropIfExists('admin_roles');
        Schema::create('admin_roles', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->text('module_access')->nullable();
            $table->boolean('status')->default(1);
            $table->timestamps();
        });
        Schema::create('admins', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('admin_role_id')->default(1);
            $table->integer('status')->default(1);
            $table->timestamps();
        });
        DB::table('admin_roles')->insert([
            ['id' => 1, 'name' => 'Master Admin', 'module_access' => null, 'status' => 1],
            ['id' => 2, 'name' => 'Marketing', 'module_access' => json_encode(['themes_and_addons']), 'status' => 1],
        ]);
        DB::table('admins')->insert([
            ['id' => 1, 'name' => 'Master', 'email' => 'master@example.test', 'admin_role_id' => 1],
            ['id' => 2, 'name' => 'Marketing', 'email' => 'marketing@example.test', 'admin_role_id' => 2],
        ]);

        Schema::dropIfExists(StaffCountryScope::TABLE);
        Schema::create(StaffCountryScope::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->string('country', 2);
            $table->timestamps();
        });
        StaffCountryScope::forgetTheSchema();
    }

    /** @param list<string> $countries */
    private function restrictTo(int $admin, array $countries): void
    {
        config(['gcc.staff.country_scopes' => true]);

        foreach ($countries as $country) {
            DB::table(StaffCountryScope::TABLE)->insert(['admin_id' => $admin, 'country' => $country, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function signInAsAdmin(int $admin): void
    {
        $this->actingAs(Admin::query()->findOrFail($admin), 'admin');
    }
}
