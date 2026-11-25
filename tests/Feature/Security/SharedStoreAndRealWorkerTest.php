<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Storefront\OrderNotificationTestCase;
use App\Services\Storefront\StorefrontContext;
use Illuminate\Mail\Events\MessageSending;
use Tests\Support\BuildsNotificationProfiles;

/**
 * A market's cached value and a market's queued message with a SHARED cache store and a REAL worker (M2-18).
 *
 * ## Why every other test in this repository proves less about this than it looks
 *
 * `phpunit.xml` and `phpunit.mysql.xml` both pin `CACHE_DRIVER=array` and `QUEUE_CONNECTION=sync`, and nothing has ever run otherwise.
 * Those two settings remove the two conditions that a leak between markets needs:
 *
 *   - the ARRAY store lives inside one process and is thrown away with it, so a key that names no market cannot be served to the next
 *     market, because there is no next market. On the server the store is shared -- the operating guide says never to leave it on `array`
 *     before the storefronts switch goes on -- and the first market to warm a key serves its own content to the other five, past a deploy,
 *     until something clears it;
 *   - the SYNC queue runs a listener inside the dispatching request, which still holds the dispatcher's market in memory. The listener
 *     then reads the right market for the wrong reason. A real worker boots once, holds no market, and takes a SERIALIZED job off the
 *     queue: whatever the market was, it has to have travelled in the payload or come off the order.
 *
 * So this is the arrangement the threat model names (`docs/gcc/THREAT_MODEL.md`, classes 7 and 8), and it had never been run.
 *
 * ## What makes the worker's half honest
 *
 * `Artisan::call('queue:work --once')` runs in this process, so a market left in a singleton by the dispatching request would still be
 * there and the test would pass for the wrong reason. The request's market is therefore FORGOTTEN before the queue is worked, and the test
 * asserts that it is: at the moment the message is built the worker is serving no storefront at all, exactly as a real worker is. The only
 * remaining way for the Kuwaiti account to be used is the one the platform claims -- the market comes off the ORDER.
 *
 * ## What it still does not prove
 *
 * One process, so it does not prove what two workers do to each other, and the cache half uses the DATABASE store rather than Redis,
 * which is what the server will run. Both are shared stores that outlive a request, which is the property under test; Redis's own
 * behaviour (eviction, a flush of the wrong database) is not covered here.
 */
class SharedStoreAndRealWorkerTest extends OrderNotificationTestCase
{
    use BuildsNotificationProfiles;

    private const CACHE_TABLE = 'cache';

    private const JOBS_MIGRATION = '2022_04_19_095926_create_jobs_table.php';

    /** @var list<string> the tables this test built and must take away again */
    private array $built = [];

    /** @var list<array{host: ?string, from: ?string}> what each send left through, read at the moment of the send */
    private array $sends = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildNotificationProfilesTable();
        $this->captureTheEmailsTheMailerRenders();
        config(['mail.host' => 'smtp.platform.example', 'mail.from.address' => 'noreply@platform.example']);
        $this->seedOrderPlacedEmailTemplate(titleInEnglish: 'Your order is placed', titleInArabic: 'تم إنشاء طلبك', design: 'order-place');
        $this->watchWhatEachSendLeftThrough();
    }

    protected function tearDown(): void
    {
        $this->dropNotificationProfilesTable();

        foreach ($this->built as $table) {
            Schema::dropIfExists($table);
        }
        $this->built = [];

        parent::tearDown();
    }

    public function test_a_markets_cached_value_is_not_served_to_another_market_from_a_store_they_share(): void
    {
        $this->useASharedCacheStore();
        $this->switchStorefrontsOn();

        $kuwaiti = $this->cacheTheHomeCategoriesOf('KW');
        $emirati = $this->cacheTheHomeCategoriesOf('AE');

        $this->assertNotSame(
            $kuwaiti,
            $emirati,
            'two markets were served from one shared store under the same key: the first to warm it decides what the others see',
        );
        $this->assertSame($kuwaiti, $this->cacheTheHomeCategoriesOf('KW'), 'and each market keeps reading its own');

        $this->assertGreaterThanOrEqual(2, DB::table(self::CACHE_TABLE)->count(), 'the entries must really be in the shared store, not in a process');
    }

    public function test_the_saudi_market_reads_the_key_it_always_had_from_a_shared_store(): void
    {
        $this->useASharedCacheStore();

        $beforeTheSwitch = $this->cacheTheHomeCategoriesOf('SA');
        $keysBefore = $this->keysOfTheSharedStore();

        DB::table(self::CACHE_TABLE)->delete();
        $this->switchStorefrontsOn();
        $this->cacheTheHomeCategoriesOf('SA');

        $this->assertSame($keysBefore, $this->keysOfTheSharedStore(), 'the default market must keep the key it has always had, so its cache is not cold after the deploy');
        $this->assertNotSame([], $keysBefore);
        $this->assertNotNull($beforeTheSwitch);
    }

    public function test_a_queued_message_taken_off_a_real_queue_by_a_worker_that_serves_no_market_uses_the_markets_own_account(): void
    {
        $this->useARealQueue();
        $this->switchNotificationProfilesOn();
        $this->giveMarketItsOwnAccount(
            channel: 'mail',
            country: 'KW',
            settings: ['host' => 'smtp.kw.example', 'from_address' => 'orders@kw.example'],
        );
        $this->switchStorefrontsOn();

        $order = $this->sellOrderIn(country: 'KW', orderId: 7701);
        $this->placeOrderMailFor($order);

        $this->assertSame(1, DB::table('jobs')->count(), 'the listener must be on the queue, not run inside this request');
        $this->assertSame([], $this->sends, 'and nothing may have been sent yet');

        $this->forgetWhatTheRequestWasServing();
        $this->workTheQueueOnce();

        $this->assertCount(1, $this->sends, 'the worker sent exactly one e-mail');
        $this->assertSame('smtp.kw.example', $this->sends[0]['host'], "the market came off the order, which is the only place the worker could have read it");
        $this->assertSame(0, DB::table('jobs')->count(), 'and the job was taken off the queue');
    }

    public function test_a_worker_that_takes_two_markets_messages_one_after_the_other_gives_each_its_own_account(): void
    {
        $this->useARealQueue();
        $this->switchNotificationProfilesOn();
        $this->giveMarketItsOwnAccount(channel: 'mail', country: 'KW', settings: ['host' => 'smtp.kw.example', 'from_address' => 'orders@kw.example']);
        $this->giveMarketItsOwnAccount(channel: 'mail', country: 'AE', settings: ['host' => 'smtp.ae.example', 'from_address' => 'orders@ae.example']);
        $this->switchStorefrontsOn();

        $this->placeOrderMailFor($this->sellOrderIn(country: 'KW', orderId: 7711));
        $this->placeOrderMailFor($this->sellOrderIn(country: 'AE', orderId: 7712));

        $this->assertSame(2, DB::table('jobs')->count());

        $this->forgetWhatTheRequestWasServing();
        $this->workTheQueueOnce();
        $this->workTheQueueOnce();

        $this->assertSame(
            ['smtp.kw.example', 'smtp.ae.example'],
            array_column($this->sends, 'host'),
            'the second message must not inherit the account the worker applied for the first',
        );
    }

    /**
     * What a message left through, read AT THE MOMENT OF THE SEND out of the event the real mailer fires before it hands the message to its
     * transport. An account that was applied and then put back looks identical from outside the send.
     */
    private function watchWhatEachSendLeftThrough(): void
    {
        Event::listen(MessageSending::class, function (MessageSending $sending): void {
            $from = $sending->message->getFrom();

            $this->sends[] = ['host' => (string) config('mail.host'), 'from' => $from === [] ? null : $from[0]->getAddress()];
        });
    }

    /**
     * The database cache store, which outlives the request as the server's store does. There is no migration for the `cache` table (it is
     * not a table this platform uses yet), so absent IS its migrated state and dropping it in tearDown is the restore.
     */
    private function useASharedCacheStore(): void
    {
        if (!Schema::hasTable(self::CACHE_TABLE)) {
            Schema::create(self::CACHE_TABLE, function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
            $this->built[] = self::CACHE_TABLE;
        }

        config(['cache.default' => 'database']);
        Cache::purge('database');
    }

    /**
     * The database queue driver with the platform's own `jobs` table, built by its real migration -- which is one of the old named-class
     * migrations (`CreateJobsTable`), so it is required ONCE per process and instantiated by name: `require` it twice and PHP redeclares
     * the class and the process dies, which is not a test failure but a fatal error.
     *
     * `failed_jobs` is built here rather than by a migration because this platform has none for it; a failure that had nowhere to go would
     * be swallowed, and the worker's own assertion below reads that table.
     */
    private function useARealQueue(): void
    {
        if (!Schema::hasTable('jobs')) {
            require_once database_path('migrations/' . self::JOBS_MIGRATION);
            (new \CreateJobsTable())->up();
            $this->built[] = 'jobs';
        }

        if (!Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
            $this->built[] = 'failed_jobs';
        }

        config(['queue.default' => 'database']);
    }

    /**
     * What a real worker is: a process that booted before any of these orders existed and is serving nobody. Without this the worker would
     * still be holding the market of the request that dispatched the job, and the assertion would pass for the wrong reason.
     */
    private function forgetWhatTheRequestWasServing(): void
    {
        app()->forgetInstance(StorefrontContext::class);

        $this->assertSame(
            'SA',
            StorefrontContext::current()->country,
            'the worker must be serving the default storefront and not the market that dispatched the job, or this test proves nothing about where the market came from',
        );
    }

    private function workTheQueueOnce(): void
    {
        $exit = Artisan::call('queue:work', ['--once' => true, '--no-interaction' => true, '--stop-when-empty' => true]);

        $this->assertSame(0, $exit, 'the worker failed: ' . Artisan::output());
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'the job failed on the queue: ' . json_encode(DB::table('failed_jobs')->pluck('exception')->all()));
    }

    /** @return list<string> */
    private function keysOfTheSharedStore(): array
    {
        $keys = DB::table(self::CACHE_TABLE)->pluck('key')->map(static fn (string $key): string => $key)->all();
        sort($keys);

        return $keys;
    }

    private function cacheTheHomeCategoriesOf(string $country): mixed
    {
        $this->serveRequestFrom($country);

        return Cache::remember($this->homeCategoriesKey(), 60, fn (): array => ['country' => $country, 'categories' => [$country . '-category']]);
    }

    /** The key the platform's own helper builds for the market in force, so the test does not invent the key whose scoping it asserts. */
    private function homeCategoriesKey(): string
    {
        return StorefrontContext::current()->scopedCacheKey('cache_home_categories_api_list');
    }
}
