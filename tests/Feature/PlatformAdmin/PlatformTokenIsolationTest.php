<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\RestoresLanguageFiles;

/**
 * A live platform token is worth nothing anywhere else. The routes are read from the route table of the application, every one outside the platform API, so a
 * route added later is walked without anybody remembering to list it: each is asked with a live token and with a token of the same shape that was never
 * issued, and the two answers are the same. (Asking without a header at all would not do: a route that serves a guest and a signed-in customer, like the
 * cart, answers differently when any bearer header is there.) A route whose answer is not the same twice (a page with a token of its own in it) is compared
 * by what is stable. Afterwards the time the session was last used has not moved and the platform's trail has no line.
 */
class PlatformTokenIsolationTest extends PlatformAdminTestCase
{
    use RestoresLanguageFiles;

    /** Routes whose answer changes from one request to the next may be at most this many of the routes asked, so that "unstable" is not a way out. */
    private const MOST_UNSTABLE_ROUTES = 20;

    private string $addonsFile;

    private string $addonsContents;

    /** @var list<string> tokens, and the hashes other guards look them up by, to be taken out of an answer before it is compared: with debug on, the message of a database error says the value it was looking for */
    private array $tokensToBlank = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->addonsFile = base_path('config/system-addons.php');
        $this->addonsContents = (string) file_get_contents($this->addonsFile);
        Http::fake();
        Mail::fake();
        Queue::fake();
        config(['logging.default' => 'null']);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        $this->restoreAddonsFile();

        parent::tearDown();
    }

    public function test_a_live_platform_token_changes_the_answer_of_no_route_outside_the_platform_api(): void
    {
        $admin = $this->platformAdmin();
        $liveToken = $this->mintToken($admin);
        $neverIssuedToken = 'lkpa_' . Str::random(64);
        $this->tokensToBlank = [$liveToken, $neverIssuedToken, hash('sha256', $liveToken), hash('sha256', $neverIssuedToken)];
        $live = $this->bearer($liveToken);
        $neverIssued = $this->bearer($neverIssuedToken);
        $before = $this->platformState();
        $routes = $this->routesOutsideThePlatformApi();
        $this->assertGreaterThan(1000, count($routes), 'the whole route table of the application is walked, not a list');

        $different = [];
        $unstable = [];
        foreach ($routes as $route) {
            [$method, $uri] = $this->requestTo($route);

            $control = $this->answerOf($method, $uri, $neverIssued);
            $answer = $this->answerOf($method, $uri, $live);
            if ($control === $answer) {
                continue;
            }

            if ($this->answerOf($method, $uri, $neverIssued) === $control) {
                $different[] = "{$method} {$uri}";
            } elseif ($this->stableAnswerOf($control) !== $this->stableAnswerOf($answer)) {
                $different[] = "{$method} {$uri} (unstable)";
            } else {
                $unstable[] = "{$method} {$uri}";
            }
        }

        $this->assertSame([], $different, 'a platform token changes the answer of a route outside the platform API');
        $this->assertLessThanOrEqual(self::MOST_UNSTABLE_ROUTES, count($unstable), 'too many routes answer differently every time: ' . implode(', ', array_slice($unstable, 0, 10)));
        $this->assertSame($before, $this->platformState(), 'the token was not used by any of them: not the time it was last used, not a line of the trail');
    }

    public function test_the_walk_would_see_a_route_that_does_read_the_token(): void
    {
        Route::get('/__isolation-control', fn () => response()->json(['signed_in' => DB::table('platform_admin_tokens')->where('token_hash', hash('sha256', (string) request()->bearerToken()))->exists()]));
        $live = $this->mintToken($this->platformAdmin());

        $neverIssued = $this->answerOf('GET', '/__isolation-control', $this->bearer('lkpa_' . Str::random(64)));
        $answer = $this->answerOf('GET', '/__isolation-control', $this->bearer($live));

        $this->assertNotSame($neverIssued, $answer, 'the comparison used above can tell a route that reads the token from one that does not');
    }

    /** @return list<RoutingRoute> */
    private function routesOutsideThePlatformApi(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (RoutingRoute $route): bool => !str_starts_with($route->uri(), 'api/platform-admin/v1'),
        ));
    }

    /** @param array<string, string> $headers */
    private function answerOf(string $method, string $uri, array $headers): string
    {
        Cache::flush();
        $this->flushSession();
        $response = $this->json($method, $uri, [], $headers);
        $this->restoreAddonsFile();

        return str_replace($this->tokensToBlank, '<token>', $this->saying($response) . '|' . (string) $response->headers->get('Location'));
    }

    /** What is the same however often it is asked: the status and where it redirects to. */
    private function stableAnswerOf(string $answer): string
    {
        return substr($answer, 0, 3) . '|' . substr($answer, (int) strrpos($answer, '|'));
    }

    private function restoreAddonsFile(): void
    {
        if ((string) file_get_contents($this->addonsFile) !== $this->addonsContents) {
            file_put_contents($this->addonsFile, $this->addonsContents);
        }
    }

    private function platformState(): string
    {
        return json_encode([DB::table('platform_admin_tokens')->get(), DB::table('platform_admin_audit_logs')->get(), DB::table('platform_admin_audit_tail')->get()]);
    }
}
