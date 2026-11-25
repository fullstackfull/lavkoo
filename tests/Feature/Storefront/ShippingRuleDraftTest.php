<?php

namespace Tests\Feature\Storefront;

use App\Models\Admin;
use App\Models\ShippingMethod;
use App\Models\ShippingRuleDraft;
use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\ShippingRuleDrafts;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontShippingMethods;
use App\Utils\Helpers;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\BuildsAuditLogTable;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * H1-14's last criterion: copying a shipping rule between markets creates a DRAFT, and nothing is inherited.
 *
 * The owner's ruling of 2026-11-25: a copy is unpublished, does not affect the live market, carries no carrier credentials, contracts or
 * sensitive information, and publishing afterwards is a separate action with permissions and an audit trail.
 *
 * The first two tests are the ones that matter most, and they are about what a draft is NOT. A draft is not offered to a shop and is not
 * charged, and that is true BY CONSTRUCTION rather than by a filter: drafts live in their own table, which no storefront query knows, so a
 * draft cannot reach a customer through a listing somebody forgot to narrow. The test asserts the behaviour; the design is what makes it
 * hold for the listings nobody has written yet.
 *
 * What is copied is a delivery option's name, cost and duration. What is NOT copied is the owner of the rule, the switch, and anything of the
 * carrier's -- credentials live in `integration_settings`, keyed by carrier and country, and a copy that carried them would hand one market's
 * account to another.
 */
class ShippingRuleDraftTest extends TestCase
{
    use BuildsAuditLogTable;
    use BuildsStaffScopeWorld;
    use RestoresLanguageFiles;

    private const SAUDI_METHOD = 10;

    private const KUWAITI_METHOD = 20;

    private const UNMARKED_METHOD = 30;

    /** The employee the staff world seeds with a role that may reach the business-settings module. */
    private const STAFF = 70;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildStaffAdminTables();
        $this->buildStaffProductTables();
        $this->buildShippingMethods();
        $this->runStaffScopeMigration();
        $this->migration()->up();

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.default_country' => 'SA',
        ]);
        app(StorefrontShippingMethods::class)->forget();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        // Guarded: one test rolls the migration back itself, so the table may already be gone by the time this runs.
        if (Schema::hasTable(ShippingRuleDrafts::TABLE)) {
            DB::table(ShippingRuleDrafts::TABLE)->delete();
            $this->migration()->down();
        }

        Schema::dropIfExists('shipping_methods');

        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_12_24_100000_create_shipping_rule_drafts_table.php');
    }

    private function drafts(): ShippingRuleDrafts
    {
        return app(ShippingRuleDrafts::class);
    }

    /** The point of the whole design: a draft is not a delivery option and no shop is offered it. */
    public function test_a_draft_is_not_offered_to_any_shop_and_is_not_a_shipping_method(): void
    {
        $this->drafts()->copyTowards(self::SAUDI_METHOD, 'KW', by: 1, byName: 'Owner');

        $this->assertSame(1, ShippingRuleDraft::query()->count(), 'the draft exists');
        $this->assertSame(3, ShippingMethod::query()->count(), 'and no shipping method was made');

        // Kuwait is offered exactly what it was offered before the copy: its own one method, and not the drafted Saudi one.
        $this->inMarket('KW', function (): void {
            $this->assertSame(['Kuwaiti standard'], Helpers::getShippingMethods(1, 'admin')->pluck('title')->all(), 'a draft is not an offer');
        });
        $this->inMarket('SA', function (): void {
            $this->assertSame(
                ['Saudi standard', 'Legacy'],
                Helpers::getShippingMethods(1, 'admin')->pluck('title')->all(),
                'and the market it was copied FROM is untouched',
            );
        });
    }

    /** And what a draft carries is a delivery option's name, cost and time -- not its owner, not its switch, nothing of the carrier's. */
    public function test_a_draft_carries_the_option_and_not_the_owner_the_switch_or_anything_of_the_carriers(): void
    {
        $draft = $this->drafts()->copyTowards(self::SAUDI_METHOD, 'KW', by: 1, byName: 'Owner');

        $this->assertSame('Saudi standard', $draft->title);
        $this->assertSame(25.0, $draft->cost);
        $this->assertSame('3', $draft->duration);
        $this->assertSame('KW', $draft->target_country);
        $this->assertSame('SA', $draft->source_country, 'and it says where it came from');

        $columns = array_keys(DB::table(ShippingRuleDrafts::TABLE)->first() ? (array) DB::table(ShippingRuleDrafts::TABLE)->first() : []);

        foreach (['creator_id', 'creator_type', 'status', 'credentials', 'base_url', 'api_key', 'contract'] as $mustNotExist) {
            $this->assertNotContains($mustNotExist, $columns, "a draft must not carry {$mustNotExist}");
        }
    }

    /** Publishing is a separate act: it makes ONE live method, in the target market, switched OFF. */
    public function test_publishing_makes_one_live_method_in_the_target_market_switched_off(): void
    {
        $draft = $this->drafts()->copyTowards(self::SAUDI_METHOD, 'KW', by: 1, byName: 'Owner');

        $method = $this->drafts()->publish($draft->id, by: 7, byName: 'A person with the authority');

        $this->assertSame('KW', $method->getAttribute(StorefrontShippingMethods::COLUMN));
        $this->assertFalse((bool) $method->status, 'switched off, so publishing changes nothing a customer sees');
        $this->assertSame('Saudi standard', $method->title);
        $this->assertSame(7, (int) $method->creator_id, 'owned by whoever published it, never by the source rule\'s owner');
        $this->assertSame(4, ShippingMethod::query()->count(), 'exactly one method was made');

        $draft->refresh();
        $this->assertNotNull($draft->published_at);
        $this->assertSame('A person with the authority', $draft->published_by_name, 'and who published it outlives their account');
        $this->assertSame($method->getKey(), $draft->published_method_id);
    }

    /** A published method is still not offered until somebody turns it on, which is the existing audited action. */
    public function test_a_published_method_is_still_not_offered_until_somebody_turns_it_on(): void
    {
        $draft = $this->drafts()->copyTowards(self::SAUDI_METHOD, 'KW', by: 1, byName: 'Owner');
        $method = $this->drafts()->publish($draft->id, by: 7, byName: 'Owner');

        $this->inMarket('KW', function (): void {
            $this->assertSame(['Kuwaiti standard'], Helpers::getShippingMethods(1, 'admin')->pluck('title')->all(), 'switched off is not offered');
        });

        $method->forceFill(['status' => true])->save();
        app(StorefrontShippingMethods::class)->forget();

        $this->inMarket('KW', function (): void {
            $this->assertSame(
                ['Kuwaiti standard', 'Saudi standard'],
                Helpers::getShippingMethods(1, 'admin')->pluck('title')->all(),
                'and once on, it is offered beside the market\'s own',
            );
        });
    }

    public function test_a_draft_can_never_be_published_twice(): void
    {
        $draft = $this->drafts()->copyTowards(self::SAUDI_METHOD, 'KW', by: 1, byName: 'Owner');
        $this->drafts()->publish($draft->id, by: 7, byName: 'Owner');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('that_shipping_rule_draft_has_already_been_published');
        $this->drafts()->publish($draft->id, by: 7, byName: 'Owner');
    }

    /** Copying the same rule towards the same market again refreshes the draft rather than piling up a second one. */
    public function test_copying_the_same_rule_towards_the_same_market_refreshes_the_one_draft(): void
    {
        $first = $this->drafts()->copyTowards(self::SAUDI_METHOD, 'KW', by: 1, byName: 'Owner');
        DB::table('shipping_methods')->where('id', self::SAUDI_METHOD)->update(['cost' => 31]);

        $second = $this->drafts()->copyTowards(self::SAUDI_METHOD, 'KW', by: 1, byName: 'Owner');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ShippingRuleDraft::query()->count());
        $this->assertSame(31.0, $second->refresh()->cost, 'the draft is the source as it is now');
    }

    /** What is published is what was COPIED and reviewed, not whatever the source has become since. */
    public function test_publishing_writes_what_was_copied_and_not_what_the_source_became(): void
    {
        $draft = $this->drafts()->copyTowards(self::SAUDI_METHOD, 'KW', by: 1, byName: 'Owner');
        DB::table('shipping_methods')->where('id', self::SAUDI_METHOD)->update(['cost' => 999, 'title' => 'Changed since']);

        $method = $this->drafts()->publish($draft->id, by: 7, byName: 'Owner');

        $this->assertSame('Saudi standard', $method->title);
        $this->assertSame(25.0, (float) $method->cost, 'the figure a person reviewed, not one nobody saw');
    }

    /** The source rule survives, because a copy is a copy. Nothing is shared and nothing is moved. */
    public function test_the_source_rule_is_untouched_and_nothing_is_shared(): void
    {
        $draft = $this->drafts()->copyTowards(self::SAUDI_METHOD, 'KW', by: 1, byName: 'Owner');
        $method = $this->drafts()->publish($draft->id, by: 7, byName: 'Owner');

        $method->forceFill(['cost' => 99])->save();

        $this->assertSame(
            25.0,
            (float) ShippingMethod::withoutGlobalScope(\App\Models\Scopes\StaffCountryShippingScope::class)->find(self::SAUDI_METHOD)->cost,
            'editing the published copy does not move the price the source charges',
        );
    }

    public function test_a_rule_is_not_copied_towards_the_market_it_already_belongs_to(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('that_market_already_has_this_shipping_rule');
        $this->drafts()->copyTowards(self::KUWAITI_METHOD, 'KW', by: 1, byName: 'Owner');
    }

    public function test_a_rule_is_not_copied_towards_a_market_the_platform_does_not_serve(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the_platform_does_not_serve_that_market');
        $this->drafts()->copyTowards(self::SAUDI_METHOD, 'ZZ', by: 1, byName: 'Owner');
    }

    public function test_a_market_must_be_named_and_a_rule_must_exist(): void
    {
        try {
            $this->drafts()->copyTowards(self::SAUDI_METHOD, '', by: 1, byName: 'Owner');
            $this->fail('A copy towards no market must be refused.');
        } catch (InvalidArgumentException $refusal) {
            $this->assertSame('say_which_market_this_shipping_rule_is_copied_towards', $refusal->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('that_shipping_rule_does_not_exist');
        $this->drafts()->copyTowards(999, 'KW', by: 1, byName: 'Owner');
    }

    /** An unmarked rule is the DEFAULT market's, so copying it towards the default market is copying it onto itself. */
    public function test_an_unmarked_rule_belongs_to_the_default_market_for_this_too(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('that_market_already_has_this_shipping_rule');
        $this->drafts()->copyTowards(self::UNMARKED_METHOD, 'SA', by: 1, byName: 'Owner');
    }

    /** A restricted employee copies only between the markets they work with, and the refusal says which end was wrong. */
    public function test_a_restricted_employee_copies_only_between_their_own_markets(): void
    {
        $this->boundStaffHolding('KW');

        try {
            $this->drafts()->copyTowards(self::SAUDI_METHOD, 'KW', by: self::STAFF, byName: 'Kuwaiti staff');
            $this->fail('An employee who does not hold the source market must be refused.');
        } catch (InvalidArgumentException $refusal) {
            $this->assertSame('your_account_does_not_work_with_the_market_this_rule_belongs_to', $refusal->getMessage());
        }

        $this->boundStaffHolding('SA');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('your_account_does_not_work_with_the_market_you_are_copying_towards');
        $this->drafts()->copyTowards(self::SAUDI_METHOD, 'KW', by: self::STAFF, byName: 'Saudi staff');
    }

    /** And publishes only into a market they work with: that is the permission the ruling asks for. */
    public function test_a_restricted_employee_publishes_only_into_their_own_market(): void
    {
        $draft = $this->drafts()->copyTowards(self::SAUDI_METHOD, 'KW', by: 1, byName: 'Owner');
        $this->boundStaffHolding('SA');

        try {
            $this->drafts()->publish($draft->id, by: self::STAFF, byName: 'Saudi staff');
            $this->fail('Publishing into a market the employee does not work with must be refused.');
        } catch (InvalidArgumentException $refusal) {
            $this->assertSame('your_account_does_not_work_with_the_market_you_are_publishing_into', $refusal->getMessage());
        }

        $this->boundStaffHolding('KW');
        $this->assertSame('KW', $this->drafts()->publish($draft->id, by: self::STAFF, byName: 'Kuwaiti staff')->getAttribute(StorefrontShippingMethods::COLUMN));
    }

    /** The waiting list is narrowed to the employee's own markets too, so a screen shows what that person may decide. */
    public function test_the_waiting_list_is_narrowed_to_the_employees_markets(): void
    {
        $this->drafts()->copyTowards(self::SAUDI_METHOD, 'KW', by: 1, byName: 'Owner');
        $this->drafts()->copyTowards(self::KUWAITI_METHOD, 'BH', by: 1, byName: 'Owner');

        $this->assertSame(['BH', 'KW'], $this->drafts()->waiting()->pluck('target_country')->all(), 'an unrestricted administrator sees both');

        $this->boundStaffHolding('KW');
        $this->assertSame(['KW'], $this->drafts()->waiting()->pluck('target_country')->all());
    }

    /** A published draft leaves the waiting list, so a decision is not offered twice. */
    public function test_a_published_draft_leaves_the_waiting_list(): void
    {
        $draft = $this->drafts()->copyTowards(self::SAUDI_METHOD, 'KW', by: 1, byName: 'Owner');

        $this->assertCount(1, $this->drafts()->waiting());

        $this->drafts()->publish($draft->id, by: 7, byName: 'Owner');

        $this->assertCount(0, $this->drafts()->waiting());
    }

    /** Both acts are audited, which is the other half of the ruling. */
    public function test_copying_and_publishing_are_both_audited(): void
    {
        $this->buildAuditLogTable();
        $draft = $this->drafts()->copyTowards(self::SAUDI_METHOD, 'KW', by: 1, byName: 'Owner');
        $this->drafts()->publish($draft->id, by: 7, byName: 'A person with the authority');

        $actions = DB::table('audit_logs')->orderBy('id')->pluck('action')->all();

        $this->assertContains('shipping_rule_draft.copied', $actions);
        $this->assertContains('shipping_rule_draft.published', $actions);
    }

    /** Before the migration nothing is available, and asking is refused rather than failing on a table that is not there. */
    public function test_before_the_migration_copying_is_not_available(): void
    {
        $this->migration()->down();

        $this->assertFalse($this->drafts()->available());
        $this->assertCount(0, $this->drafts()->waiting());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('copying_a_shipping_rule_is_not_available_on_this_installation');
        $this->drafts()->copyTowards(self::SAUDI_METHOD, 'KW', by: 1, byName: 'Owner');
    }

    /** Rolling back is refused while a draft exists, because it would forget the decisions nobody has taken and who took the rest. */
    public function test_the_rollback_is_refused_while_a_draft_exists(): void
    {
        $this->drafts()->copyTowards(self::SAUDI_METHOD, 'KW', by: 1, byName: 'Owner');

        try {
            $this->migration()->down();
            $this->fail('The rollback must be refused while a draft exists.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('Rolling this migration back would forget', $refusal->getMessage());
        }
    }

    private function inMarket(string $country, callable $read): void
    {
        app()->instance(StorefrontContext::class, new StorefrontContext(country: $country, currency: 'SAR', storefrontsEnabled: true));
        app(StorefrontShippingMethods::class)->forget();

        $read();
    }

    /**
     * A bound request, as the middleware leaves one, for an employee holding EXACTLY these countries.
     *
     * The rows are cleared first: `giveCountries()` appends, so a test that binds twice would leave the employee holding the union of both
     * calls -- which is how a refusal test comes to pass by having nothing to refuse.
     */
    private function boundStaffHolding(string ...$countries): void
    {
        DB::table(StaffCountryScope::TABLE)->where('admin_id', self::STAFF)->delete();
        $this->giveCountries(self::STAFF, $countries);
        $this->switchStaffScopesOn();
        $admin = Admin::query()->findOrFail(self::STAFF);
        $this->actingAs($admin, 'admin');

        $request = Request::create('/admin/business-settings/shipping-method/index');
        $this->app->instance('request', $request);
        app(StaffCountryScope::class)->bind(request: $request, admin: $admin);
    }

    private function buildShippingMethods(): void
    {
        Schema::dropIfExists('shipping_methods');
        Schema::create('shipping_methods', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->string('creator_type', 24)->nullable();
            $table->string('title')->nullable();
            $table->decimal('cost', 24, 2)->default(0);
            $table->string('duration')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        (require database_path('migrations/2026_12_22_100000_add_storefront_country_to_shipping_methods.php'))->up();

        foreach ([
            [self::SAUDI_METHOD, 'Saudi standard', 25, 'SA'],
            [self::KUWAITI_METHOD, 'Kuwaiti standard', 15, 'KW'],
            [self::UNMARKED_METHOD, 'Legacy', 10, null],
        ] as [$id, $title, $cost, $market]) {
            DB::table('shipping_methods')->insert([
                'id' => $id,
                'creator_id' => 0,
                'creator_type' => 'admin',
                'title' => $title,
                'cost' => $cost,
                'duration' => '3',
                'status' => 1,
                StorefrontShippingMethods::COLUMN => $market,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
