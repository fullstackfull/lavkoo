<?php

namespace App\Http\Middleware;

use App\Services\Staff\StaffCostRoutes;
use App\Services\Staff\StaffCountryScope;
use App\Services\Staff\StaffIntegrationRoutes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Narrows the screens of slice 6 to the countries of the signed-in employee (GCC-14c; G-08, G-09): the sales channels, the fulfilment
 * providers, the payment-gateway monitor and the operational costs.
 *
 * It is registered by the controllers themselves, so it runs after the sign-in check and the module permission of the route -- and, because
 * these are `web` routes, after `SubstituteBindings`, which is why the registries read a bound MODEL's key out of the route and not digits.
 *
 * It binds the employee's countries to the request, which narrows what each screen lists: the channel listings and the channel orders by the
 * market their ACCOUNT sells in (`StaffCountryChannelAccountScope`), the listing drafts, the cost layers and the return lines by their
 * product, and the warehouses by their own market (`StaffCountryMarketRowScope`). It then narrows the rows a listing screen asked for through
 * the ORDER they belong to -- a provider shipment -- and only those, so that an action which CHANGES one still runs on the real data.
 *
 * For the rule of the action it refuses a request that names a channel listing, a draft, a channel order, an account, a warehouse, a product,
 * an order, a routing rule, a cost layer, a return line or a sale outside those countries, and it refuses the screens that cannot be told
 * apart by market at all. A route with NO rule is refused too: a new action of a covered controller is unreachable to a restricted employee
 * until somebody decides what market it belongs to.
 *
 * While the switch is off, or for an employee who is not restricted, it does nothing but let the request through.
 */
class ScopeIntegrationsToStaffCountries
{
    public function __construct(private readonly StaffCountryScope $scope)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->scope->bind(request: $request, admin: auth('admin')->user()) === null) {
            return $next($request);
        }

        $route = $request->route();
        $controller = (string) $route->getControllerClass();
        $method = $route->getActionMethod();
        $cost = StaffCostRoutes::ruleOf(controller: $controller, method: $method);
        $rule = $cost ?? StaffIntegrationRoutes::ruleOf(controller: $controller, method: $method);

        abort_if($rule === null || !$this->covers(request: $request, rule: $rule, isCost: $cost !== null), 404);

        $this->scope->narrowTo(request: $request, subjects: StaffIntegrationRoutes::subjectsOf(rule: $rule));

        return $next($request);
    }

    /** @param list<string> $rule */
    private function covers(Request $request, array $rule, bool $isCost): bool
    {
        if ($rule === []) {
            return false;
        }

        foreach ($rule as $part) {
            $claim = $isCost
                ? StaffCostRoutes::claimOf(request: $request, part: $part)
                : StaffIntegrationRoutes::claimOf(request: $request, part: $part);

            if ($claim === null || !$this->answers(claim: $claim)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the employee may work with everything one part of a rule named. Each kind is answered by the method of `StaffCountryScope`
     * that owns that question, so the rule of which market a thing belongs to stays in one place per thing.
     *
     * @param array{products?: list<int>, orders?: list<int>, locations?: list<int>, accounts?: list<int>, countries?: list<string>, default_market?: bool} $claim
     */
    private function answers(array $claim): bool
    {
        foreach ($claim['countries'] ?? [] as $country) {
            if (!$this->scope->allows(country: $country)) {
                return false;
            }
        }

        return $this->scope->coversEveryProduct(productIds: $claim['products'] ?? [])
            && $this->scope->coversEveryOrder(orderIds: $claim['orders'] ?? [])
            && $this->scope->coversEveryInventoryLocation(locationIds: $claim['locations'] ?? [])
            && $this->scope->coversEveryChannelAccount(accountIds: $claim['accounts'] ?? [])
            && (!($claim['default_market'] ?? false) || $this->scope->coversTheDefaultMarket());
    }
}
