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
 *     namespace of its own and touches no address the Saudi platform serves (axis L);
 *   - the routes in DECLARED_ADDITIONS below, each written out and grouped under the lane that added
 *     it. That list grew when the lanes were unified: the question this test asks is not "has anything
 *     been added" but "is every addition one somebody chose", so each is named rather than matched by
 *     a prefix, and a route added outside the list still fails.
 *
 * ## The declared departures
 *
 * Three addresses main serves are not served here, and all three are deliberate; they are in
 * DECLARED_DEPARTURES with the reason. The test still fails for any other one, which is the point:
 * nothing may quietly stop being served.
 *
 * A route added or removed outside those lists fails this test, and the right answer is then to decide
 * whether it belongs in the list, not to widen the list reflexively.
 */
class SaudiRouteSurfaceTest extends TestCase
{
    private const BASELINE = __DIR__ . '/../../Fixtures/routes-on-main.txt';

    private const ROUTES_ON_MAIN = 2312;

    private const UNNAMED = '(none)';

    private const STOREFRONT_LIST = 'GET api/v1/storefronts | api.v1.storefronts';

    private const PLATFORM_ADMIN_PREFIX = 'api/platform-admin/v1';

    /**
     * Every other route this chain adds, by the lane that added it. A prefix match would have let a lane add anything under a folder it had
     * already opened, so each is written out and the whole set is compared, which is what makes a thirty-fourth fail.
     *
     * @var list<string>
     */
    private const DECLARED_ADDITIONS = [
        // The digital-file privatisation lane. A customer's paid file used to be served from a public folder by its name; every surface that
        // hands one over now answers through a route that checks who is asking, which is why each of these exists on all five surfaces
        // (admin, vendor, the two seller APIs, the website) and why each takes an optional file name.
        'GET admin/orders/digital-file/{id}/{fileName?} | admin.orders.digital-file',
        'GET admin/products/digital-file/{id}/{fileName?} | admin.products.digital-file',
        'GET admin/products/digital-variation-file/{id}/{fileName?} | admin.products.digital-variation-file',
        'GET api/v1/customer/order/digital-product-download/{id}/{fileName?} | customer-api.orders.digital-file',
        'GET api/v2/seller/orders/digital-file/{id}/{fileName?} | seller-api.v2.orders.digital-file',
        'GET api/v2/seller/products/digital-file/{id}/{fileName?} | seller-api.v2.products.digital-file',
        'GET api/v2/seller/products/digital-variation-file/{id}/{fileName?} | seller-api.v2.products.digital-variation-file',
        'GET api/v3/seller/orders/digital-file/{id}/{fileName?} | seller-api.v3.orders.digital-file',
        'GET api/v3/seller/products/digital-file/{id}/{fileName?} | seller-api.v3.products.digital-file',
        'GET api/v3/seller/products/digital-variation-file/{id}/{fileName?} | seller-api.v3.products.digital-variation-file',
        'GET digital-product-download-file/{id}/{fileName?} | digital-product-download-file',
        'GET vendor/orders/digital-file/{id}/{fileName?} | vendor.orders.digital-file',
        'GET vendor/products/digital-file/{id}/{fileName?} | vendor.products.digital-file',
        'GET vendor/products/digital-variation-file/{id}/{fileName?} | vendor.products.digital-variation-file',

        // The storefront lifecycle and the legal texts a market must publish before it may sell: the market list and its four lifecycle
        // moves, and the seven a legal text goes through. All under admin/storefronts, all gated by EnsureStorefrontPermission.
        'GET admin/storefronts | admin.storefronts.index',
        'POST admin/storefronts/{country}/activate | admin.storefronts.activate',
        'POST admin/storefronts/{country}/pause | admin.storefronts.pause',
        'POST admin/storefronts/{country}/ready | admin.storefronts.ready',
        'POST admin/storefronts/{country}/resume | admin.storefronts.resume',
        'GET admin/storefronts/{country}/legal-texts | admin.storefronts.legal-texts.index',
        'POST admin/storefronts/{country}/legal-texts | admin.storefronts.legal-texts.draft',
        'POST admin/storefronts/legal-texts/{text}/approve | admin.storefronts.legal-texts.approve',
        'POST admin/storefronts/legal-texts/{text}/discard | admin.storefronts.legal-texts.discard',
        'POST admin/storefronts/legal-texts/{text}/edit | admin.storefronts.legal-texts.edit',
        'POST admin/storefronts/legal-texts/{text}/new-version | admin.storefronts.legal-texts.new-version',
        'POST admin/storefronts/legal-texts/{text}/publish | admin.storefronts.legal-texts.publish',
        'POST admin/storefronts/legal-texts/{text}/revoke | admin.storefronts.legal-texts.revoke',

        // Marketing consent and unsubscribe (A2-13, I1-08): the signed link every marketing message has to carry.
        'GET marketing/unsubscribe/{token} | marketing.unsubscribe',

        // Product identity per country (B-04..B-07): one product copied into another market's catalogue.
        'POST admin/products/copy-to-country/{id} | admin.products.copy-to-country',

        // Whether one product may reach one country, and on whose authority (H1-03, H1-04). The platform refuses a destination it has no
        // record for, so this is where a refusal becomes a permission: two addresses, one to read a product's permissions and one to grant
        // or withdraw them, both behind admin + actch:admin_panel + module:catalog and both audited. They serve the Saudi platform nothing
        // new -- a domestic destination needs no record at all -- and the screen is empty until somebody grants something.
        'GET admin/shipping/destination-permissions | admin.shipping.destination-permissions.index',
        'POST admin/shipping/destination-permissions | admin.shipping.destination-permissions.record',

        // What the app tells a customer about customs duty before they pay (H1-09), beside the fulfillment notice main already serves in
        // this group and read the same way. It answers null for every order that crosses no border, which is every order this platform
        // has taken, and the address it reads has to be the caller's own.
        'GET api/v1/cart/customs-duty | (none)',

        // Why a shipment of one of the seller's own orders has not left (H1-04, H1-06). The reason was written by the dispatcher and read
        // only by the admin panel, so a seller watching an order that never moved could not find out why. Behind the same
        // seller_can:orders.view,orders.manage as the order's own details, and an order that is not theirs answers 404 like every other
        // order endpoint here. It exposes the reason and nothing else: not the carrier, not the provider's error text.
        'GET api/v3/seller/orders/{id}/shipment-holds | (none)',

        // The seller app's own three additions: the barcode sheet its till prints, the screen that tells a signed-in seller who they are,
        // and the one way to give a credential back. Signing out had no address at all on main: the app forgot its own copy of the token
        // and the token itself stayed live on the row, with no expiry, so a sold or stolen phone kept a working credential (K-12).
        'GET api/v3/seller/products/barcode/sheet | seller-barcode-sheet',
        'GET api/v3/seller/seller-center/security/me | (none)',
        'POST api/v3/seller/auth/logout | (none)',

        // A blog draft is previewed through a route of its own rather than by publishing it.
        'GET admin/blog/draft-preview/{id} | admin.blog.draft-preview',

        // The same address as main's, hardened from GET to POST: see DECLARED_DEPARTURES, where its GET is accounted for.
        'POST vendor/business-settings/withdraw/close/{id} | vendor.business-settings.withdraw.close',

        // The visitor's choice of market (A1-06): the one address that writes it, and a POST because it changes what the site serves this
        // visitor and a GET could be done to them by a link, an image or a prefetch. It answers 404 while storefronts are off or while the
        // platform serves one market, so the Saudi platform of today serves nothing new at it.
        'POST storefront | storefront.choose',

        // The marketing name, logos and favicon one storefront shows (F-13). It is in the theme group, which already resolves a storefront,
        // because /api/v1/config deliberately resolves none -- an app asks it before it knows which storefront it is in -- so a brand asked
        // for there would always be the default storefront's. It answers every field resolved, so a client never merges two payloads, and it
        // serves the Saudi platform the platform-wide identity it already serves: the default storefront has no brand record of its own.
        'GET api/v1/theme/brand | api.v1.theme.brand',

        // Each market's own notification accounts and the test send that proves one works (I1-01). The one shared mail configuration main
        // serves is untouched and still at admin/third-party/mail; these three sit behind the same module:3rd_party_setup permission, and
        // the markets any given employee is offered are narrowed again by their country scope. They serve the Saudi platform nothing new:
        // the screen says plainly that accounts per market are switched off, and a save is refused while they are, so until
        // GCC_NOTIFICATION_PROFILES is on the only mail account on the platform is the one it has today.
        'GET admin/notification-accounts | admin.notification-accounts.index',
        'POST admin/notification-accounts/mail | admin.notification-accounts.mail.save',
        'POST admin/notification-accounts/mail/test | admin.notification-accounts.mail.test',

        // A company's own notification address (D2-09), beside the platform-wide one main already serves. The token in the path names one
        // gateway account of one company and the delivery is authenticated with that account's own secret, so the address answers nothing
        // until a company has an account with a token: while per-company accounts are off it resolves no account and refuses every
        // request, and the Saudi platform keeps taking every notification at the address it takes them at today.
        'POST api/payments/account/{account}/webhook | payments.account.webhook',

        // Which market may sell into which country, and on whose authority (H1-05, C-16). The pair screen reads and the POST records one
        // decision; both sit behind admin + actch:admin_panel and both are audited, like the destination-permission pair above them. They
        // serve the Saudi platform nothing: with cross-border shipping off every sale stays inside its own country and the screen says so,
        // and a market selling domestically needs no pair recorded at all.
        'GET admin/shipping/cross-border-pairs | admin.shipping.cross-border-pairs.index',
        'POST admin/shipping/cross-border-pairs | admin.shipping.cross-border-pairs.record',

        // Copying a theme's own rows into another market (F-04). One address, POST only, because it is an action and not a screen: the
        // theme builder already has the screen. It is inert on a single-market platform, which has no other market to copy into.
        'POST admin/theme/copy-to-market | admin.theme.copy-to-market',

        // Which markets the signed-in seller may put a product in (B-09). The seller app's market picker could not be built because nothing
        // told it what the choice was: the grant lived in a table and a seller learnt of it by being refused a create. It is a fact about
        // the caller's own account, behind the same seller_can list as the Seller Center overview beside it, and it writes nothing. On the
        // Saudi platform it answers the one default market and says the picker is not needed, because the market grants ship off.
        'GET api/v3/seller/seller-center/markets | (none)',
    ];

    /**
     * The three addresses main serves that this chain does not, and why each one is allowed to go. Everything else main serves is still
     * served, which is what test_no_route_of_the_saudi_platform_has_disappeared asks.
     *
     * @var array<string, string>
     */
    private const DECLARED_DEPARTURES = [
        // Removed outright by the security fix 8ec87fbd: it loaded an order by its id alone, with no seller in the lookup, so any vendor
        // could read any order. Nothing links to it, nothing calls route('vendor.pos.order-details'), POSOrder::ORDER_DETAILS is referenced
        // nowhere, and the page it fed crashed for every order. It is pinned as removed by
        // VendorPosOwnershipTest::test_the_point_of_sale_has_no_order_details_route_that_reads_an_order_by_its_id.
        'POST vendor/pos/order-details/{id} | vendor.pos.order-details' => 'removed: it read any order by its id (8ec87fbd)',

        // The same address, now a POST: closing a withdrawal request moves money and a state-changing route is not a GET. The POST is in
        // DECLARED_ADDITIONS, so the pair is visible as one change rather than as a loss and an unexplained addition.
        'GET vendor/business-settings/withdraw/close/{id} | vendor.business-settings.withdraw.close' => 'hardened from GET to POST',

        // The same download, now named and privatised: the URI gained an optional {fileName?} segment, so the old URI still matches, and it
        // gained the name customer-api.orders.digital-file, which nothing on main could call because on main it had none. The replacement is
        // in DECLARED_ADDITIONS.
        'GET api/v1/customer/order/digital-product-download/{id} | (none)' => 'replaced by the named, privatised route with an optional file name',
    ];
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
        $declared = array_keys(self::DECLARED_DEPARTURES);
        sort($declared);

        $this->assertSame(
            $declared,
            $gone,
            "a route main serves is not served here and is not one of the three declared departures. Each line is 'METHOD uri | name': a URI change breaks a bookmark, a name change breaks every route() call that names it. "
            . 'If it really should go, say why in DECLARED_DEPARTURES; three lines of reason are cheaper than a bookmark nobody can explain.',
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
            count($added) - 1 - count(array_intersect($added, self::DECLARED_ADDITIONS)),
            $platformAdmin,
            'everything added beyond the storefront list and the routes written out in DECLARED_ADDITIONS has to be in the platform admin namespace',
        );
        $this->assertSame(
            [],
            array_values(array_diff(self::DECLARED_ADDITIONS, $added)),
            'a route is declared here that this tree does not serve: the list states what the chain adds, so a line of it that adds nothing is either a typo or a route that has been lost',
        );
    }

    public function test_the_platform_admin_api_shares_no_address_with_the_saudi_platform(): void
    {
        $onMain = array_values(array_filter($this->routesOnMain(), fn (string $row): bool => $this->isPlatformAdmin(row: $row)));

        $this->assertSame([], $onMain, 'main already serves something under the platform admin prefix, so it is not a namespace of its own');
    }

    private function isDeclared(string $row): bool
    {
        return $row === self::STOREFRONT_LIST
            || in_array($row, self::DECLARED_ADDITIONS, true)
            || $this->isPlatformAdmin(row: $row);
    }

    private function isPlatformAdmin(string $row): bool
    {
        [$method, $rest] = explode(' ', $row, 2);
        $uri = explode(' | ', $rest, 2)[0];

        return $method !== '' && str_starts_with($uri, self::PLATFORM_ADMIN_PREFIX);
    }
}
