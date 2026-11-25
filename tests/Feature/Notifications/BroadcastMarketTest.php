<?php

namespace Tests\Feature\Notifications;

use App\Http\Controllers\Admin\Notification\NotificationController;
use App\Http\Requests\Admin\NotificationRequest;
use App\Models\Admin;
use App\Services\Notification\BroadcastMarkets;
use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontResolver;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\FakesThePushService;

/**
 * I1-04: an admin broadcast that is for one market reaches that market and no other.
 *
 * A broadcast went to every customer the platform had, on one topic, and was listed in every app inbox -- which is right for one market and
 * wrong in both directions for six: a Saudi customer is shown a Kuwaiti promotion that does not apply to them and whose prices are in
 * another currency, and the Kuwaiti customer who should have seen it is not told it was meant for them.
 *
 * Three things are asserted, because a broadcast reaches a customer by two different routes and both have to agree:
 *   - the PUSH goes to the market's own topic and NOT to the platform-wide one, read off the request that reached FCM;
 *   - the app INBOX of another market does not list it, and the unread counter counts the same rows the list shows;
 *   - an employee restricted to Kuwait can target Kuwait and nothing else, whatever the form carries.
 *
 * What is NOT asserted: whether a device is reached. A device receives a market's topic only once its app build subscribes to that topic,
 * which is the customer app's half of this requirement (I1-04, in lavkooapp) and is not in this repository. What is asserted is the name the
 * server published to, which is the half that has to be right first.
 */
class BroadcastMarketTest extends ApiAddressTestCase
{
    use FakesThePushService;

    private const PLATFORM_WIDE_TOPIC = 'sixvalley';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.mode' => 'live']);
        $this->createOrderPipelineTables();
        $this->configureThePushService();
        $this->createTheBroadcastTables();
        $this->buildTheAdmins();
        ToastMagic::clear();
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true, 'gcc.storefronts.countries.AE.active' => true]);
        app(BroadcastMarkets::class)->flush();
        $this->signInAsAdmin(1);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_seens');
        Schema::dropIfExists(StaffCountryScope::TABLE);
        Schema::dropIfExists('admins');
        Schema::dropIfExists('admin_roles');
        app(BroadcastMarkets::class)->flush();

        parent::tearDown();
    }

    /** The first test of the switch: with storefronts off a broadcast names no market and goes where it has always gone. */
    public function test_while_storefronts_are_off_a_broadcast_names_no_market_and_goes_to_the_platform_wide_topic(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        app(BroadcastMarkets::class)->flush();

        $this->broadcast(title: 'Weekend sale', markets: ['KW']);

        $this->assertSame([self::PLATFORM_WIDE_TOPIC], $this->topicsPublished());
        $this->assertNull(DB::table('notifications')->value(BroadcastMarkets::COLUMN), 'a market was stored while storefronts are off');
    }

    public function test_a_broadcast_for_kuwait_is_published_to_kuwaits_topic_only(): void
    {
        $this->broadcast(title: 'Kuwaiti weekend sale', markets: ['KW']);

        $this->assertSame(['sixvalley_KW'], $this->topicsPublished(), 'the broadcast did not go to Kuwait\'s topic alone');
        $this->assertSame('["KW"]', DB::table('notifications')->value(BroadcastMarkets::COLUMN));
    }

    public function test_a_broadcast_for_two_markets_is_published_to_each_of_their_topics_and_to_no_platform_wide_one(): void
    {
        $this->broadcast(title: 'Gulf weekend sale', markets: ['KW', 'AE']);

        // In the order the markets are CONFIGURED, not the order the form listed them: the stored list and the topics published to are one
        // list, and a list whose order depends on which checkbox was ticked first is a list two readings of one broadcast can disagree on.
        $this->assertSame(['sixvalley_AE', 'sixvalley_KW'], $this->topicsPublished());
        $this->assertNotContains(self::PLATFORM_WIDE_TOPIC, $this->topicsPublished(), 'a broadcast for two markets also reached every device');
    }

    public function test_a_broadcast_for_no_market_goes_to_the_platform_wide_topic_exactly_as_before(): void
    {
        $this->broadcast(title: 'Platform news', markets: []);

        $this->assertSame([self::PLATFORM_WIDE_TOPIC], $this->topicsPublished());
        $this->assertNull(DB::table('notifications')->value(BroadcastMarkets::COLUMN));
    }

    /** The app inbox: the market's own broadcasts and the ones that name no market, and nothing else. */
    public function test_the_app_inbox_of_a_market_lists_its_own_broadcasts_and_the_unmarked_ones_only(): void
    {
        $this->storeBroadcast(title: 'Kuwaiti sale', markets: ['KW']);
        $this->storeBroadcast(title: 'Emirati sale', markets: ['AE']);
        $this->storeBroadcast(title: 'Platform news', markets: []);

        // Canonicalizing because the three rows carry one timestamp and `latest()` has nothing to order them by; what is asserted is WHICH
        // broadcasts each market is shown, which is the whole question.
        $this->assertEqualsCanonicalizing(['Platform news', 'Kuwaiti sale'], $this->inboxTitlesOf(market: 'KW'));
        $this->assertEqualsCanonicalizing(['Platform news', 'Emirati sale'], $this->inboxTitlesOf(market: 'AE'));
    }

    /** And the counter counts the same rows: a badge of three over a list of two is how a customer learns to ignore the badge. */
    public function test_the_unread_counter_counts_the_rows_the_list_shows(): void
    {
        $this->storeBroadcast(title: 'Kuwaiti sale', markets: ['KW']);
        $this->storeBroadcast(title: 'Emirati sale', markets: ['AE']);
        $this->storeBroadcast(title: 'Platform news', markets: []);

        $inbox = $this->inboxOf(market: 'KW');

        $this->assertSame(2, $inbox['total_size']);
        $this->assertSame(2, $inbox['new_notification']);
    }

    /** Resending repeats a message; it does not re-target it. */
    public function test_a_resend_goes_to_the_markets_the_broadcast_was_sent_with(): void
    {
        $this->broadcast(title: 'Kuwaiti sale', markets: ['KW']);
        $id = DB::table('notifications')->value('id');

        $response = app(NotificationController::class)->resendNotification(Request::create(uri: '/admin/notification/resend-notification', method: 'POST', parameters: ['id' => $id]));

        $this->assertTrue($response->getData(true)['success'], 'the resend did not reach the push');
        $this->assertSame(['sixvalley_KW', 'sixvalley_KW'], $this->topicsPublished());
    }

    public function test_an_employee_restricted_to_kuwait_is_offered_kuwait_only(): void
    {
        $this->restrictTo(admin: 2, countries: ['KW']);
        $this->signInAsAdmin(2);

        $this->assertSame(['KW'], app(BroadcastMarkets::class)->choices());
        $this->assertFalse(app(BroadcastMarkets::class)->mayChooseEveryMarket());
    }

    /** Whatever the form carries, a restricted employee's broadcast goes to their own markets: the field cannot widen their reach. */
    public function test_an_employee_restricted_to_kuwait_cannot_target_another_market_or_every_market(): void
    {
        $this->restrictTo(admin: 2, countries: ['KW']);
        $this->signInAsAdmin(2);

        $this->broadcast(title: 'Emirati sale by a Kuwaiti employee', markets: ['AE']);

        $this->assertSame(['sixvalley_KW'], $this->topicsPublished(), 'a restricted employee reached a market that is not theirs');
        $this->assertSame('["KW"]', DB::table('notifications')->value(BroadcastMarkets::COLUMN));
    }

    public function test_a_restricted_employee_who_chooses_nothing_reaches_their_own_markets_and_not_every_market(): void
    {
        $this->restrictTo(admin: 2, countries: ['KW']);
        $this->signInAsAdmin(2);

        $this->broadcast(title: 'No market chosen', markets: []);

        $this->assertSame(['sixvalley_KW'], $this->topicsPublished(), 'a restricted employee who chose nothing broadcast platform-wide');
    }

    /** An employee whose scope grants NO market reaches nobody, and an empty list means every market -- so they are refused instead. */
    public function test_an_employee_whose_scope_grants_no_market_may_not_broadcast(): void
    {
        $this->restrictTo(admin: 2, countries: ['ZZ']);
        $this->signInAsAdmin(2);

        $this->broadcast(title: 'Sent by nobody', markets: []);

        $this->assertSame([], $this->topicsPublished(), 'an employee with no market of their own broadcast to every device');
        $this->assertSame(0, DB::table('notifications')->count(), 'the broadcast was stored although it was refused');
    }

    /** @param list<string> $markets */
    private function broadcast(string $title, array $markets): void
    {
        app(NotificationController::class)->add(NotificationRequest::create(
            uri: '/admin/notification/add',
            method: 'POST',
            parameters: ['title' => $title, 'description' => 'Ten per cent off', 'storefront_countries' => $markets],
        ));
    }

    /** One stored broadcast, as the admin screen would have written it, without sending anything. */
    private function storeBroadcast(string $title, array $markets): void
    {
        DB::table('notifications')->insert([
            'sent_to' => 'customer',
            'title' => $title,
            'description' => 'Ten per cent off',
            'status' => 1,
            BroadcastMarkets::COLUMN => $markets === [] ? null : json_encode($markets),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function inboxOf(string $market): array
    {
        return $this->withHeader(StorefrontResolver::HEADER, $market)
            ->getJson('/api/v1/notifications?limit=10&offset=1')
            ->assertOk()
            ->json();
    }

    /** @return list<string> */
    private function inboxTitlesOf(string $market): array
    {
        return array_map(static fn (array $row): string => (string) $row['title'], $this->inboxOf($market)['notification']);
    }

    /** @return list<string> */
    private function topicsPublished(): array
    {
        return array_map(static fn ($push): string => $push['message']['topic'], $this->pushesSent());
    }

    private function createTheBroadcastTables(): void
    {
        Schema::dropIfExists('notifications');
        Schema::create('notifications', static function (Blueprint $table): void {
            $table->id();
            $table->string('sent_by')->nullable();
            $table->string('sent_to')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('notification_count')->default(0);
            $table->string('image')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        // The real migration, so the column is exactly what a server gets rather than a hand-written guess at it.
        (require database_path('migrations/2026_12_30_100000_add_markets_to_notifications_table.php'))->up();

        Schema::dropIfExists('notification_seens');
        Schema::create('notification_seens', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('notification_id')->nullable();
            $table->timestamps();
        });

        // The staff country scope is only enforced once the products carry a market, so the real migration runs here too.
        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();

        Schema::dropIfExists(StaffCountryScope::TABLE);
        Schema::create(StaffCountryScope::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->string('country', 2);
            $table->timestamps();
        });
        StaffCountryScope::forgetTheSchema();
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
            ['id' => 2, 'name' => 'Marketing', 'module_access' => json_encode(['marketing']), 'status' => 1],
        ]);
        DB::table('admins')->insert([
            ['id' => 1, 'name' => 'Master', 'email' => 'master@example.test', 'admin_role_id' => 1],
            ['id' => 2, 'name' => 'Marketing', 'email' => 'marketing@example.test', 'admin_role_id' => 2],
        ]);
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
