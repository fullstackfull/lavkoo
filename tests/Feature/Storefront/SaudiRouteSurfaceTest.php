<?php

namespace Tests\Feature\Storefront;

use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A1-08 of the master plan: the old Saudi URLs and API contracts keep working unchanged.
 *
 * The live platform is Saudi Arabia. Every address a shopper has bookmarked, every endpoint both
 * Flutter applications call and every route name a Blade template or a redirect names is a promise
 * this chain of work is not allowed to break. Until now that promise was checked once, by hand: a
 * scratch count of 191 against 192 api/v1 routes written into the body of pull request 28. A count
 * cannot see a rename, and a number in a pull request body is not run by anything.
 *
 * So the route surface of `main` is committed as a fixture and compared here, on the integration
 * tree that carries the whole chain. The comparison is by method, URI and route name together,
 * because a route that keeps its URI and loses its name breaks every `route()` call that names it,
 * and one that keeps its name and changes its URI breaks every bookmark.
 *
 * ## What the fixture is, and how to rebuild it
 *
 * `tests/Fixtures/routes-on-main.txt` is one line per registered route, `METHOD uri | name`, sorted,
 * HEAD left out (Laravel registers it beside every GET) and a route with no name written `(none)`
 * rather than left blank, so that no line of it ends in whitespace an editor or a diff tool could
 * eat. It was produced by booting the application on origin/main and walking `Route::getRoutes()`. To rebuild it after main itself moves: check main
 * out, boot the test application, write that list, and commit the new file in a commit of its own
 * that explains which commit of main it came from. Never edit a line of it to make this test pass —
 * that is the one change this test exists to catch.
 *
 * ## The declared additions
 *
 * The chain adds routes; it is allowed to, and the point is that what it adds is a short list someone
 * chose rather than whatever happened. Two things are added, and nothing is taken away:
 *
 *   - `GET api/v1/storefronts`, the list of markets, which is the one addition A1-08 names;
 *   - the `api/platform-admin/v1` namespace, the GCC-20 platform administrator API, which is a new
 *     namespace of its own and touches no address the Saudi platform serves (axis L).
 *
 * A route added outside those two fails this test, and the right answer is then to decide whether it
 * belongs in the list, not to widen the list reflexively.
 */
class SaudiRouteSurfaceTest extends TestCase
{
    private const BASELINE = __DIR__ . '/../../Fixtures/routes-on-main.txt';

    private const ROUTES_ON_MAIN = 2312;

    private const UNNAMED = '(none)';

    private const STOREFRONT_LIST = 'GET api/v1/storefronts | api.v1.storefronts';

    private const PLATFORM_ADMIN_PREFIX = 'api/platform-admin/v1';

    /** @return list<string> one line per route of this tree, as the fixture spells them */
    private function routesHere(): array
    {
        $rows = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($this->methodsOf(route: $route) as $method) {
                $rows[] = $method . ' ' . $route->uri() . ' | ' . ($route->getName() ?: self::UNNAMED);
            }
        }

        $rows = array_values(array_unique($rows));
        sort($rows);

        return $rows;
    }

    /** @return list<string> every method the route answers except HEAD, which Laravel registers beside every GET */
    private function methodsOf(RegisteredRoute $route): array
    {
        return array_values(array_diff($route->methods(), ['HEAD']));
    }

    /** @return list<string> */
    private function routesOnMain(): array
    {
        $lines = explode("\n", (string) file_get_contents(self::BASELINE));
        $lines = array_filter(array_map(fn (string $line): string => rtrim($line, "\r"), $lines), fn (string $line): bool => $line !== '');

        return array_values($lines);
    }

    public function test_the_baseline_fixture_is_the_route_set_it_says_it_is(): void
    {
        $onMain = $this->routesOnMain();

        $this->assertFileExists(self::BASELINE);
        $this->assertCount(self::ROUTES_ON_MAIN, $onMain, 'the fixture has been edited: rebuild it from main in a commit of its own instead');
        $this->assertSame($onMain, array_values(array_unique($onMain)), 'the fixture lists a route twice');
    }

    public function test_no_route_of_the_saudi_platform_has_disappeared(): void
    {
        $gone = array_values(array_diff($this->routesOnMain(), $this->routesHere()));

        $this->assertSame(
            [],
            $gone,
            "a route main serves is not served here. Each line is 'METHOD uri | name': a URI change breaks a bookmark, a name change breaks every route() call that names it.",
        );
    }

    public function test_every_route_this_chain_adds_is_one_that_was_declared(): void
    {
        $added = array_values(array_diff($this->routesHere(), $this->routesOnMain()));
        $undeclared = array_values(array_filter($added, fn (string $row): bool => !$this->isDeclared(row: $row)));

        $this->assertSame([], $undeclared, 'this route is new and is neither the storefront list nor part of the platform admin API; decide where it belongs rather than widening the list');
    }

    public function test_the_storefront_list_is_added(): void
    {
        $added = array_diff($this->routesHere(), $this->routesOnMain());

        $this->assertContains(self::STOREFRONT_LIST, $added, 'the one addition A1-08 names is not here');
    }

    public function test_the_platform_admin_api_is_a_namespace_of_its_own_and_is_all_that_else_is_added(): void
    {
        $added = array_values(array_diff($this->routesHere(), $this->routesOnMain()));
        $platformAdmin = array_values(array_filter($added, fn (string $row): bool => $this->isPlatformAdmin(row: $row)));

        $this->assertNotEmpty($platformAdmin, 'the platform admin API is not on this tree, so this test is being run somewhere it does not belong');
        $this->assertCount(
            count($added) - 1,
            $platformAdmin,
            'everything added beyond the storefront list has to be in the platform admin namespace',
        );
    }

    public function test_the_platform_admin_api_shares_no_address_with_the_saudi_platform(): void
    {
        $onMain = array_values(array_filter($this->routesOnMain(), fn (string $row): bool => $this->isPlatformAdmin(row: $row)));

        $this->assertSame([], $onMain, 'main already serves something under the platform admin prefix, so it is not a namespace of its own');
    }

    private function isDeclared(string $row): bool
    {
        return $row === self::STOREFRONT_LIST || $this->isPlatformAdmin(row: $row);
    }

    private function isPlatformAdmin(string $row): bool
    {
        [$method, $rest] = explode(' ', $row, 2);
        $uri = explode(' | ', $rest, 2)[0];

        return $method !== '' && str_starts_with($uri, self::PLATFORM_ADMIN_PREFIX);
    }
}
