<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Services\Platform\Policy;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\TestCase;

/**
 * The counters behind `throttle:` are not in the store the application empties.
 *
 * The sign-in throttle is the only thing between a sign-in form and a password guessed without limit: the captcha was taken out
 * by a product decision and the route file says so. Its counters used to sit in the default cache store, and the application
 * empties that store while it runs: a language change, which a visitor with no account can ask for, ends in `cache:clear`,
 * and several admin screens call `cache()->flush()`. Each of those gave every caller a fresh allowance on every throttled route.
 *
 * The counters now live in a store of their own (`cache.limiter`). These tests drive the real sign-in route to its refusal, empty
 * the cache in each of the ways the application does, and ask again; before the change every one of them answers as if no
 * guess had been made. The last test repeats it in the shape production runs in, two file stores in sibling directories,
 * because the suite itself runs on array stores and a file store is emptied by deleting a directory.
 */
class ThrottleCountersOutliveACacheFlushTest extends TestCase
{
    use BuildsFulfillmentSchema;

    private const CALLER = '203.0.113.9';

    private const ANOTHER_CALLER = '203.0.113.10';

    private const REFUSED = 429;

    /** What the sign-in answers a wrong password with: it sends the visitor back to the form. Anything else is not a guess that was let through. */
    private const SENT_BACK_TO_THE_FORM = 302;

    private ?string $fileStores = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCoreSchema();
        DB::table('business_settings')->updateOrInsert(['type' => 'language'], ['value' => json_encode([
            ['id' => 1, 'name' => 'english', 'code' => 'en', 'direction' => 'ltr', 'status' => 1, 'default' => true],
        ])]);
        Cache::flush();

        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class,
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if ($this->fileStores !== null) {
            File::deleteDirectory($this->fileStores);
        }

        parent::tearDown();
    }

    public function test_a_stranger_who_asks_for_a_language_change_does_not_get_a_fresh_allowance_of_guesses(): void
    {
        $this->guessUntilRefused();

        $this->post('/change-language', ['language_code' => 'en'])->assertOk();

        $this->assertFalse(auth('admin')->check(), 'the language change has to be asked for by somebody who is not signed in, or this proves nothing');
        $this->assertSame([self::REFUSED, self::REFUSED, self::REFUSED], [$this->guess(), $this->guess(), $this->guess()]);
    }

    public function test_the_refusal_outlives_every_way_the_application_empties_its_cache(): void
    {
        $this->guessUntilRefused();

        Artisan::call('cache:clear');
        $this->assertSame(self::REFUSED, $this->guess(), 'cache:clear emptied the counters of the throttle');

        cache()->flush();
        $this->assertSame(self::REFUSED, $this->guess(), 'cache()->flush() emptied the counters of the throttle');

        Cache::flush();
        $this->assertSame(self::REFUSED, $this->guess(), 'Cache::flush() emptied the counters of the throttle');
    }

    public function test_the_counters_are_kept_in_a_store_of_their_own(): void
    {
        $this->assertNotNull(config('cache.limiter'), 'with no limiter store named, the counters fall back to the default store');
        $this->assertNotSame(config('cache.default'), config('cache.limiter'));
        $this->assertNotSame(Cache::store()->getStore(), $this->storeOfTheCounters());
    }

    public function test_one_caller_who_is_refused_does_not_use_up_the_allowance_of_another(): void
    {
        $this->guessUntilRefused();

        $this->assertSame(self::SENT_BACK_TO_THE_FORM, $this->guess(from: self::ANOTHER_CALLER));
    }

    public function test_it_holds_in_the_shape_production_runs_in_two_file_stores_side_by_side(): void
    {
        $this->useFileStoresSideBySide();
        $this->guessUntilRefused();

        Artisan::call('cache:clear');
        $this->post('/change-language', ['language_code' => 'en'])->assertOk();

        $this->assertInstanceOf(\Illuminate\Cache\FileStore::class, $this->storeOfTheCounters());
        $this->assertSame(self::REFUSED, $this->guess(), 'emptying the default file store emptied the counters kept beside it');
    }

    /** One wrong password at the real admin sign-in route, and the status the application answered with. */
    private function guess(string $from = self::CALLER): int
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $from])
            ->post('/login', ['email' => 'nobody@example.test', 'password' => 'not-the-password', 'role' => 'admin'])
            ->getStatusCode();
    }

    /** Guesses until the throttle refuses, and fails if it lets through more than the policy allows: a throttle that never refuses protects nothing. */
    private function guessUntilRefused(): void
    {
        $allowed = app(Policy::class)->int('auth_attempts_per_minute');

        for ($attempt = 1; $attempt <= $allowed; $attempt++) {
            $this->assertSame(self::SENT_BACK_TO_THE_FORM, $this->guess(), "guess {$attempt} of the {$allowed} the policy allows was not let through as a wrong password is");
        }

        $this->assertSame(self::REFUSED, $this->guess(), "the throttle let a guess through after the {$allowed} the policy allows");
    }

    private function storeOfTheCounters(): object
    {
        return (new \ReflectionProperty(RateLimiter::class, 'cache'))->getValue(app(RateLimiter::class))->getStore();
    }

    /** The default store and the limiter store as two file stores in sibling directories, which is how the shipped configuration lays them out. */
    private function useFileStoresSideBySide(): void
    {
        $this->fileStores = storage_path('framework/testing/throttle-' . bin2hex(random_bytes(6)));

        config([
            'cache.default' => 'file',
            'cache.stores.file.path' => $this->fileStores . '/data',
            'cache.stores.limiter.driver' => 'file',
            'cache.stores.limiter.path' => $this->fileStores . '/limiter',
        ]);
        Cache::forgetDriver('file');
        Cache::forgetDriver('limiter');
        $this->app->forgetInstance('cache.store');

        // The limiter is given its store when it is built, and it also holds the named limits the routes ask for, so it is kept and
        // handed the store the framework would have built it with under this configuration.
        (new \ReflectionProperty(RateLimiter::class, 'cache'))->setValue(app(RateLimiter::class), Cache::store(config('cache.limiter')));
    }
}
