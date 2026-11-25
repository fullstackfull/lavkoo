<?php

namespace Tests\Feature\Marketing;

use App\Http\Controllers\Admin\Customer\CustomerController;
use App\Http\Controllers\Web\WebController;
use App\Models\Admin;
use App\Models\Subscription;
use App\Services\Notification\NewsletterMarkets;
use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * I1-04: a newsletter signup records the market it was made in, and a send reaches that market's subscribers only.
 *
 * `subscriptions` held an e-mail address and nothing else, so six markets shared one list and the only thing an operator could do with it
 * was send to all of it -- a message whose offer is in one market's currency, for one market's catalogue, reaching people who cannot buy
 * from it.
 *
 * Three things are asserted, and the middle one is the part that is easy to miss:
 *
 *   - the SIGNUP records the market the visitor was on;
 *   - the DUPLICATE CHECK moves with it. It was global, so a Kuwaiti visitor whose address was already on the Saudi list was told they had
 *     already subscribed and NO Kuwaiti row was written -- the Kuwaiti operator's list would simply never hold them, and nothing anywhere
 *     would have said why;
 *   - the LIST and the EXPORT are narrowed by one rule, through a scope on the model rather than at the two call sites, because an export
 *     that forgets is a file of every market's addresses leaving the building.
 *
 * NULL is the default storefront's, not "every market": an unmarked subscriber signed up on the only storefront there has ever been. So a
 * send to Saudi Arabia reaches exactly the people it reaches today, which is the first test below.
 */
class NewsletterMarketTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.mode' => 'live', 'marketing.consent.enabled' => false]);
        $this->buildTheSubscriberWorld();
        $this->buildTheAdmins();
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true]);
        app(NewsletterMarkets::class)->flush();
    }

    protected function tearDown(): void
    {
        $this->serveNoStorefront();
        app(NewsletterMarkets::class)->flush();

        parent::tearDown();
    }

    /** The first test of the switch: with storefronts off a signup records no market and the list is the whole list. */
    public function test_while_storefronts_are_off_a_signup_records_no_market(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        app(NewsletterMarkets::class)->flush();
        $this->serveStorefront('KW');

        $this->subscribe('visitor@example.test');

        $this->assertNull(DB::table('subscriptions')->where('email', 'visitor@example.test')->value('storefront_country'));
        $this->assertFalse(app(NewsletterMarkets::class)->applies());
    }

    public function test_a_signup_records_the_market_the_visitor_was_on(): void
    {
        $this->serveStorefront('KW');

        $this->subscribe('kuwaiti@example.test');

        $this->assertSame('KW', DB::table('subscriptions')->where('email', 'kuwaiti@example.test')->value('storefront_country'));
    }

    /**
     * The duplicate check moves with the list. Before this, the second signup wrote nothing and the Kuwaiti operator's list could never
     * hold an address a Saudi visitor had used first.
     */
    public function test_the_same_address_may_subscribe_in_a_second_market(): void
    {
        $this->serveStorefront('SA');
        $this->subscribe('both@example.test');

        $this->serveStorefront('KW');
        $this->subscribe('both@example.test');

        $this->assertEqualsCanonicalizing(
            ['SA', 'KW'],
            DB::table('subscriptions')->where('email', 'both@example.test')->pluck('storefront_country')->all(),
        );
    }

    public function test_the_same_address_cannot_subscribe_twice_in_one_market(): void
    {
        $this->serveStorefront('KW');
        $this->subscribe('twice@example.test');
        $this->subscribe('twice@example.test');

        $this->assertSame(1, DB::table('subscriptions')->where('email', 'twice@example.test')->count());
    }

    /** A row written before the markets existed belongs to the default storefront, so a Saudi visitor is still told they subscribed. */
    public function test_an_address_subscribed_before_the_markets_existed_is_the_default_storefronts(): void
    {
        DB::table('subscriptions')->insert(['email' => 'legacy@example.test', 'created_at' => now(), 'updated_at' => now()]);

        $this->serveStorefront('SA');
        $this->subscribe('legacy@example.test');

        $this->assertSame(1, DB::table('subscriptions')->where('email', 'legacy@example.test')->count(), 'a second Saudi row was written for an address the list already held');

        // And it IS reachable from Kuwait as a new subscriber, because the legacy row is Saudi Arabia's and not everybody's.
        $this->serveStorefront('KW');
        $this->subscribe('legacy@example.test');

        $this->assertSame('KW', DB::table('subscriptions')->where('email', 'legacy@example.test')->whereNotNull('storefront_country')->value('storefront_country'));
    }

    public function test_the_admin_list_of_a_market_holds_its_own_subscribers_and_the_unmarked_ones(): void
    {
        $this->giveSubscriber('saudi@example.test', 'SA');
        $this->giveSubscriber('kuwaiti@example.test', 'KW');
        $this->giveSubscriber('legacy@example.test', null);
        $this->signInAsAdmin(1);

        $this->assertEqualsCanonicalizing(['saudi@example.test', 'legacy@example.test'], $this->listedEmails(market: 'SA'));
        $this->assertSame(['kuwaiti@example.test'], $this->listedEmails(market: 'KW'));
    }

    public function test_an_unrestricted_operator_who_chooses_no_market_sees_every_subscriber(): void
    {
        $this->giveSubscriber('saudi@example.test', 'SA');
        $this->giveSubscriber('kuwaiti@example.test', 'KW');
        $this->signInAsAdmin(1);

        $this->assertEqualsCanonicalizing(['saudi@example.test', 'kuwaiti@example.test'], $this->listedEmails(market: null));
    }

    public function test_an_operator_restricted_to_kuwait_sees_kuwaits_subscribers_without_choosing_anything(): void
    {
        $this->giveSubscriber('saudi@example.test', 'SA');
        $this->giveSubscriber('kuwaiti@example.test', 'KW');
        $this->restrictTo(admin: 2, countries: ['KW']);
        $this->signInAsAdmin(2);

        $this->assertSame(['kuwaiti@example.test'], $this->listedEmails(market: null));
    }

    /** A market that is not theirs is not theirs to choose. */
    public function test_an_operator_restricted_to_kuwait_cannot_list_another_markets_subscribers(): void
    {
        $this->giveSubscriber('saudi@example.test', 'SA');
        $this->giveSubscriber('kuwaiti@example.test', 'KW');
        $this->restrictTo(admin: 2, countries: ['KW']);
        $this->signInAsAdmin(2);

        $this->assertSame(['kuwaiti@example.test'], $this->listedEmails(market: 'SA'));
        $this->assertSame(['KW'], app(NewsletterMarkets::class)->inForce('SA'));
    }

    /**
     * The export is narrowed by the same rule, asserted through the model the exporter reads rather than by building a spreadsheet: what
     * must not happen is the QUERY reaching another market, and that is where it is decided.
     */
    public function test_the_export_reaches_the_same_subscribers_the_list_shows(): void
    {
        $this->giveSubscriber('saudi@example.test', 'SA');
        $this->giveSubscriber('kuwaiti@example.test', 'KW');
        $this->restrictTo(admin: 2, countries: ['KW']);
        $this->signInAsAdmin(2);

        $request = Request::create(uri: '/admin/customer/subscriber-list/export', parameters: ['market' => 'SA']);
        $this->app->instance('request', $request);
        app(NewsletterMarkets::class)->bind($request, $request->get('market'));

        $this->assertSame(['kuwaiti@example.test'], Subscription::query()->pluck('email')->all());
    }

    /** Nothing bound means nothing narrowed, which is every storefront page and every queue. */
    public function test_a_read_that_bound_no_market_is_not_narrowed(): void
    {
        $this->giveSubscriber('saudi@example.test', 'SA');
        $this->giveSubscriber('kuwaiti@example.test', 'KW');

        $this->assertCount(2, Subscription::query()->get());
        $this->assertNull(app(NewsletterMarkets::class)->boundMarkets());
    }

    // ---------------------------------------------------------------------------------------

    /** @return list<string> */
    private function listedEmails(?string $market): array
    {
        $request = Request::create(uri: '/admin/customer/subscriber-list', parameters: $market === null ? [] : ['market' => $market]);
        // Bound to the container as a real request is, because what the screen narrows to lives on the request and the model's scope
        // reads it from there -- a test that passes the controller one Request and leaves another in the container narrows nothing.
        $this->app->instance('request', $request);
        $view = app(CustomerController::class)->getSubscriberListView($request);

        return array_map(static fn ($row): string => (string) $row->email, iterator_to_array($view->getData()['subscriberList']));
    }

    private function subscribe(string $email): void
    {
        app(WebController::class)->subscription(Request::create(
            uri: '/subscription',
            method: 'POST',
            parameters: ['subscription_email' => $email],
        ));
    }

    private function giveSubscriber(string $email, ?string $market): void
    {
        DB::table('subscriptions')->insert([
            'email' => $email,
            'storefront_country' => $market,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function serveStorefront(string $country): void
    {
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context(explicitChoice: $country));
    }

    private function serveNoStorefront(): void
    {
        app()->forgetInstance(StorefrontContext::class);
    }

    private function buildTheSubscriberWorld(): void
    {
        Schema::dropIfExists('business_settings');
        Schema::create('business_settings', static function (Blueprint $table): void {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        // The screen's page size comes from the settings; without the row `getWebConfig` answers null and the repository refuses it.
        DB::table('business_settings')->insert([
            ['type' => 'pagination_limit', 'value' => '25', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::dropIfExists('subscriptions');
        Schema::create('subscriptions', static function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->timestamps();
        });
        // The real migration, so the column is exactly what a server gets -- ascii_bin and all, which is what makes 'kw' not 'KW'.
        (require database_path('migrations/2026_12_31_100000_add_storefront_country_to_subscriptions.php'))->up();

        // The staff country scope is enforced only once the products carry a market, so the column the scope reads has to be there.
        Schema::dropIfExists('products');
        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
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
            ['id' => 2, 'name' => 'Marketing', 'module_access' => json_encode(['customer']), 'status' => 1],
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
