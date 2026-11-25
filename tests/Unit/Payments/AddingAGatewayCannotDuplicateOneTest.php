<?php

namespace Tests\Unit\Payments;

use App\Services\Payments\PaymentGatewayCatalog;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * D2-03, last criterion: adding a gateway -- a BNPL one or any other -- cannot create a second KEY or a second CONTROLLER for a gateway
 * that already exists.
 *
 * The KEY half is guarded already, in depth, by `GatewayRegistryConsistencyTest` and `PaymentGatewayCatalogTest`: no key is declared twice
 * in any of the six registries, no two keys share one start route, only one file declares what a gateway is called, and an alias has to
 * name a gateway that exists and must not be offered as one. Those tests read the registries from their own source files, so a key added
 * tomorrow to one of them and forgotten in another fails a named test, which is exactly the guard this criterion asks for.
 *
 * The CONTROLLER half had nothing. A gateway reaches its controller through a registered route, and what no test said was that each
 * gateway's start route reaches exactly ONE controller action and that no controller action is the start of two gateways. Both are ways to
 * end up with two implementations of one gateway, which is the shape of the defect this criterion names: a BNPL add-on that ships its own
 * copy of a gateway the platform already has, taking payments through code nobody is maintaining and reconciling against the wrong
 * account.
 *
 * It is a REGISTRY guard and nothing here needs a BNPL gateway to exist. That is the point: `D2-03`'s other three criteria have no code to
 * test and cannot be closed until a provider is approved, and this one is about what must be true BEFORE one is added.
 *
 * Deliberately NOT asserted: that every controller under `app/Http/Controllers/Payment_Methods` is reachable. One of them is reached under
 * an unconventional path (`payment/bkash/make-payment`) and the registry tests already pin that as a known limit; adding a second,
 * differently-shaped opinion about it here would make a change to either one fail two files for one reason.
 *
 * TEETH, by actually adding a gateway in the tree and taking it out again -- which is the only honest way to test a guard against adding
 * one. A new `tabby` key routed to the EXACT action an existing gateway starts at (`PaymobController@credit`) turns 1 red and names both
 * gateways; the same key routed OUTSIDE the catalogue with its own controller turns 1 red; and declaring `my_fatoorah` a gateway of its own
 * rather than an alias turns 2 red. One mutation came back inert and is worth knowing: a new key routed to a DIFFERENT action of an
 * existing gateway's controller (`PaymobController@index` beside `@credit`) is not caught and should not be -- a controller may serve more
 * than one gateway, and it is the start ACTION that cannot be shared.
 *
 * 4 tests / 36 assertions, green on SQLite and MariaDB 10.11.14, with the whole of `tests/Unit/Payments` (45 tests / 220 assertions) green
 * beside it. It reads routes and a catalogue and touches no database, so the engine makes no difference to it.
 */
class AddingAGatewayCannotDuplicateOneTest extends TestCase
{
    /**
     * Each gateway key's start path mapped to the controller action the application routes it to.
     *
     * @return array<string, string> gateway key => "Controller@action", for the keys whose path is registered at all
     */
    private function controllerOfEachGateway(): array
    {
        $registered = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if ($action !== 'Closure') {
                $registered[$route->uri()][] = $action;
            }
        }

        $controllers = [];

        foreach (PaymentGatewayCatalog::startRoutes() as $key => $path) {
            if (isset($registered[$path])) {
                $controllers[$key] = $registered[$path];
            }
        }

        return $controllers;
    }

    public function test_each_gateways_start_path_reaches_exactly_one_controller_action(): void
    {
        foreach ($this->controllerOfEachGateway() as $key => $actions) {
            $this->assertCount(
                1,
                array_unique($actions),
                $key . "'s payment page is routed to more than one controller action (" . implode(', ', array_unique($actions))
                . '), so which code takes the money depends on route order. One gateway, one implementation.',
            );
        }
    }

    public function test_no_controller_action_is_the_start_of_two_different_gateways(): void
    {
        $gatewaysOfAction = [];

        foreach ($this->controllerOfEachGateway() as $key => $actions) {
            // An alias is not a second gateway: `my_fatoorah` keeps its own start path so links already in customers' inboxes resolve,
            // and both reach the same controller on purpose. The canonical key is what is counted.
            $canonical = PaymentGatewayCatalog::ALIASES[$key] ?? $key;
            $gatewaysOfAction[$actions[0]][$canonical] = true;
        }

        foreach ($gatewaysOfAction as $action => $gateways) {
            $this->assertCount(
                1,
                $gateways,
                $action . ' is the payment start of more than one gateway (' . implode(', ', array_keys($gateways))
                . '). Either two gateways are one gateway under two names, in which case one of them is an alias and belongs in '
                . PaymentGatewayCatalog::class . '::ALIASES, or one of them is a duplicate implementation.',
            );
        }
    }

    /** An alias reaches the same controller as the gateway it aliases -- the property that makes it an alias and not a second gateway. */
    public function test_an_alias_reaches_the_same_controller_as_the_gateway_it_aliases(): void
    {
        $controllers = $this->controllerOfEachGateway();

        $this->assertNotSame([], PaymentGatewayCatalog::ALIASES, 'the catalogue is expected to declare at least one alias');

        foreach (PaymentGatewayCatalog::ALIASES as $alias => $gateway) {
            $this->assertArrayHasKey($alias, $controllers, $alias . ' is declared as an alias and its start path is not registered, so an older link 404s.');
            $this->assertArrayHasKey($gateway, $controllers, $gateway . ' is aliased by ' . $alias . ' and its own start path is not registered.');
            $this->assertSame(
                $controllers[$gateway][0],
                $controllers[$alias][0],
                $alias . ' is declared as an older spelling of ' . $gateway . ' and reaches a DIFFERENT controller, which makes it a second '
                . 'implementation of one gateway rather than an alias of it.',
            );
        }
    }

    /**
     * A payment start path the catalogue does not know must at least reach a controller a catalogued gateway already reaches.
     *
     * The first version of this asserted that NO conventional start path is registered outside the catalogue, and it failed on a real and
     * documented case: `payment/fatoorah/pay` is registered beside `payment/myfatoorah/pay` and both reach `MyFatoorahController::pay`,
     * kept because it is the path the Payment trait has always generated, so an old link, a queued job or an installed app build still
     * works. That is a PATH alias, not a second implementation, and the criterion is about implementations -- so the assertion was made
     * sharper rather than given an exception. A path reaching a controller NO catalogued gateway reaches is the real risk: a gateway
     * routed from outside the catalogue is invisible to every registry check, which is how a second copy of an existing gateway gets in.
     *
     * Only the conventional shape is judged (`payment/<gateway>/pay`), the same limit the registry tests state and for the same reason:
     * one of today's gateways is registered under another shape and is pinned there, not here.
     */
    public function test_a_payment_start_path_outside_the_catalogue_reaches_no_controller_of_its_own(): void
    {
        $catalogued = array_values(PaymentGatewayCatalog::startRoutes());
        $knownControllers = [];

        foreach ($this->controllerOfEachGateway() as $actions) {
            $knownControllers[$actions[0]] = true;
        }

        $strangers = [];

        foreach (Route::getRoutes() as $route) {
            $path = $route->uri();
            $action = $route->getActionName();

            if (!str_starts_with($path, 'payment/') || !str_ends_with($path, '/pay') || in_array($path, $catalogued, true)) {
                continue;
            }

            if ($action !== 'Closure' && !isset($knownControllers[$action])) {
                $strangers[$path . ' -> ' . $action] = true;
            }
        }

        $this->assertSame([], array_keys($strangers), implode("\n", [
            'A payment start path is registered that ' . PaymentGatewayCatalog::class . '::startRoutes() does not know, and it reaches a '
            . 'controller no catalogued gateway reaches:',
            ...array_keys($strangers),
            'A gateway routed from outside the catalogue is invisible to every registry check. Put its key in the catalogue, or, if it is '
            . 'an older path of a gateway that is already there, point it at that gateway\'s controller.',
        ]));
    }
}
